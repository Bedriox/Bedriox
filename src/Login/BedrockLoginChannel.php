<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Login;

use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Server\Transport\InboundPayload;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use SplQueue;
use Throwable;

/** Owns the transport application-payload to current Bedrock login composition boundary. */
final class BedrockLoginChannel
{
    /** @var SplQueue<OutgoingLoginPayload> */
    private SplQueue $outgoing;
    private int $outgoingBytes = 0;
    private bool $compressionNegotiated = false;
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

    public function accept(InboundPayload $event): bool
    {
        if (!$this->session->preflight()) {
            return $this->fail($this->session->failure() ?? LoginFailureCode::CLOSED_BY_SERVER);
        }
        if ($event->reliability !== Reliability::RELIABLE) {
            return $this->fail(LoginFailureCode::INPUT_LIMIT);
        }
        try {
            $batch = BedrockBatchCodec::decode(
                $event->payload,
                $this->compressionNegotiated ? CompressionMode::NegotiatedZlib : CompressionMode::Uncompressed,
                $this->limits->batch,
                NetworkCompressionPolicy::THRESHOLD_BYTES,
            );
            if ($batch->packets === []) {
                return $this->fail(LoginFailureCode::INPUT_LIMIT);
            }
            foreach ($batch->packets as $frame) {
                if ($frame->header->senderSubclientId !== 0 || $frame->header->targetSubclientId !== 0) {
                    return $this->fail(LoginFailureCode::INPUT_LIMIT);
                }
                // A future client may send an optional packet which this server
                // does not yet understand. Framing has already been validated,
                // so skip that frame without losing later login packets.
                if (!BedrockPacketCodec::supportsDecoding($frame->header->packetId)) {
                    continue;
                }
                $packet = BedrockPacketCodec::decode($frame->header->packetId, $frame->payload);
                if (!$this->session->enqueue(new LoginInput($packet, strlen($frame->payload) + 1, false))) {
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
                } elseif ($effect instanceof LoginReadyEffect) {
                    $protocolVersion = $this->session->observedProtocolVersion();
                    if ($protocolVersion === null) {
                        return $this->fail(LoginFailureCode::UNSUPPORTED_PROTOCOL);
                    }
                    $this->ready = new LoginChannelReady($effect->login, $protocolVersion);
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
        if ($effect->encrypted) {
            return $this->fail(LoginFailureCode::ENCRYPTION_STATE);
        }
        $packet = $effect->packet;
        $envelope = BedrockBatchCodec::encode(new BedrockBatch([
            new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet)),
        ], $this->compressionNegotiated ? CompressionMode::NegotiatedZlib : CompressionMode::Uncompressed, NetworkCompressionPolicy::THRESHOLD_BYTES), $this->limits->batch);
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
        return false;
    }
}
