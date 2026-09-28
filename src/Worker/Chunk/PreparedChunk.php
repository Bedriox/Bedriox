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

use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

/** A compressed, session-independent LevelChunk batch ready for revision validation and encryption. */
final readonly class PreparedChunk
{
    public function __construct(
        public string $cacheKey,
        public ChunkPosition $position,
        public int $revision,
        public int $protocolVersion,
        public string $clearEnvelope,
    ) {
        if ($cacheKey === '' || $revision < 0 || $protocolVersion < 1
            || $clearEnvelope === '' || ord($clearEnvelope[0]) !== BedrockBatchCodec::GAME_PACKET_MARKER) {
            throw new InvalidArgumentException('Prepared chunk is invalid.');
        }
    }

    public function bytes(): int
    {
        return strlen($this->clearEnvelope);
    }
}
