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

use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockPlacementContext;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;
use LogicException;

/** Canonical state transitions and timing rules for one-block switches. */
final readonly class InteractiveBlockStateResolver
{
    public const int STONE_BUTTON_RELEASE_TICKS = 20;
    public const int WOODEN_BUTTON_RELEASE_TICKS = 30;
    public const int PRESSURE_PLATE_RELEASE_TICKS = 20;
    public const int WEIGHTED_PRESSURE_PLATE_RELEASE_TICKS = 10;

    public function __construct(private BlockStateRegistry $states) {}

    public function placeTrapdoor(
        CanonicalBlockState $state,
        BlockPlacementContext $context,
    ): InternalBlockStateId {
        $properties = $state->properties();
        foreach (['direction', 'open_bit', 'upside_down_bit'] as $property) {
            if (!array_key_exists($property, $properties)) {
                throw new InvalidArgumentException('Canonical block state is not a trapdoor.');
            }
        }
        $properties['direction'] = self::trapdoorDirection($context->playerYaw);
        $properties['open_bit'] = 0;
        $properties['upside_down_bit'] = $context->clickedFace === 0
            || ($context->clickedFace !== 1 && $context->clickY > 0.5) ? 1 : 0;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function placeFenceGate(
        CanonicalBlockState $state,
        BlockPlacementContext $context,
        bool $wallOnLeft,
        bool $wallOnRight,
    ): InternalBlockStateId {
        $properties = $state->properties();
        foreach (['in_wall_bit', 'minecraft:cardinal_direction', 'open_bit'] as $property) {
            if (!array_key_exists($property, $properties)) {
                throw new InvalidArgumentException('Canonical block state is not a fence gate.');
            }
        }
        $properties['in_wall_bit'] = $wallOnLeft || $wallOnRight ? 1 : 0;
        $properties['minecraft:cardinal_direction'] = self::playerDirection($context->playerYaw);
        $properties['open_bit'] = 0;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function toggleOpen(CanonicalBlockState $state, ?string $playerFacing = null): InternalBlockStateId
    {
        $properties = $state->properties();
        if (!array_key_exists('open_bit', $properties)
            || array_key_exists('upper_block_bit', $properties)) {
            throw new InvalidArgumentException('Canonical block state is not a trapdoor or fence gate.');
        }
        $opening = $properties['open_bit'] !== 1;
        $properties['open_bit'] = $opening ? 1 : 0;

        if ($opening && array_key_exists('in_wall_bit', $properties) && $playerFacing !== null) {
            self::assertHorizontalDirection($playerFacing);
            $currentFacing = $properties['minecraft:cardinal_direction'] ?? null;
            if ($currentFacing === self::oppositeDirection($playerFacing)) {
                $properties['minecraft:cardinal_direction'] = $playerFacing;
            }
        }

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function canPlayerToggle(CanonicalBlockState $state): bool
    {
        $properties = $state->properties();
        if (!array_key_exists('open_bit', $properties)
            || array_key_exists('upper_block_bit', $properties)) {
            throw new InvalidArgumentException('Canonical block state is not a trapdoor or fence gate.');
        }

        return $state->identifier() !== 'minecraft:iron_trapdoor';
    }

    public function setButtonPressed(CanonicalBlockState $state, bool $pressed): InternalBlockStateId
    {
        $properties = $state->properties();
        if (!array_key_exists('button_pressed_bit', $properties)) {
            throw new InvalidArgumentException('Canonical block state is not a button.');
        }
        $properties['button_pressed_bit'] = $pressed ? 1 : 0;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function buttonReleaseTicks(CanonicalBlockState $state): int
    {
        if (!array_key_exists('button_pressed_bit', $state->properties())) {
            throw new InvalidArgumentException('Canonical block state is not a button.');
        }

        return in_array($state->identifier(), ['minecraft:stone_button', 'minecraft:polished_blackstone_button'], true)
            ? self::STONE_BUTTON_RELEASE_TICKS
            : self::WOODEN_BUTTON_RELEASE_TICKS;
    }

    public function pressurePlateActivationType(CanonicalBlockState $state): PressurePlateActivationType
    {
        if (!BlockTagRegistry::contains(BlockTag::PRESSURE_PLATE, $state->identifier())
            || !array_key_exists('redstone_signal', $state->properties())) {
            throw new InvalidArgumentException('Canonical block state is not a pressure plate.');
        }

        return match ($state->identifier()) {
            'minecraft:stone_pressure_plate', 'minecraft:polished_blackstone_pressure_plate' => PressurePlateActivationType::LIVING_ENTITIES,
            'minecraft:light_weighted_pressure_plate' => PressurePlateActivationType::LIGHT_WEIGHTED,
            'minecraft:heavy_weighted_pressure_plate' => PressurePlateActivationType::HEAVY_WEIGHTED,
            default => PressurePlateActivationType::ALL_ENTITIES,
        };
    }

    public function pressurePlateSignal(
        CanonicalBlockState $state,
        int $livingEntityCount,
        int $allEntityCount,
    ): InternalBlockStateId {
        if ($livingEntityCount < 0 || $allEntityCount < 0 || $livingEntityCount > $allEntityCount) {
            throw new InvalidArgumentException('Pressure plate entity counts are inconsistent.');
        }

        $signal = match ($this->pressurePlateActivationType($state)) {
            PressurePlateActivationType::LIVING_ENTITIES => $livingEntityCount > 0 ? 15 : 0,
            PressurePlateActivationType::ALL_ENTITIES => $allEntityCount > 0 ? 15 : 0,
            PressurePlateActivationType::LIGHT_WEIGHTED => min(15, $allEntityCount),
            PressurePlateActivationType::HEAVY_WEIGHTED => min(15, intdiv($allEntityCount + 9, 10)),
        };
        $properties = $state->properties();
        $properties['redstone_signal'] = $signal;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    public function pressurePlateReleaseTicks(CanonicalBlockState $state): int
    {
        return match ($this->pressurePlateActivationType($state)) {
            PressurePlateActivationType::LIGHT_WEIGHTED,
            PressurePlateActivationType::HEAVY_WEIGHTED => self::WEIGHTED_PRESSURE_PLATE_RELEASE_TICKS,
            default => self::PRESSURE_PLATE_RELEASE_TICKS,
        };
    }

    private static function assertHorizontalDirection(string $direction): void
    {
        if (!in_array($direction, ['north', 'east', 'south', 'west'], true)) {
            throw new InvalidArgumentException('Player facing must be a horizontal cardinal direction.');
        }
    }

    private static function oppositeDirection(string $direction): string
    {
        return match ($direction) {
            'north' => 'south',
            'east' => 'west',
            'south' => 'north',
            'west' => 'east',
            default => throw new InvalidArgumentException('Player facing must be a horizontal cardinal direction.'),
        };
    }

    /** Bedrock trapdoor direction values: east=0, west=1, south=2, north=3. */
    private static function trapdoorDirection(float $yaw): int
    {
        return match (self::oppositeDirection(self::playerDirection($yaw))) {
            'east' => 0,
            'west' => 1,
            'south' => 2,
            'north' => 3,
            default => throw new LogicException('Validated horizontal direction is outside its supported range.'),
        };
    }

    private static function playerDirection(float $yaw): string
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
