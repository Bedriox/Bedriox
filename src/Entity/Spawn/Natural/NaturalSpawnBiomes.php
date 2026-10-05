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

/** Exact groups drawn from the pinned Bedrock biome identifier registry. */
final class NaturalSpawnBiomes
{
    private const array OCEANS = [
        'minecraft:ocean',
        'minecraft:deep_ocean',
        'minecraft:warm_ocean',
        'minecraft:deep_warm_ocean',
        'minecraft:lukewarm_ocean',
        'minecraft:deep_lukewarm_ocean',
        'minecraft:cold_ocean',
        'minecraft:deep_cold_ocean',
        'minecraft:frozen_ocean',
        'minecraft:deep_frozen_ocean',
        'minecraft:legacy_frozen_ocean',
    ];

    private const array WARM_OCEANS = [
        'minecraft:warm_ocean',
        'minecraft:deep_warm_ocean',
        'minecraft:lukewarm_ocean',
        'minecraft:deep_lukewarm_ocean',
    ];

    private const array FROZEN_OCEANS = [
        'minecraft:frozen_ocean',
        'minecraft:deep_frozen_ocean',
        'minecraft:legacy_frozen_ocean',
    ];

    private function __construct() {}

    public static function isOcean(string $biome): bool
    {
        return in_array($biome, self::OCEANS, true);
    }

    public static function isWarmOcean(string $biome): bool
    {
        return in_array($biome, self::WARM_OCEANS, true);
    }

    public static function isFrozenOcean(string $biome): bool
    {
        return in_array($biome, self::FROZEN_OCEANS, true);
    }

    public static function isRiver(string $biome): bool
    {
        return $biome === 'minecraft:river' || $biome === 'minecraft:frozen_river';
    }

    public static function isDesert(string $biome): bool
    {
        return in_array($biome, [
            'minecraft:desert',
            'minecraft:desert_hills',
            'minecraft:desert_mutated',
        ], true);
    }

    public static function isSwamp(string $biome): bool
    {
        return in_array($biome, [
            'minecraft:swampland',
            'minecraft:swampland_mutated',
            'minecraft:mangrove_swamp',
        ], true);
    }

    public static function isSnowy(string $biome): bool
    {
        return in_array($biome, [
            'minecraft:frozen_peaks',
            'minecraft:snowy_slopes',
            'minecraft:grove',
            'minecraft:ice_plains',
            'minecraft:ice_mountains',
            'minecraft:ice_plains_spikes',
            'minecraft:cold_taiga',
            'minecraft:cold_taiga_hills',
            'minecraft:cold_taiga_mutated',
        ], true);
    }

    public static function matchesFamily(string $biome, string $family): bool
    {
        return match ($family) {
            'ocean' => self::isOcean($biome),
            'river' => self::isRiver($biome),
            'desert' => self::isDesert($biome),
            'swamp' => self::isSwamp($biome),
            'snow', 'ice' => self::isSnowy($biome) || self::isFrozenOcean($biome),
            'beach' => in_array($biome, [
                'minecraft:beach', 'minecraft:stone_beach', 'minecraft:cold_beach',
            ], true),
            'badlands' => in_array($biome, [
                'minecraft:mesa', 'minecraft:mesa_plateau_stone', 'minecraft:mesa_plateau',
                'minecraft:mesa_bryce', 'minecraft:mesa_plateau_stone_mutated',
                'minecraft:mesa_plateau_mutated',
            ], true),
            'forest' => in_array($biome, [
                'minecraft:forest', 'minecraft:forest_hills', 'minecraft:flower_forest',
                'minecraft:birch_forest', 'minecraft:birch_forest_hills',
                'minecraft:birch_forest_mutated', 'minecraft:birch_forest_hills_mutated',
                'minecraft:roofed_forest', 'minecraft:roofed_forest_mutated',
                'minecraft:pale_garden', 'minecraft:dappled_forest',
            ], true),
            'taiga' => in_array($biome, [
                'minecraft:taiga', 'minecraft:taiga_hills', 'minecraft:taiga_mutated',
                'minecraft:cold_taiga', 'minecraft:cold_taiga_hills', 'minecraft:cold_taiga_mutated',
                'minecraft:mega_taiga', 'minecraft:mega_taiga_hills',
                'minecraft:redwood_taiga_mutated', 'minecraft:redwood_taiga_hills_mutated',
            ], true),
            'grove' => in_array($biome, ['minecraft:grove', 'minecraft:cherry_grove'], true),
            'jungle' => in_array($biome, [
                'minecraft:jungle', 'minecraft:jungle_hills', 'minecraft:jungle_edge',
                'minecraft:jungle_mutated', 'minecraft:jungle_edge_mutated',
                'minecraft:bamboo_jungle', 'minecraft:bamboo_jungle_hills',
            ], true),
            'plains' => in_array($biome, [
                'minecraft:plains', 'minecraft:sunflower_plains', 'minecraft:meadow',
            ], true),
            'savanna' => in_array($biome, [
                'minecraft:savanna', 'minecraft:savanna_plateau',
                'minecraft:savanna_mutated', 'minecraft:savanna_plateau_mutated',
            ], true),
            'mountain', 'windswept' => in_array($biome, [
                'minecraft:extreme_hills', 'minecraft:extreme_hills_edge',
                'minecraft:extreme_hills_mutated', 'minecraft:extreme_hills_plus_trees',
                'minecraft:extreme_hills_plus_trees_mutated', 'minecraft:jagged_peaks',
                'minecraft:frozen_peaks', 'minecraft:stony_peaks', 'minecraft:snowy_slopes',
            ], true),
            'peak' => in_array($biome, [
                'minecraft:jagged_peaks', 'minecraft:frozen_peaks', 'minecraft:stony_peaks',
            ], true),
            'slope' => $biome === 'minecraft:snowy_slopes',
            'mushroom' => in_array($biome, [
                'minecraft:mushroom_island', 'minecraft:mushroom_island_shore',
            ], true),
            default => false,
        };
    }
}
