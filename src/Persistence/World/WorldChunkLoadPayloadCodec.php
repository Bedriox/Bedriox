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

namespace Bedriox\Server\Persistence\World;

use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Worker\Chunk\ChunkTransferCodec;
use RuntimeException;

/** Bounded framing for one terrain snapshot and its optional entity-owner snapshot. */
final class WorldChunkLoadPayloadCodec
{
    private const string MAGIC = "BXWL\x00\x01";
    public const int MAXIMUM_ENCODED_BYTES = ChunkTransferCodec::MAXIMUM_ENCODED_BYTES
        + EntityPersistenceLimits::MAX_DOCUMENT_BYTES
        + 14;

    public function encode(string $chunk, ?string $entities): string
    {
        $entityBytes = $entities ?? '';
        if ($chunk === '' || strlen($chunk) > ChunkTransferCodec::MAXIMUM_ENCODED_BYTES
            || strlen($entityBytes) > EntityPersistenceLimits::MAX_DOCUMENT_BYTES) {
            throw new RuntimeException('World chunk-load payload components are outside their bounds.');
        }

        return self::MAGIC . pack('N2', strlen($chunk), strlen($entityBytes)) . $chunk . $entityBytes;
    }

    /** @return array{chunk: string, entities: ?string} */
    public function decode(string $payload): array
    {
        if (strlen($payload) < 15 || strlen($payload) > self::MAXIMUM_ENCODED_BYTES
            || substr($payload, 0, strlen(self::MAGIC)) !== self::MAGIC) {
            throw new RuntimeException('World chunk-load payload header or size is invalid.');
        }
        $lengths = unpack('Nchunk/Nentities', substr($payload, strlen(self::MAGIC), 8));
        $chunkLength = is_array($lengths) ? ($lengths['chunk'] ?? null) : null;
        $entityLength = is_array($lengths) ? ($lengths['entities'] ?? null) : null;
        $headerLength = strlen(self::MAGIC) + 8;
        if (!is_int($chunkLength) || !is_int($entityLength)
            || $chunkLength < 1 || $chunkLength > ChunkTransferCodec::MAXIMUM_ENCODED_BYTES
            || $entityLength > EntityPersistenceLimits::MAX_DOCUMENT_BYTES
            || $headerLength + $chunkLength + $entityLength !== strlen($payload)) {
            throw new RuntimeException('World chunk-load payload lengths are invalid.');
        }
        $chunk = substr($payload, $headerLength, $chunkLength);
        $entities = $entityLength === 0 ? null : substr($payload, $headerLength + $chunkLength, $entityLength);

        return ['chunk' => $chunk, 'entities' => $entities];
    }
}
