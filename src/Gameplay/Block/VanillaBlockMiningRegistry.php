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

use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;

/** Central vanilla mining-property overrides for blocks admitted from the current data set. */
final class VanillaBlockMiningRegistry
{
    /** @var null|array<string, BlockMiningDefinition> */
    private static ?array $definitions = null;

    private function __construct() {}

    public static function definition(string $identifier): ?BlockMiningDefinition
    {
        return self::definitions()[$identifier] ?? null;
    }

    /** @return array<string, BlockMiningDefinition> */
    private static function definitions(): array
    {
        if (self::$definitions !== null) {
            return self::$definitions;
        }
        $pickaxe = ToolType::Pickaxe;
        $definitions = [];
        foreach (['coal' => ToolTier::Wood, 'copper' => ToolTier::Stone, 'iron' => ToolTier::Stone,
            'gold' => ToolTier::Iron, 'lapis' => ToolTier::Stone, 'diamond' => ToolTier::Iron,
            'emerald' => ToolTier::Iron, 'redstone' => ToolTier::Iron] as $ore => $tier) {
            $definitions["minecraft:{$ore}_ore"] = new BlockMiningDefinition(3.0, $pickaxe, $tier);
            $definitions["minecraft:deepslate_{$ore}_ore"] = new BlockMiningDefinition(4.5, $pickaxe, $tier);
        }
        $definitions['minecraft:quartz_ore'] = new BlockMiningDefinition(3.0, $pickaxe, ToolTier::Wood);
        $definitions['minecraft:nether_gold_ore'] = new BlockMiningDefinition(3.0, $pickaxe, ToolTier::Wood);
        $definitions['minecraft:ancient_debris'] = new BlockMiningDefinition(30.0, $pickaxe, ToolTier::Diamond);
        $definitions['minecraft:grass_block'] = new BlockMiningDefinition(0.6, ToolType::Shovel, null);
        $definitions['minecraft:mycelium'] = new BlockMiningDefinition(0.6, ToolType::Shovel, null);
        $definitions['minecraft:podzol'] = new BlockMiningDefinition(0.5, ToolType::Shovel, null);
        $definitions['minecraft:grass_path'] = new BlockMiningDefinition(0.65, ToolType::Shovel, null);
        $definitions['minecraft:rooted_dirt'] = new BlockMiningDefinition(0.5, ToolType::Shovel, null);
        $definitions['minecraft:creaking_heart'] = new BlockMiningDefinition(10.0, ToolType::Axe, null);
        foreach (['minecraft:infested_stone', 'minecraft:infested_cobblestone', 'minecraft:infested_stone_bricks',
            'minecraft:infested_mossy_stone_bricks', 'minecraft:infested_cracked_stone_bricks',
            'minecraft:infested_chiseled_stone_bricks', 'minecraft:infested_deepslate'] as $identifier) {
            $definitions[$identifier] = new BlockMiningDefinition(0.75, $pickaxe, ToolTier::Wood);
        }

        return self::$definitions = $definitions;
    }
}
