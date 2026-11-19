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
use Bedriox\Server\Worker\Runtime\WorkerBlockStateCatalog;
use Bedriox\Server\Worker\WorkerTaskHandler;

/** Produces a compressed, unencrypted LevelChunk envelope from one immutable canonical snapshot. */
final class PrepareChunkTask implements WorkerTaskHandler
{
    public function __construct(private readonly WorkerBlockStateCatalog $catalog = new WorkerBlockStateCatalog()) {}

    public function execute(string $payload): string
    {
        $request = (new ChunkPreparationRequestCodec())->decode($payload);
        if ($request->protocolVersion !== ProtocolVersion::CURRENT
            || $request->serializerVersion !== ChunkProjectionIdentity::SERIALIZER_VERSION
            || $request->compressionThreshold !== ChunkProjectionIdentity::COMPRESSION_THRESHOLD
            || !hash_equals($this->catalog->registryHash(), $request->registryHash)) {
            throw new ChunkTransferException('Chunk-preparation wire identity is not admitted.');
        }

        $column = (new ChunkProjectionTransferCodec())->decodeColumn(
            $request->chunkTransfer,
            $this->catalog->internalStates(),
            $this->catalog->translator(),
        );
        $packet = ChunkSerializer::fullColumn($column);
        $frame = new PacketFrame(
            new PacketHeader(BedrockPacketCodec::packetId($packet)),
            BedrockPacketCodec::encode($packet, $request->protocolVersion),
        );
        $limits = new BatchLimits();
        $uncompressed = PacketBatchCodec::encode([$frame], $limits);

        return BatchCompressionCodec::encode(
            $uncompressed,
            CompressionMode::NegotiatedZlib,
            $limits,
            $request->compressionThreshold,
        );
    }

}
