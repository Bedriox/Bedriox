<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use SplQueue;
use Throwable;

/** Owns the RakNet application-payload to current Bedrock login composition boundary. */
final class BedrockLoginChannel
{
    private const int COMPRESSION_THRESHOLD = 256;

    /** @var SplQueue<OutgoingLoginPayload> */
    private SplQueue $outgoing;
    private int $outgoingBytes = 0;
    private bool $compressionNegotiated = false;
    private ?BedrockEncryptor $encryptor = null;
    private ?BedrockDecryptor $decryptor = null;
    private ?LoginChannelReady $ready = null;
    private ?string $failureDetail = null;

    public function __construct(private readonly LoginSession $session, private readonly LoginChannelLimits $limits = new LoginChannelLimits())
    {
        $this->outgoing = new SplQueue();
    }

    public function __destruct()
    {
        $this->close();
    }

    public function accept(ConnectedPayloadEvent $event): bool
    {
        if (!$this->session->preflight()) {
            return $this->fail($this->session->failure() ?? LoginFailureCode::CLOSED_BY_SERVER);
        }
        if ($event->reliability !== Reliability::ReliableOrdered || $event->orderingChannel !== 0) {
            return $this->fail(LoginFailureCode::INPUT_LIMIT);
        }
        $encrypted = $this->decryptor !== null;
        try {
            $envelope = $encrypted ? $this->decryptor->decryptEnvelope($event->payload) : $event->payload;
            $batch = BedrockBatchCodec::decode(
                $envelope,
                $this->compressionNegotiated ? CompressionMode::NegotiatedZlib : CompressionMode::Uncompressed,
                $this->limits->batch,
                self::COMPRESSION_THRESHOLD,
            );
            if ($batch->packets === []) {
                return $this->fail(LoginFailureCode::INPUT_LIMIT);
            }
            foreach ($batch->packets as $frame) {
                if ($frame->header->senderSubclientId !== 0 || $frame->header->targetSubclientId !== 0) {
                    return $this->fail(LoginFailureCode::INPUT_LIMIT);
                }
                $packet = BedrockPacketCodec::decode($frame->header->packetId, $frame->payload);
                if (!$this->session->enqueue(new LoginInput($packet, strlen($frame->payload) + 1, $encrypted))) {
                    return $this->fail($this->session->failure() ?? LoginFailureCode::INPUT_LIMIT);
                }
            }
            $this->session->tick();
            return $this->consumeEffects();
        } catch (Throwable $exception) {
            $this->failureDetail = 'input:' . $exception::class;
            return $this->fail(LoginFailureCode::INPUT_LIMIT);
        }
    }

    public function tick(): bool
    {
        $this->session->tick();
        return $this->consumeEffects();
    }

    /** @return list<OutgoingLoginPayload> */
    public function drainOutgoing(): array
    {
        $values = [];
        while (!$this->outgoing->isEmpty()) {
            $value = $this->outgoing->dequeue();
            $this->outgoingBytes -= strlen($value->payload);
            $values[] = $value;
        }
        return $values;
    }

    public function takeReady(): ?LoginChannelReady
    {
        $ready = $this->ready;
        $this->ready = null;
        return $ready;
    }

    public function state(): LoginState
    {
        return $this->session->state();
    }

    public function failure(): ?LoginFailureCode
    {
        return $this->session->failure();
    }

    public function observedProtocolVersion(): ?int
    {
        return $this->session->observedProtocolVersion();
    }

    public function failureDetail(): ?string
    {
        return $this->failureDetail ?? $this->session->failureDetail();
    }

    public function securityPosture(): LoginSecurityPosture
    {
        return $this->session->securityPosture();
    }

    /** Idempotently disconnect this pre-login channel and discard all channel-owned state. */
    public function close(LoginFailureCode $reason = LoginFailureCode::CLOSED_BY_SERVER): void
    {
        $this->fail($reason);
    }

    private function consumeEffects(): bool
    {
        foreach ($this->session->drainEffects() as $effect) {
            try {
                if ($effect instanceof SendPacketEffect) {
                    if (!$this->encode($effect)) {
                        return false;
                    }
                } elseif ($effect instanceof EnableEncryptionEffect) {
                    if ($this->encryptor !== null || $this->decryptor !== null) {
                        return $this->fail(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
                    }
                    $this->encryptor = new BedrockEncryptor($effect->sessionKey);
                    $this->decryptor = new BedrockDecryptor($effect->sessionKey);
                } elseif ($effect instanceof LoginReadyEffect) {
                    $encryptor = $this->encryptor;
                    $decryptor = $this->decryptor;
                    if ($encryptor === null || $decryptor === null) {
                        return $this->fail(LoginFailureCode::ENCRYPTION_STATE);
                    }
                    $protocolVersion = $this->session->observedProtocolVersion();
                    if ($protocolVersion === null) {
                        return $this->fail(LoginFailureCode::UNSUPPORTED_PROTOCOL);
                    }
                    $this->ready = new LoginChannelReady($effect->login, $encryptor, $decryptor, $protocolVersion);
                    $this->encryptor = null;
                    $this->decryptor = null;
                }
            } catch (Throwable $exception) {
                $this->failureDetail = 'effect:' . $exception::class;
                return $this->fail(LoginFailureCode::CRYPTOGRAPHIC_FAILURE);
            }
        }
        if ($this->session->state() === LoginState::CLOSED) {
            return $this->fail($this->session->failure() ?? LoginFailureCode::CLOSED_BY_SERVER);
        }
        return true;
    }

    private function encode(SendPacketEffect $effect): bool
    {
        if ($effect->encrypted !== ($this->encryptor !== null)) {
            return $this->fail(LoginFailureCode::ENCRYPTION_STATE);
        }
        $packet = $effect->packet;
        $envelope = BedrockBatchCodec::encode(new BedrockBatch([
            new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet)),
        ], $this->compressionNegotiated ? CompressionMode::NegotiatedZlib : CompressionMode::Uncompressed, self::COMPRESSION_THRESHOLD), $this->limits->batch);
        if ($effect->encrypted) {
            $encryptor = $this->encryptor;
            if ($encryptor === null) {
                return $this->fail(LoginFailureCode::ENCRYPTION_STATE);
            }
            $envelope = $encryptor->encryptEnvelope($envelope);
        }
        if ($this->outgoing->count() >= $this->limits->maximumQueuedPayloads
            || strlen($envelope) > $this->limits->maximumQueuedPayloadBytes - $this->outgoingBytes) {
            return $this->fail(LoginFailureCode::EFFECT_LIMIT);
        }
        $this->outgoing->enqueue(new OutgoingLoginPayload($envelope));
        $this->outgoingBytes += strlen($envelope);
        if ($packet instanceof NetworkSettingsPacket) {
            $this->compressionNegotiated = true;
        }
        return true;
    }

    private function fail(LoginFailureCode $code): bool
    {
        $this->session->close($code);
        $this->outgoing = new SplQueue();
        $this->outgoingBytes = 0;
        $this->ready = null;
        $this->closeCrypto();
        return false;
    }

    private function closeCrypto(): void
    {
        $this->encryptor?->close();
        $this->decryptor?->close();
        $this->encryptor = null;
        $this->decryptor = null;
    }
}
