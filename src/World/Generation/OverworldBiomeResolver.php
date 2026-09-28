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

namespace Bedriox\Server\World\Generation;

use Bedriox\Server\World\Biome;

/** Resolves column biomes from terrain and climate without chunk-local state. */
final class OverworldBiomeResolver
{
    public function resolve(OverworldTerrainSample $sample): Biome
    {
        $height = $sample->surfaceHeight;
        $climate = $sample->climate;
        $temperature = $climate->temperature;
        $humidity = $climate->humidity;
        $variant = abs($climate->detail + $climate->ridge * 3 + $climate->erosion * 5);
        if ($height <= 53) {
            return new Biome(match (true) {
                $temperature < -14_000 => 'minecraft:deep_frozen_ocean',
                $temperature < -5_000 => 'minecraft:deep_cold_ocean',
                $temperature > 14_000 => 'minecraft:deep_warm_ocean',
                $temperature > 5_000 => 'minecraft:deep_lukewarm_ocean',
                default => 'minecraft:deep_ocean',
            });
        }
        if ($height < OverworldTerrainSampler::SEA_LEVEL - 1) {
            return new Biome(match (true) {
                $temperature < -14_000 => 'minecraft:frozen_ocean',
                $temperature < -5_000 => 'minecraft:cold_ocean',
                $temperature > 14_000 => 'minecraft:warm_ocean',
                $temperature > 5_000 => 'minecraft:lukewarm_ocean',
                default => 'minecraft:ocean',
            });
        }
        if ($sample->riverStrength >= 420 && $height <= OverworldTerrainSampler::SEA_LEVEL + 1) {
            return new Biome($temperature < -9_000 ? 'minecraft:frozen_river' : 'minecraft:river');
        }
        if ($height <= OverworldTerrainSampler::SEA_LEVEL && $climate->continentalness < 1_500) {
            return new Biome(match (true) {
                $sample->slope >= 5 => 'minecraft:stone_beach',
                $temperature < -9_000 => 'minecraft:cold_beach',
                default => 'minecraft:beach',
            });
        }
        if ($height >= 128) {
            return new Biome(match (true) {
                $temperature > 3_500 => 'minecraft:stony_peaks',
                $climate->ridge > 4_000 => 'minecraft:jagged_peaks',
                default => 'minecraft:frozen_peaks',
            });
        }
        if ($height >= 99 || ($height >= 86 && $climate->temperature < -9_000)) {
            return new Biome(match (true) {
                $temperature > 4_000 && $humidity > 2_000 => 'minecraft:meadow',
                $humidity > 8_000 => 'minecraft:grove',
                default => 'minecraft:snowy_slopes',
            });
        }
        if ($height >= 83 || $sample->slope >= 8) {
            return new Biome(match (true) {
                $temperature < -11_000 => 'minecraft:ice_mountains',
                $temperature < -3_500 => 'minecraft:cold_taiga_hills',
                $temperature > 9_000 && $humidity < -2_000 => 'minecraft:savanna_plateau',
                $temperature > 7_000 && $humidity > 8_000 => 'minecraft:jungle_hills',
                $humidity > 8_000 => 'minecraft:forest_hills',
                $variant % 29 === 0 => 'minecraft:extreme_hills_plus_trees_mutated',
                $variant % 23 === 0 => 'minecraft:extreme_hills_mutated',
                $sample->slope >= 11 => 'minecraft:extreme_hills_edge',
                $variant % 7 === 0 => 'minecraft:extreme_hills_plus_trees',
                default => 'minecraft:extreme_hills',
            });
        }
        if ($climate->continentalness > 18_000 && $humidity < -16_000 && $variant % 83 === 0) {
            return new Biome($height <= OverworldTerrainSampler::SEA_LEVEL + 2
                ? 'minecraft:mushroom_island_shore'
                : 'minecraft:mushroom_island');
        }
        if ($temperature < -10_500) {
            return new Biome($variant % 13 === 0 ? 'minecraft:ice_plains_spikes' : 'minecraft:ice_plains');
        }
        if ($temperature < -3_500) {
            return new Biome(match (true) {
                $humidity > 13_500 && $sample->slope >= 4 => 'minecraft:redwood_taiga_hills_mutated',
                $humidity > 12_000 && $variant % 5 === 0 => 'minecraft:redwood_taiga_mutated',
                $humidity > 9_000 && $sample->slope >= 4 => 'minecraft:mega_taiga_hills',
                $humidity > 9_000 => 'minecraft:mega_taiga',
                $variant % 17 === 0 => 'minecraft:cold_taiga_mutated',
                $temperature < -7_500 => 'minecraft:cold_taiga',
                $variant % 13 === 0 => 'minecraft:taiga_mutated',
                $variant % 11 === 0 => 'minecraft:taiga_hills',
                default => 'minecraft:taiga',
            });
        }
        if ($temperature > 11_000 && $humidity < -8_000) {
            return new Biome(match (true) {
                $climate->uplift > 13_000 && $variant % 5 === 0 => 'minecraft:mesa_bryce',
                $climate->uplift > 12_000 && $variant % 17 === 0 => 'minecraft:mesa_plateau_stone_mutated',
                $climate->uplift > 11_000 && $variant % 11 === 0 => 'minecraft:mesa_plateau_mutated',
                $climate->uplift > 10_000 && $humidity < -16_000 => 'minecraft:mesa_plateau_stone',
                $climate->uplift > 9_000 => 'minecraft:mesa_plateau',
                $climate->uplift > 6_000 => 'minecraft:mesa',
                $variant % 19 === 0 => 'minecraft:desert_mutated',
                $sample->slope >= 4 => 'minecraft:desert_hills',
                default => 'minecraft:desert',
            });
        }
        if ($temperature > 7_000 && $humidity < 3_500) {
            return new Biome(match (true) {
                $sample->slope >= 5 && $variant % 7 === 0 => 'minecraft:savanna_plateau_mutated',
                $variant % 17 === 0 => 'minecraft:savanna_mutated',
                $sample->slope >= 4 => 'minecraft:savanna_plateau',
                default => 'minecraft:savanna',
            });
        }
        if ($height <= OverworldTerrainSampler::SEA_LEVEL + 3 && $humidity > 11_000) {
            return new Biome(match (true) {
                $temperature > 6_500 => 'minecraft:mangrove_swamp',
                $variant % 13 === 0 => 'minecraft:swampland_mutated',
                default => 'minecraft:swampland',
            });
        }
        if ($temperature > 6_000 && $humidity > 10_000) {
            return new Biome(match (true) {
                $sample->slope >= 5 && $variant % 5 === 0 => 'minecraft:bamboo_jungle_hills',
                $variant % 11 === 0 => 'minecraft:bamboo_jungle',
                $sample->slope >= 4 => 'minecraft:jungle_hills',
                $humidity < 14_000 && $variant % 7 === 0 => 'minecraft:jungle_edge_mutated',
                $humidity < 14_000 => 'minecraft:jungle_edge',
                $variant % 23 === 0 => 'minecraft:jungle_mutated',
                default => 'minecraft:jungle',
            });
        }
        if ($humidity > 7_000) {
            return new Biome(match (true) {
                $temperature < 1_500 && $variant % 7 === 0 => 'minecraft:pale_garden',
                $temperature < 3_500 && $variant % 5 === 0 => 'minecraft:dappled_forest',
                $temperature > 4_500 && $variant % 11 === 0 => 'minecraft:flower_forest',
                $variant % 31 === 0 => 'minecraft:roofed_forest_mutated',
                $variant % 13 === 0 => 'minecraft:roofed_forest',
                $sample->slope >= 4 && $variant % 17 === 0 => 'minecraft:birch_forest_hills_mutated',
                $sample->slope >= 4 && $variant % 7 === 0 => 'minecraft:birch_forest_hills',
                $variant % 23 === 0 => 'minecraft:birch_forest_mutated',
                $variant % 5 === 0 => 'minecraft:birch_forest',
                default => 'minecraft:forest',
            });
        }
        if ($temperature > 1_500 && $humidity > 1_000 && $variant % 19 === 0) {
            return new Biome('minecraft:cherry_grove');
        }

        return new Biome($variant % 13 === 0 ? 'minecraft:sunflower_plains' : 'minecraft:plains');
    }
}
