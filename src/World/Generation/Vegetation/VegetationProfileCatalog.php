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

/** Maps every wooded overworld biome to a deliberately distinct vegetation character. */
final class VegetationProfileCatalog
{
    public static function forBiome(string $biome): ?VegetationProfile
    {
        /** @var array<string, VegetationProfile|null> $cache */
        static $cache = [];
        if (array_key_exists($biome, $cache)) {
            return $cache[$biome];
        }

        return $cache[$biome] = self::create($biome);
    }

    private static function create(string $biome): ?VegetationProfile
    {
        if ($biome === 'minecraft:plains' || $biome === 'minecraft:sunflower_plains') {
            return self::profile('plains_oaks', 100, 8, 160, -22_000, [
                [TreeArchetype::OakBroad, 3], [TreeArchetype::OakTall, 2], [TreeArchetype::OakLeaning, 2],
            ]);
        }
        if (str_contains($biome, 'pale_garden')) {
            return self::profile('pale_garden', 860, 4, 96, -16_000, [
                [TreeArchetype::PaleOakGnarled, 7], [TreeArchetype::PaleOakTall, 3],
            ]);
        }
        if (str_contains($biome, 'dappled_forest')) {
            return self::profile('dappled_forest', 780, 4, 112, -17_000, [
                [TreeArchetype::OakBroad, 4], [TreeArchetype::OakForked, 3],
                [TreeArchetype::OakLeaning, 2], [TreeArchetype::BirchTall, 1],
            ]);
        }
        if (str_contains($biome, 'roofed_forest')) {
            return self::profile('dark_forest', 900, 4, 96, -15_000, [
                [TreeArchetype::DarkOakBroad, 7], [TreeArchetype::DarkOakTall, 3],
            ]);
        }
        if (str_contains($biome, 'birch')) {
            return self::profile('birch_forest', 800, 4, 112, -16_000, [
                [TreeArchetype::BirchSlender, 4], [TreeArchetype::BirchTall, 4],
                [TreeArchetype::BirchForked, 2],
            ]);
        }
        if (str_contains($biome, 'flower_forest')) {
            return self::profile('flower_forest', 700, 4, 120, -17_000, [
                [TreeArchetype::OakCompact, 2], [TreeArchetype::OakBroad, 3],
                [TreeArchetype::OakForked, 2], [TreeArchetype::BirchSlender, 2],
                [TreeArchetype::BirchTall, 1],
            ]);
        }
        if (str_contains($biome, 'bamboo_jungle')) {
            return self::profile('bamboo_jungle', 720, 6, 112, -21_000, [
                [TreeArchetype::JungleTall, 4], [TreeArchetype::JungleBranching, 6],
            ]);
        }
        if (str_contains($biome, 'jungle')) {
            return self::profile('jungle', 900, 4, 112, -22_000, [
                [TreeArchetype::JungleTall, 5], [TreeArchetype::JungleBranching, 5],
            ]);
        }
        if (str_contains($biome, 'mangrove')) {
            return self::profile('mangrove', 800, 6, 112, -21_000, [
                [TreeArchetype::MangroveArched, 6], [TreeArchetype::MangroveBroad, 4],
            ]);
        }
        if (str_contains($biome, 'swamp')) {
            return self::profile('swamp', 520, 7, 136, -18_000, [
                [TreeArchetype::SwampOak, 7], [TreeArchetype::OakLeaning, 3],
            ]);
        }
        if (str_contains($biome, 'cherry')) {
            return self::profile('cherry_grove', 650, 6, 112, -18_000, [
                [TreeArchetype::CherryWide, 7], [TreeArchetype::CherryTall, 3],
            ]);
        }
        if ($biome === 'minecraft:meadow') {
            return self::profile('meadow_trees', 90, 9, 192, -20_000, [
                [TreeArchetype::OakTall, 3], [TreeArchetype::BirchTall, 2],
            ]);
        }
        if (str_contains($biome, 'mesa_plateau_stone')) {
            return self::profile('wooded_badlands', 140, 9, 192, -18_000, [
                [TreeArchetype::OakCompact, 3], [TreeArchetype::OakLeaning, 2],
            ]);
        }
        if (str_contains($biome, 'savanna')) {
            return self::profile('savanna', 280, 8, 176, -18_000, [
                [TreeArchetype::AcaciaFlat, 6], [TreeArchetype::AcaciaSplit, 4],
            ]);
        }
        if (str_contains($biome, 'mega_taiga') || str_contains($biome, 'redwood_taiga')) {
            return self::profile('old_growth_taiga', 880, 5, 128, -22_500, [
                [TreeArchetype::SpruceTall, 5], [TreeArchetype::SpruceWide, 5],
            ]);
        }
        if (str_contains($biome, 'taiga') || str_contains($biome, 'grove')) {
            return self::profile('taiga', 830, 5, 128, -22_000, [
                [TreeArchetype::SpruceConical, 4], [TreeArchetype::SpruceTall, 4],
                [TreeArchetype::SpruceWide, 2],
            ]);
        }
        if (str_contains($biome, 'extreme_hills_plus_trees')) {
            return self::profile('wooded_mountains', 480, 7, 144, -20_000, [
                [TreeArchetype::OakTall, 4], [TreeArchetype::OakLeaning, 3],
                [TreeArchetype::SpruceConical, 3],
            ]);
        }
        if (str_contains($biome, 'forest')) {
            return self::profile('forest', 880, 4, 96, -18_000, [
                [TreeArchetype::OakCompact, 2], [TreeArchetype::OakBroad, 3],
                [TreeArchetype::OakTall, 2], [TreeArchetype::OakForked, 2],
                [TreeArchetype::OakLeaning, 1],
            ]);
        }

        return null;
    }

    /** @param non-empty-list<array{TreeArchetype, int}> $archetypes */
    private static function profile(
        string $name,
        int $density,
        int $spacing,
        int $clusterScale,
        int $clearingThreshold,
        array $archetypes,
    ): VegetationProfile {
        return new VegetationProfile($name, $density, $spacing, $clusterScale, $clearingThreshold, $archetypes);
    }
}
