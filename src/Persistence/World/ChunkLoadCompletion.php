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

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Provider\LoadedChunkData;
use InvalidArgumentException;

final readonly class ChunkLoadCompletion
{
    public function __construct(
        public ChunkPosition $position,
        public ?LoadedChunkData $loaded,
        public bool $missing,
        public ?string $failureCode = null,
        public WorldDimension $dimension = WorldDimension::OVERWORLD,
    ) {
        $outcomes = (int) ($loaded !== null) + (int) $missing + (int) ($failureCode !== null);
        if ($outcomes !== 1 || ($failureCode !== null && (strlen($failureCode) > 128
            || preg_match('/^[a-z][a-z0-9_.-]*$/D', $failureCode) !== 1))) {
            throw new InvalidArgumentException('Chunk load completion must contain exactly one valid outcome.');
        }
    }
}
