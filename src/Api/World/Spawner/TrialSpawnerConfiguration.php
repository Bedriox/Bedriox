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

/** Bounded encounter rules for a trial spawner. */
final readonly class TrialSpawnerConfiguration
{
    public function __construct(
        public string $entityIdentifier,
        public int $warmupTicks = 40,
        public int $spawnIntervalTicks = 40,
        public int $baseTotalMobs = 6,
        public int $additionalMobsPerPlayer = 2,
        public int $maximumSimultaneousMobs = 3,
        public int $cooldownTicks = 36_000,
        public int $requiredPlayerRange = 14,
    ) {
        if (preg_match('~^[a-z0-9_.-]+:[a-z0-9_./-]+$~D', $entityIdentifier) !== 1
            || strlen($entityIdentifier) > 128
            || $warmupTicks < 1 || $warmupTicks > 1_200
            || $spawnIntervalTicks < 1 || $spawnIntervalTicks > 1_200
            || $baseTotalMobs < 1 || $baseTotalMobs > 64
            || $additionalMobsPerPlayer < 0 || $additionalMobsPerPlayer > 16
            || $maximumSimultaneousMobs < 1 || $maximumSimultaneousMobs > 16
            || $cooldownTicks < 20 || $cooldownTicks > 1_728_000
            || $requiredPlayerRange < 1 || $requiredPlayerRange > 64) {
            throw new InvalidArgumentException('Trial-spawner configuration is outside its supported bounds.');
        }
    }

    public function totalMobsForParticipants(int $participants): int
    {
        if ($participants < 1 || $participants > 64) {
            throw new InvalidArgumentException('Trial-spawner participant count is outside its supported bounds.');
        }

        return min(256, $this->baseTotalMobs + (($participants - 1) * $this->additionalMobsPerPlayer));
    }
}
