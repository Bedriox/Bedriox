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

namespace Bedriox\Server\Worker\Chunk;

use InvalidArgumentException;

final readonly class ChunkPreparationRequest
{
    public function __construct(
        public int $protocolVersion,
        public int $serializerVersion,
        public int $compressionThreshold,
        public string $registryHash,
        public string $chunkTransfer,
    ) {
        if ($protocolVersion < 1 || $protocolVersion > 0x7fff_ffff
            || $serializerVersion < 1 || $serializerVersion > 65_535
            || $compressionThreshold < 0 || $compressionThreshold > 65_535
            || preg_match('/^[a-f0-9]{64}$/D', $registryHash) !== 1
            || $chunkTransfer === '') {
            throw new InvalidArgumentException('Chunk-preparation request identity is invalid.');
        }
    }
}
