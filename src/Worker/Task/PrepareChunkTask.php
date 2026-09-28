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

namespace Bedriox\Server\Worker\Task;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Batch\BatchCompressionCodec;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Batch\PacketBatchCodec;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\ChunkSerializer;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Worker\Chunk\ChunkPreparationRequestCodec;
use Bedriox\Server\Worker\Chunk\ChunkProjectionIdentity;
use Bedriox\Server\Worker\Chunk\ChunkProjectionTransferCodec;
use Bedriox\Server\Worker\Chunk\ChunkTransferException;
use Bedriox\Server\Worker\WorkerTaskHandler;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;

/** Produces a compressed, unencrypted LevelChunk envelope from one immutable canonical snapshot. */
final class PrepareChunkTask implements WorkerTaskHandler
{
    private ?BlockStateRegistry $internalStates = null;
    private ?BlockNetworkTranslator $blocks = null;
    private ?string $registryHash = null;

    public function execute(string $payload): string
    {
        $request = (new ChunkPreparationRequestCodec())->decode($payload);
        $this->initializeProjection();
        if ($request->protocolVersion !== ProtocolVersion::CURRENT
            || $request->serializerVersion !== ChunkProjectionIdentity::SERIALIZER_VERSION
            || $request->compressionThreshold !== ChunkProjectionIdentity::COMPRESSION_THRESHOLD
            || !hash_equals($this->registryHash ?? '', $request->registryHash)) {
            throw new ChunkTransferException('Chunk-preparation wire identity is not admitted.');
        }

        $internalStates = $this->internalStates
            ?? throw new \LogicException('Chunk projection did not initialize its internal registry.');
        $blocks = $this->blocks
            ?? throw new \LogicException('Chunk projection did not initialize its block translator.');
        $column = (new ChunkProjectionTransferCodec())->decodeColumn(
            $request->chunkTransfer,
            $internalStates,
            $blocks,
        );
        $packet = ChunkSerializer::fullColumn($column);
        $frame = new PacketFrame(
            new PacketHeader(BedrockPacketCodec::packetId($packet)),
            BedrockPacketCodec::encode($packet, $request->protocolVersion),
        );
        $limits = new BatchLimits();
        $uncompressed = PacketBatchCodec::encode([$frame], $limits);

        return chr(BedrockBatchCodec::GAME_PACKET_MARKER) . BatchCompressionCodec::encode(
            $uncompressed,
            CompressionMode::NegotiatedZlib,
            $limits,
            $request->compressionThreshold,
        );
    }

    private function initializeProjection(): void
    {
        if ($this->blocks !== null) {
            return;
        }
        $data = BedrockDataSet::bundled();
        $networkStates = $data->blockStateRegistry();
        $this->internalStates = new BlockStateRegistry($networkStates->states());
        $this->blocks = new BlockNetworkTranslator($this->internalStates, $networkStates);
        $this->registryHash = ChunkProjectionIdentity::registryHash($data, $networkStates);
    }
}
