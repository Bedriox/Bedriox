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

namespace Bedriox\Server\Gameplay\Block;

/** Authoritative vanilla loot definitions that cannot be represented by the legacy generated-block fallback. */
final class VanillaBlockLootRegistry
{
    /** @var null|array<string, BlockLootDefinition> */
    private static ?array $definitions = null;

    private function __construct() {}

    public static function definition(string $identifier): ?BlockLootDefinition
    {
        return self::definitions()[$identifier] ?? null;
    }

    /** @return array<string, BlockLootDefinition> */
    private static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }

        $definitions = [];
        self::orePair($definitions, 'coal', 'minecraft:coal', 1, 1, 0, 2);
        self::orePair($definitions, 'copper', 'minecraft:raw_copper', 2, 5);
        self::orePair($definitions, 'iron', 'minecraft:raw_iron', 1, 1);
        self::orePair($definitions, 'gold', 'minecraft:raw_gold', 1, 1);
        self::orePair($definitions, 'lapis', 'minecraft:lapis_lazuli', 4, 9, 2, 5);
        self::orePair($definitions, 'diamond', 'minecraft:diamond', 1, 1, 3, 7);
        self::orePair($definitions, 'emerald', 'minecraft:emerald', 1, 1, 3, 7);
        foreach (['minecraft:redstone_ore', 'minecraft:deepslate_redstone_ore'] as $identifier) {
            $definitions[$identifier] = new BlockLootDefinition(
                BlockLootKind::Redstone,
                'minecraft:redstone',
                4,
                5,
                $identifier,
                1,
                5,
            );
        }
        $definitions['minecraft:quartz_ore'] = new BlockLootDefinition(
            BlockLootKind::FortuneWeighted,
            'minecraft:quartz',
            1,
            1,
            'minecraft:quartz_ore',
            2,
            5,
        );
        $definitions['minecraft:nether_gold_ore'] = new BlockLootDefinition(
            BlockLootKind::FortuneWeighted,
            'minecraft:gold_nugget',
            2,
            6,
            'minecraft:nether_gold_ore',
            0,
            1,
        );
        $definitions['minecraft:ancient_debris'] = self::fixed('minecraft:ancient_debris', 'minecraft:ancient_debris');
        $definitions['minecraft:grass_block'] = self::fixed('minecraft:dirt', 'minecraft:grass_block');
        $definitions['minecraft:mycelium'] = self::fixed('minecraft:dirt', 'minecraft:mycelium');
        $definitions['minecraft:podzol'] = self::fixed('minecraft:dirt', 'minecraft:podzol');
        $definitions['minecraft:grass_path'] = self::fixed('minecraft:dirt', 'minecraft:grass_path');
        $definitions['minecraft:rooted_dirt'] = self::fixed('minecraft:rooted_dirt', 'minecraft:rooted_dirt');
        $definitions['minecraft:creaking_heart'] = new BlockLootDefinition(
            BlockLootKind::FortuneWeighted,
            'minecraft:resin_clump',
            1,
            3,
            'minecraft:creaking_heart',
            20,
            24,
            experienceWithSilkTouch: true,
            experienceStateProperty: 'natural',
            experienceStateValue: 1,
        );

        foreach (self::infestedBlocks() as $identifier => $imitatedIdentifier) {
            $definitions[$identifier] = new BlockLootDefinition(
                BlockLootKind::None,
                silkTouchIdentifier: $imitatedIdentifier,
                breakEffect: BlockBreakEffect::SpawnSilverfish,
            );
        }

        return self::$definitions = $definitions;
    }

    /** @param array<string, BlockLootDefinition> $definitions */
    private static function orePair(
        array &$definitions,
        string $ore,
        string $drop,
        int $minimum,
        int $maximum,
        int $minimumExperience = 0,
        int $maximumExperience = 0,
    ): void {
        foreach (["minecraft:{$ore}_ore", "minecraft:deepslate_{$ore}_ore"] as $identifier) {
            $definitions[$identifier] = new BlockLootDefinition(
                BlockLootKind::FortuneWeighted,
                $drop,
                $minimum,
                $maximum,
                $identifier,
                $minimumExperience,
                $maximumExperience,
            );
        }
    }

    private static function fixed(string $drop, ?string $silkTouch): BlockLootDefinition
    {
        return new BlockLootDefinition(BlockLootKind::Fixed, $drop, 1, 1, $silkTouch);
    }

    /** @return array<string, string> */
    private static function infestedBlocks(): array
    {
        return [
            'minecraft:infested_stone' => 'minecraft:stone',
            'minecraft:infested_cobblestone' => 'minecraft:cobblestone',
            'minecraft:infested_stone_bricks' => 'minecraft:stone_bricks',
            'minecraft:infested_mossy_stone_bricks' => 'minecraft:mossy_stone_bricks',
            'minecraft:infested_cracked_stone_bricks' => 'minecraft:cracked_stone_bricks',
            'minecraft:infested_chiseled_stone_bricks' => 'minecraft:chiseled_stone_bricks',
            'minecraft:infested_deepslate' => 'minecraft:deepslate',
        ];
    }
}
