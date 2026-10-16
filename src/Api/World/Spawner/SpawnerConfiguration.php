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

namespace Bedriox\Api\World\Spawner;

use InvalidArgumentException;

/** Bounded, server-authoritative configuration for an ordinary mob spawner. */
final readonly class SpawnerConfiguration
{
    public function __construct(
        public string $entityIdentifier,
        public int $minimumDelayTicks = 200,
        public int $maximumDelayTicks = 800,
        public int $spawnCount = 4,
        public int $maximumNearbyEntities = 6,
        public int $requiredPlayerRange = 16,
        public int $spawnRange = 4,
    ) {
        if (preg_match('~^[a-z0-9_.-]+:[a-z0-9_./-]+$~D', $entityIdentifier) !== 1
            || strlen($entityIdentifier) > 128) {
            throw new InvalidArgumentException('Spawner entity identifier must be canonical and bounded.');
        }
        if ($minimumDelayTicks < 1 || $minimumDelayTicks > 72_000
            || $maximumDelayTicks < $minimumDelayTicks || $maximumDelayTicks > 72_000
            || $spawnCount < 1 || $spawnCount > 16
            || $maximumNearbyEntities < 1 || $maximumNearbyEntities > 128
            || $requiredPlayerRange < 1 || $requiredPlayerRange > 128
            || $spawnRange < 1 || $spawnRange > 16) {
            throw new InvalidArgumentException('Spawner configuration is outside its supported bounds.');
        }
    }
}
