<?php

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
    ): array {
        if (!BlockBreakRules::canHarvest($block, $heldItem)) {
            return [];
        }

        return match ($block->dropKind) {
            BlockDropKind::None => [],
            BlockDropKind::Self => [new BlockDrop($block->itemIdentifier() ?? $block->identifier(), 1)],
            BlockDropKind::Dirt => [new BlockDrop('minecraft:dirt', 1)],
            BlockDropKind::Cobblestone => [new BlockDrop('minecraft:cobblestone', 1)],
            BlockDropKind::CobbledDeepslate => [new BlockDrop('minecraft:cobbled_deepslate', 1)],
            BlockDropKind::Gravel => [new BlockDrop(
                $silkTouch || $random->integer(1, 10) !== 1 ? 'minecraft:gravel' : 'minecraft:flint',
                1,
            )],
            BlockDropKind::Coal => [new BlockDrop('minecraft:coal', 1)],
            BlockDropKind::RawCopper => [new BlockDrop('minecraft:raw_copper', $random->integer(2, 5))],
            BlockDropKind::RawIron => [new BlockDrop('minecraft:raw_iron', 1)],
            BlockDropKind::RawGold => [new BlockDrop('minecraft:raw_gold', 1)],
            BlockDropKind::Redstone => [new BlockDrop('minecraft:redstone', $random->integer(4, 5))],
            BlockDropKind::Diamond => [new BlockDrop('minecraft:diamond', 1)],
            BlockDropKind::ClayBalls => [new BlockDrop('minecraft:clay_ball', 4)],
            BlockDropKind::Snowballs => [new BlockDrop('minecraft:snowball', 4)],
            BlockDropKind::Ice => $silkTouch ? [new BlockDrop('minecraft:ice', 1)] : [],
            BlockDropKind::OakLeaves => self::leaves($block, $heldItem, $random, 'minecraft:oak_sapling', true),
            BlockDropKind::BirchLeaves => self::leaves($block, $heldItem, $random, 'minecraft:birch_sapling', false),
            BlockDropKind::SpruceLeaves => self::leaves($block, $heldItem, $random, 'minecraft:spruce_sapling', false),
        };
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
