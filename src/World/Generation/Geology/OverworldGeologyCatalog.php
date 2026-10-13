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

namespace Bedriox\Server\World\Generation\Geology;

/** Current ordered overworld geology rules and their measured placement distributions. */
final class OverworldGeologyCatalog
{
    /** @return list<OverworldGeologyRule> */
    public static function rules(): array
    {
        $stoneOre = static fn(string $ore): array => [
            'minecraft:stone' => "minecraft:$ore",
            'minecraft:deepslate' => "minecraft:deepslate_$ore",
        ];

        return [
            new OverworldGeologyRule('minecraft:overworld_underground_iron_ore_small_feature', 10, null, false, -64, 136, 4, 0.0, $stoneOre('iron_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_diamond_ore_feature_square', 2, null, false, -64, 60, 4, 0.5, $stoneOre('diamond_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_coal_ore_upper_feature', 30, null, false, 136, 184, 17, 0.0, $stoneOre('coal_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_iron_ore_upper_feature', 90, null, true, 80, 153, 10, 0.0, $stoneOre('iron_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_gold_ore_feature', 4, null, true, -64, 49, 9, 0.5, $stoneOre('gold_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_diamond_ore_buried_feature', 4, null, true, -144, 81, 8, 1.0, $stoneOre('diamond_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_andesite_lower_feature', 2, null, false, 0, 60, 64, 0.0, ['minecraft:stone' => 'minecraft:andesite']),
            new OverworldGeologyRule('minecraft:overworld_underground_gravel_ore_feature', 14, null, false, -64, 384, 33, 0.0, ['minecraft:stone' => 'minecraft:gravel', 'minecraft:deepslate' => 'minecraft:gravel']),
            new OverworldGeologyRule('minecraft:overworld_underground_andesite_upper_feature', 0, 6, false, 64, 16, 64, 0.0, ['minecraft:stone' => 'minecraft:andesite']),
            new OverworldGeologyRule('minecraft:overworld_underground_copper_ore_feature', 16, null, true, -16, 65, 10, 0.0, $stoneOre('copper_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_coal_ore_lower_feature', 20, null, true, 0, 97, 17, 0.5, $stoneOre('coal_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_diamond_ore_feature', 7, null, true, -144, 81, 4, 0.5, $stoneOre('diamond_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_iron_ore_middle_feature', 10, null, true, -24, 41, 10, 0.0, $stoneOre('iron_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_diamond_ore_large_feature', 0, 9, true, -144, 81, 12, 0.7, $stoneOre('diamond_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_diorite_lower_feature', 2, null, false, 0, 60, 64, 0.0, ['minecraft:stone' => 'minecraft:diorite']),
            new OverworldGeologyRule('minecraft:overworld_underground_diorite_upper_feature', 0, 6, false, 64, 16, 64, 0.0, ['minecraft:stone' => 'minecraft:diorite']),
            new OverworldGeologyRule('minecraft:overworld_underground_redstone_ore_lower_feature', 8, null, true, -96, 33, 8, 0.0, $stoneOre('redstone_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_dirt_feature', 7, null, false, 0, 160, 33, 0.0, ['minecraft:stone' => 'minecraft:dirt']),
            new OverworldGeologyRule('minecraft:overworld_underground_gold_ore_lower_feature', 0, 2, false, -64, 16, 9, 0.5, $stoneOre('gold_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_granite_lower_feature', 2, null, false, 0, 60, 64, 0.0, ['minecraft:stone' => 'minecraft:granite']),
            new OverworldGeologyRule('minecraft:overworld_underground_granite_upper_feature', 0, 6, false, 64, 16, 64, 0.0, ['minecraft:stone' => 'minecraft:granite']),
            new OverworldGeologyRule('minecraft:overworld_underground_lapis_ore_buried_feature', 4, null, false, -64, 128, 7, 1.0, $stoneOre('lapis_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_lapis_ore_feature', 2, null, true, -32, 33, 7, 0.0, $stoneOre('lapis_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_redstone_ore_feature', 4, null, false, -64, 79, 8, 0.0, $stoneOre('redstone_ore')),
            new OverworldGeologyRule('minecraft:overworld_underground_tuff_feature', 2, null, false, -64, 64, 64, 0.0, ['minecraft:stone' => 'minecraft:tuff', 'minecraft:deepslate' => 'minecraft:tuff']),
        ];
    }
}
