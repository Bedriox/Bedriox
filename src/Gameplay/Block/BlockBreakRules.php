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
use Bedriox\Server\Gameplay\Item\ToolDefinition;
use Bedriox\Server\Gameplay\Item\ToolType;

/** Deterministic vanilla mining compatibility and progress calculations. */
final class BlockBreakRules
{
    private const float COMPATIBLE_TOOL_MULTIPLIER = 1.5;
    private const float INCOMPATIBLE_TOOL_MULTIPLIER = 5.0;

    private function __construct() {}

    public static function canHarvest(BlockType $block, ?ItemType $heldItem): bool
    {
        if (!$block->isBreakable()) {
            return false;
        }
        if ($block->requiredTier === null) {
            return true;
        }

        $tool = $heldItem?->tool;

        return $tool !== null
            && $tool->type === $block->preferredTool
            && $tool->harvestLevel() >= $block->requiredTier->harvestLevel();
    }

    public static function breakTimeSeconds(BlockType $block, ?ItemType $heldItem): float
    {
        if (!$block->isBreakable()) {
            return INF;
        }
        $tool = $heldItem?->tool;
        if (self::breaksInstantly($block, $tool)) {
            return 0.0;
        }
        $seconds = $block->hardness * (
            self::canHarvest($block, $heldItem)
                ? self::COMPATIBLE_TOOL_MULTIPLIER
                : self::INCOMPATIBLE_TOOL_MULTIPLIER
        );
        if ($tool !== null && $tool->type === $block->preferredTool) {
            $seconds /= $tool->miningEfficiency;
        }

        return $seconds;
    }

    public static function progressPerTick(
        BlockType $block,
        ?ItemType $heldItem,
        BlockBreakContext $context = new BlockBreakContext(),
    ): float {
        $seconds = self::breakTimeSeconds($block, $heldItem);
        if ($seconds === INF) {
            return 0.0;
        }
        if ($seconds === 0.0) {
            return 1.0;
        }
        if ($context->airborne) {
            $seconds *= 5.0;
        }
        if ($context->underwater && !$context->aquaAffinity) {
            $seconds *= 5.0;
        }
        $progress = 1.0 / ($seconds * 20.0);
        if ($context->hasteLevel > 0) {
            $progress *= (1.0 + 0.2 * $context->hasteLevel) * (1.2 ** $context->hasteLevel);
        }
        if ($context->miningFatigueLevel > 0) {
            $progress *= 0.21 ** $context->miningFatigueLevel;
        }

        return min(1.0, $progress);
    }

    public static function networkBreakRate(
        BlockType $block,
        ?ItemType $heldItem,
        BlockBreakContext $context = new BlockBreakContext(),
    ): int {
        return (int) (65_535 * self::progressPerTick($block, $heldItem, $context));
    }

    private static function breaksInstantly(BlockType $block, ?ToolDefinition $tool): bool
    {
        return $block->hardness === 0.0
            || ($tool?->type === ToolType::Shears && self::isLeaves($block));
    }

    private static function isLeaves(BlockType $block): bool
    {
        return match ($block->dropKind) {
            BlockDropKind::OakLeaves, BlockDropKind::BirchLeaves, BlockDropKind::SpruceLeaves => true,
            default => false,
        };
    }
}
