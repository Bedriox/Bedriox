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

use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use LogicException;

/** Resolves the exact server-owned block state produced by a placement. */
final readonly class BlockPlacementStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function resolve(CanonicalBlockState $itemState, int $clickedFace, float $playerYaw = 0.0): InternalBlockStateId
    {
        return $this->resolvePlacement($itemState, new BlockPlacementContext(
            $clickedFace,
            0.5,
            0.5,
            0.5,
            $playerYaw,
        ));
    }

    public function resolvePlacement(
        CanonicalBlockState $itemState,
        BlockPlacementContext $context,
    ): InternalBlockStateId {
        $properties = $itemState->properties();
        if (array_key_exists('pillar_axis', $properties)) {
            $properties['pillar_axis'] = BlockAxis::fromBlockFace($context->clickedFace)->value;
        }
        if (array_key_exists('minecraft:cardinal_direction', $properties)) {
            $properties['minecraft:cardinal_direction'] = self::oppositeHorizontalDirection($context->playerYaw);
        }
        if (array_key_exists('facing_direction', $properties)
            && $itemState->identifier() === 'minecraft:barrel') {
            $properties['facing_direction'] = self::oppositeHorizontalFace($context->playerYaw);
        }
        if (array_key_exists('weirdo_direction', $properties)
            && array_key_exists('upside_down_bit', $properties)) {
            $properties['weirdo_direction'] = self::stairDirection($context->playerYaw);
            $properties['upside_down_bit'] = $context->clickedFace === 0
                || ($context->clickedFace !== 1 && $context->clickY > 0.5) ? 1 : 0;
            if (array_key_exists('minecraft:corner', $properties)) {
                $properties['minecraft:corner'] = 'none';
            }
        }
        if (BlockTagRegistry::contains(BlockTag::SLAB, $itemState->identifier())
            && array_key_exists('minecraft:vertical_half', $properties)) {
            $properties['minecraft:vertical_half'] = $context->clickedFace === 0
                || ($context->clickedFace !== 1 && $context->clickY > 0.5) ? 'top' : 'bottom';
        }
        if (array_key_exists('button_pressed_bit', $properties)) {
            $properties['button_pressed_bit'] = 0;
            $properties['facing_direction'] = $context->clickedFace;
        }
        if (array_key_exists('open_bit', $properties)) {
            $properties['open_bit'] = 0;
        }

        return $this->states->internalId(CanonicalBlockState::from($itemState->identifier(), $properties));
    }

    /** Returns the double-slab state for a valid same-family merge, or null when normal placement must continue. */
    public function resolveSlabMerge(
        CanonicalBlockState $itemState,
        CanonicalBlockState $existingState,
        BlockPlacementContext $context,
    ): ?InternalBlockStateId {
        $identifier = $itemState->identifier();
        if (!BlockTagRegistry::contains(BlockTag::SLAB, $identifier)
            || $existingState->identifier() !== $identifier) {
            return null;
        }
        $half = $existingState->properties()['minecraft:vertical_half'] ?? null;
        $merge = ($half === 'bottom' && ($context->clickedFace === 1 || $context->clickY > 0.5))
            || ($half === 'top' && ($context->clickedFace === 0 || $context->clickY <= 0.5));
        if (!$merge) {
            return null;
        }
        $doubleIdentifier = substr($identifier, 0, -strlen('_slab')) . '_double_slab';

        return $this->states->internalId(CanonicalBlockState::from($doubleIdentifier, [
            'minecraft:vertical_half' => 'bottom',
        ]));
    }

    private static function oppositeHorizontalDirection(float $yaw): string
    {
        return match (self::oppositeHorizontalFace($yaw)) {
            2 => 'north',
            3 => 'south',
            4 => 'west',
            5 => 'east',
            default => throw new LogicException('Horizontal placement face is outside its supported range.'),
        };
    }

    /** Bedrock facing_direction: north=2, south=3, west=4, east=5. */
    private static function oppositeHorizontalFace(float $yaw): int
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return match (true) {
            $normalized < 45.0 || $normalized >= 315.0 => 2,
            $normalized < 135.0 => 5,
            $normalized < 225.0 => 3,
            default => 4,
        };
    }

    /** Bedrock stair weirdo_direction: east=0, west=1, south=2, north=3. */
    private static function stairDirection(float $yaw): int
    {
        return match (self::horizontalDirection($yaw)) {
            'east' => 0,
            'west' => 1,
            'south' => 2,
            'north' => 3,
            default => throw new LogicException('Stair direction is outside its supported range.'),
        };
    }

    private static function horizontalDirection(float $yaw): string
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return match (true) {
            $normalized < 45.0 || $normalized >= 315.0 => 'south',
            $normalized < 135.0 => 'west',
            $normalized < 225.0 => 'north',
            default => 'east',
        };
    }
}
