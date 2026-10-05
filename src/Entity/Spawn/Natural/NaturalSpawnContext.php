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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class NaturalSpawnContext
{
    public function __construct(
        public string $worldName,
        public ChunkPosition $chunk,
        public EntityType $type,
        public EntityCategory $category,
        public Position $position,
        public WorldDimension $dimension,
        public string $biome,
        public NaturalSpawnMedium $medium,
        public int $lightLevel,
        public float $nearestPlayerDistanceSquared,
        public float $worldSpawnDistanceSquared,
        public ?string $supportBlock = null,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $biome) !== 1
            || $lightLevel < 0 || $lightLevel > 15
            || ($supportBlock !== null && preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $supportBlock) !== 1)
            || !is_finite($nearestPlayerDistanceSquared) || $nearestPlayerDistanceSquared < 0.0
            || !is_finite($worldSpawnDistanceSquared) || $worldSpawnDistanceSquared < 0.0) {
            throw new InvalidArgumentException('Natural-spawn context is invalid.');
        }
    }
}
