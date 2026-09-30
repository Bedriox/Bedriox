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

use Bedriox\Server\Gameplay\Item\ItemType;
use Bedriox\Server\Gameplay\Item\ToolType;

/** Resolves the initial vanilla drop set from authoritative block and tool state. */
final class BlockDropRules
{
    private function __construct() {}

    /** @return list<BlockDrop> */
    public static function drops(
        BlockType $block,
        ?ItemType $heldItem,
        DropRandom $random,
        bool $silkTouch = false,
        int $fortuneLevel = 0,
    ): array {
        if (!BlockBreakRules::canHarvest($block, $heldItem)) {
            return [];
        }
        if ($silkTouch && $block->dropKind !== BlockDropKind::None) {
            return [new BlockDrop($block->itemIdentifier() ?? $block->identifier(), 1)];
        }

        return match ($block->dropKind) {
            BlockDropKind::None => [],
            BlockDropKind::Self => [new BlockDrop($block->itemIdentifier() ?? $block->identifier(), 1)],
            BlockDropKind::Dirt => [new BlockDrop('minecraft:dirt', 1)],
            BlockDropKind::Cobblestone => [new BlockDrop('minecraft:cobblestone', 1)],
            BlockDropKind::CobbledDeepslate => [new BlockDrop('minecraft:cobbled_deepslate', 1)],
            BlockDropKind::Gravel => [new BlockDrop(
                $random->integer(1, max(1, 10 - (3 * min(3, $fortuneLevel)))) !== 1
                    ? 'minecraft:gravel'
                    : 'minecraft:flint',
                1,
            )],
            BlockDropKind::Coal => [new BlockDrop('minecraft:coal', self::fortuneCount(1, $fortuneLevel, $random))],
            BlockDropKind::RawCopper => [new BlockDrop(
                'minecraft:raw_copper',
                self::fortuneCount($random->integer(2, 5), $fortuneLevel, $random),
            )],
            BlockDropKind::RawIron => [new BlockDrop('minecraft:raw_iron', self::fortuneCount(1, $fortuneLevel, $random))],
            BlockDropKind::RawGold => [new BlockDrop('minecraft:raw_gold', self::fortuneCount(1, $fortuneLevel, $random))],
            BlockDropKind::Redstone => [new BlockDrop(
                'minecraft:redstone',
                self::redstoneCount($fortuneLevel, $random),
            )],
            BlockDropKind::Diamond => [new BlockDrop('minecraft:diamond', self::fortuneCount(1, $fortuneLevel, $random))],
            BlockDropKind::ClayBalls => [new BlockDrop('minecraft:clay_ball', 4)],
            BlockDropKind::Snowballs => [new BlockDrop('minecraft:snowball', 4)],
            BlockDropKind::Ice => [],
            BlockDropKind::OakLeaves => self::leaves($block, $heldItem, $random, 'minecraft:oak_sapling', true),
            BlockDropKind::BirchLeaves => self::leaves($block, $heldItem, $random, 'minecraft:birch_sapling', false),
            BlockDropKind::SpruceLeaves => self::leaves($block, $heldItem, $random, 'minecraft:spruce_sapling', false),
        };
    }

    private static function fortuneCount(int $base, int $level, DropRandom $random): int
    {
        if ($level <= 0) {
            return $base;
        }
        $bonus = $random->integer(0, $level + 1) - 1;

        return $base * (max(0, $bonus) + 1);
    }

    private static function redstoneCount(int $level, DropRandom $random): int
    {
        $base = $random->integer(4, 5);

        return $level <= 0 ? $base : min(8, $base + $random->integer(0, $level));
    }

    /** @return list<BlockDrop> */
    private static function leaves(
        BlockType $block,
        ?ItemType $heldItem,
        DropRandom $random,
        string $sapling,
        bool $dropsApples,
    ): array {
        if ($heldItem?->tool?->type === ToolType::Shears) {
            return [new BlockDrop($block->identifier(), 1)];
        }
        $drops = [];
        if ($random->integer(1, 20) === 1) {
            $drops[] = new BlockDrop($sapling, 1);
        }
        if ($dropsApples && $random->integer(1, 200) === 1) {
            $drops[] = new BlockDrop('minecraft:apple', 1);
        }
        if ($random->integer(1, 50) === 1) {
            $drops[] = new BlockDrop('minecraft:stick', $random->integer(1, 2));
        }

        return $drops;
    }
}
