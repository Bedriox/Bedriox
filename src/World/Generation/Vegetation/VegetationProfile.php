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

namespace Bedriox\Server\World\Generation\Vegetation;

use InvalidArgumentException;

/** Biome-scale density and silhouette mix for deterministic tree placement. */
final readonly class VegetationProfile
{
    /**
     * @param list<array{TreeArchetype, int}> $archetypes
     */
    public function __construct(
        public string $name,
        public int $densityPermille,
        public int $minimumSpacing,
        public int $clusterScale,
        public int $clearingThreshold,
        public array $archetypes,
    ) {
        if ($name === '' || $densityPermille < 0 || $densityPermille > 1_000
            || $minimumSpacing < 2 || $minimumSpacing > 12
            || $clusterScale < 16 || $clusterScale > 512
            || $clearingThreshold < -32_768 || $clearingThreshold > 32_767 || $archetypes === []) {
            throw new InvalidArgumentException('Vegetation profile values are outside their accepted bounds.');
        }
        foreach ($archetypes as [, $weight]) {
            if ($weight < 1 || $weight > 1_000) {
                throw new InvalidArgumentException('Vegetation archetype weights must be positive and bounded.');
            }
        }
    }

    public function selectArchetype(int $roll): TreeArchetype
    {
        $total = array_sum(array_column($this->archetypes, 1));
        $remaining = $roll % $total;
        foreach ($this->archetypes as [$archetype, $weight]) {
            if ($remaining < $weight) {
                return $archetype;
            }
            $remaining -= $weight;
        }

        return $this->archetypes[0][0];
    }
}
