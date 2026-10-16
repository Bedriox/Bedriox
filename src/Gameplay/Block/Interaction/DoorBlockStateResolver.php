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

namespace Bedriox\Server\Gameplay\Block\Interaction;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockPlacementContext;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Resolves atomic door placement and interaction states without owning world mutation. */
final readonly class DoorBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    public function place(
        CanonicalBlockState $itemState,
        BlockPlacementContext $context,
        bool $leftNeighborIsSameDoor,
        bool $leftNeighborIsOpaque,
        bool $rightNeighborIsOpaque,
    ): DoorStatePair {
        self::assertDoor($itemState);
        if ($context->clickedFace !== 1) {
            throw new InvalidArgumentException('A door may only be placed against the top face of its support.');
        }

        $properties = $itemState->properties();
        $properties['minecraft:cardinal_direction'] = self::doorDirection($context->playerYaw);
        $properties['door_hinge_bit'] = $leftNeighborIsSameDoor
            || ($leftNeighborIsOpaque && !$rightNeighborIsOpaque) ? 1 : 0;
        $properties['open_bit'] = 0;
        $properties['upper_block_bit'] = 0;
        $lower = $this->states->internalId(CanonicalBlockState::from($itemState->identifier(), $properties));

        $properties['upper_block_bit'] = 1;
        $upper = $this->states->internalId(CanonicalBlockState::from($itemState->identifier(), $properties));

        return new DoorStatePair($lower, $upper);
    }

    public function toggle(CanonicalBlockState $lower, CanonicalBlockState $upper): DoorStatePair
    {
        self::assertDoor($lower);
        self::assertDoor($upper);
        if ($lower->identifier() !== $upper->identifier()
            || ($lower->properties()['upper_block_bit'] ?? null) !== 0
            || ($upper->properties()['upper_block_bit'] ?? null) !== 1) {
            throw new InvalidArgumentException('Door interaction requires matching lower and upper halves.');
        }

        $open = ($lower->properties()['open_bit'] ?? null) === 1 ? 0 : 1;

        return new DoorStatePair(
            $this->withOpenState($lower, $open),
            $this->withOpenState($upper, $open),
        );
    }

    public function canPlayerToggle(CanonicalBlockState $state): bool
    {
        self::assertDoor($state);

        return $state->identifier() !== 'minecraft:iron_door';
    }

    private function withOpenState(CanonicalBlockState $state, int $open): InternalBlockStateId
    {
        $properties = $state->properties();
        $properties['open_bit'] = $open;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    private static function assertDoor(CanonicalBlockState $state): void
    {
        $properties = $state->properties();
        foreach (['door_hinge_bit', 'minecraft:cardinal_direction', 'open_bit', 'upper_block_bit'] as $property) {
            if (!array_key_exists($property, $properties)) {
                throw new InvalidArgumentException('Canonical block state is not a door.');
            }
        }
    }

    /**
     * Bedrock stores door directions one clockwise turn from their logical facing.
     * Player yaw uses the same convention as entity motion: zero points south.
     */
    private static function doorDirection(float $yaw): string
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return match (true) {
            $normalized < 45.0 || $normalized >= 315.0 => 'west',
            $normalized < 135.0 => 'north',
            $normalized < 225.0 => 'east',
            default => 'south',
        };
    }
}
