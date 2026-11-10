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
use Bedriox\Protocol\Batch\PacketBatchCodec;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\ResourcePackChunkDataPacket;
use Bedriox\Server\Transport\InboundPayload;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\Worker\Network\OrderedOutboundCompressionQueue;
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
    private int $nextReceiptId = 1;
    private ?int $pendingPackReceiptId = null;
    private readonly ?OrderedOutboundCompressionQueue $resourcePackCompression;
    private bool $pendingPackCompression = false;

    public function __construct(
        private readonly LoginSession $session,
        private readonly LoginChannelLimits $limits = new LoginChannelLimits(),
        ?CompressionWorkerDispatcher $compressionWorkers = null,
        int $compressionTaskTypeId = 1,
    ) {
        $this->outgoing = new SplQueue();
        $this->resourcePackCompression = $compressionWorkers === null ? null : new OrderedOutboundCompressionQueue(
            $compressionWorkers,
            $compressionTaskTypeId,
            1,
            CompressionMode::NegotiatedZlib,
            NetworkCompressionPolicy::THRESHOLD_BYTES,
            $this->limits->batch,
            maximumOutstandingTasks: 1,
            maximumOutstandingBytes: 2_097_152,
            maximumResultBytes: $this->limits->batch->maximumInputBytes,
            minimumOffloadBytes: 1,
        );
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

    public function acknowledge(int $receiptId): bool
    {
        if ($receiptId < 1 || $receiptId !== $this->pendingPackReceiptId) {
            return true;
        }
        $this->pendingPackReceiptId = null;
        $this->session->acknowledgePackChunk();
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
        if (!$this->releaseCompressedPack()) {
            return false;
        }
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
        return $this->releaseCompressedPack();
    }

    private function encode(SendPacketEffect $effect): bool
    {
        if ($effect->encrypted) {
            return $this->fail(LoginFailureCode::ENCRYPTION_STATE);
        }
        $packet = $effect->packet;
        if ($packet instanceof ResourcePackChunkDataPacket && $this->resourcePackCompression !== null) {
            if ($this->pendingPackCompression || $this->pendingPackReceiptId !== null) {
                return $this->fail(LoginFailureCode::EFFECT_LIMIT);
            }
            $clearBatch = PacketBatchCodec::encode([
                new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet)),
            ], $this->limits->batch);
            $submission = $this->resourcePackCompression->enqueue($clearBatch);
            if (!$submission->isAccepted()) {
                return $this->fail(LoginFailureCode::EFFECT_LIMIT);
            }
            $this->pendingPackCompression = true;

            return $this->releaseCompressedPack();
        }
        $envelope = BedrockBatchCodec::encode(new BedrockBatch([
            new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet)),
        ], $this->compressionNegotiated ? CompressionMode::NegotiatedZlib : CompressionMode::Uncompressed, NetworkCompressionPolicy::THRESHOLD_BYTES), $this->limits->batch);
        if ($this->outgoing->count() >= $this->limits->maximumQueuedPayloads
            || strlen($envelope) > $this->limits->maximumQueuedPayloadBytes - $this->outgoingBytes) {
            return $this->fail(LoginFailureCode::EFFECT_LIMIT);
        }
        $receiptId = null;
        if ($packet instanceof ResourcePackChunkDataPacket) {
            if ($this->pendingPackReceiptId !== null) {
                return $this->fail(LoginFailureCode::EFFECT_LIMIT);
            }
            $receiptId = $this->nextReceiptId++;
            if ($this->nextReceiptId > 0x7fff_ffff) {
                $this->nextReceiptId = 1;
            }
            $this->pendingPackReceiptId = $receiptId;
        }
        $this->outgoing->enqueue(new OutgoingLoginPayload($envelope, $receiptId));
        $this->outgoingBytes += strlen($envelope);
        if ($packet instanceof NetworkSettingsPacket) {
            $this->compressionNegotiated = true;
        }
        return true;
    }

    private function fail(LoginFailureCode $code): bool
    {
        $this->resourcePackCompression?->close();
        $this->session->close($code);
        $this->outgoing = new SplQueue();
        $this->outgoingBytes = 0;
        $this->ready = null;
        $this->pendingPackReceiptId = null;
        $this->pendingPackCompression = false;
        return false;
    }

    private function releaseCompressedPack(): bool
    {
        if ($this->resourcePackCompression === null || !$this->pendingPackCompression) {
            return true;
        }
        $ready = $this->resourcePackCompression->takeReady(1);
        if ($ready === []) {
            return true;
        }
        $batch = $ready[0];
        if ($batch->payload === null || $batch->failureCode !== null
            || $this->outgoing->count() >= $this->limits->maximumQueuedPayloads
            || strlen($batch->payload) > $this->limits->maximumQueuedPayloadBytes - $this->outgoingBytes) {
            return $this->fail(LoginFailureCode::EFFECT_LIMIT);
        }
        $receiptId = $this->nextReceiptId++;
        if ($this->nextReceiptId > 0x7fff_ffff) {
            $this->nextReceiptId = 1;
        }
        $this->pendingPackReceiptId = $receiptId;
        $this->pendingPackCompression = false;
        $this->outgoing->enqueue(new OutgoingLoginPayload($batch->payload, $receiptId));
        $this->outgoingBytes += strlen($batch->payload);

        return true;
    }
}
