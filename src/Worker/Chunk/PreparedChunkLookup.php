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

final readonly class PreparedChunkLookup
{
    public function __construct(
        public PreparedChunkAvailability $availability,
        public ?PreparedChunk $chunk = null,
        public int $submittedBytes = 0,
    ) {
        if (($availability === PreparedChunkAvailability::READY) !== ($chunk instanceof PreparedChunk)
            || $submittedBytes < 0) {
            throw new InvalidArgumentException('Prepared chunk lookup result is inconsistent.');
        }
    }
}
