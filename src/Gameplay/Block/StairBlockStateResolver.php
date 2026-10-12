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

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;
use LogicException;

/** Recalculates the canonical corner state of one stair from its horizontal neighbors. */
final readonly class StairBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    /** @param array<string, mixed> $neighbors */
    public function resolve(CanonicalBlockState $state, array $neighbors): InternalBlockStateId
    {
        $properties = $state->properties();
        $direction = self::directionName($properties['weirdo_direction'] ?? null);
        $upsideDown = ($properties['upside_down_bit'] ?? 0) === 1;
        $validated = [];
        foreach (['north', 'east', 'south', 'west'] as $name) {
            $neighbor = $neighbors[$name] ?? null;
            if (!$neighbor instanceof CanonicalBlockState) {
                throw new InvalidArgumentException('Stair state resolution requires four horizontal neighbors.');
            }
            $validated[$name] = $neighbor;
        }
        $corner = 'none';
        $forward = $validated[$direction];
        $forwardDirection = self::matchingDirection($forward, $upsideDown);
        if ($forwardDirection !== null && self::axis($forwardDirection) !== self::axis($direction)
            && self::canTakeShape($validated, $state, self::opposite($forwardDirection), $upsideDown)) {
            $corner = $forwardDirection === self::counterClockwise($direction) ? 'outer_left' : 'outer_right';
        } else {
            $backward = $validated[self::opposite($direction)];
            $backwardDirection = self::matchingDirection($backward, $upsideDown);
            if ($backwardDirection !== null && self::axis($backwardDirection) !== self::axis($direction)
                && self::canTakeShape($validated, $state, $backwardDirection, $upsideDown)) {
                $corner = $backwardDirection === self::counterClockwise($direction) ? 'inner_left' : 'inner_right';
            }
        }
        $properties['minecraft:corner'] = $corner;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    /** @param array{north: CanonicalBlockState, east: CanonicalBlockState, south: CanonicalBlockState, west: CanonicalBlockState} $neighbors */
    private static function canTakeShape(
        array $neighbors,
        CanonicalBlockState $state,
        string $direction,
        bool $upsideDown,
    ): bool {
        $blockingDirection = self::matchingDirection($neighbors[$direction], $upsideDown);

        return $blockingDirection === null
            || $blockingDirection !== self::directionName($state->properties()['weirdo_direction'] ?? null);
    }

    private static function matchingDirection(CanonicalBlockState $state, bool $upsideDown): ?string
    {
        $properties = $state->properties();
        if (!array_key_exists('minecraft:corner', $properties)
            || (($properties['upside_down_bit'] ?? 0) === 1) !== $upsideDown) {
            return null;
        }

        return self::directionName($properties['weirdo_direction'] ?? null);
    }

    private static function directionName(mixed $direction): string
    {
        return match ($direction) {
            0 => 'east',
            1 => 'west',
            2 => 'south',
            3 => 'north',
            default => throw new InvalidArgumentException('Stair state has an unsupported direction.'),
        };
    }

    private static function opposite(string $direction): string
    {
        return match ($direction) {
            'north' => 'south',
            'east' => 'west',
            'south' => 'north',
            'west' => 'east',
            default => throw new LogicException('Stair direction is outside its supported range.'),
        };
    }

    private static function counterClockwise(string $direction): string
    {
        return match ($direction) {
            'north' => 'west',
            'west' => 'south',
            'south' => 'east',
            'east' => 'north',
            default => throw new LogicException('Stair direction is outside its supported range.'),
        };
    }

    private static function axis(string $direction): string
    {
        return $direction === 'north' || $direction === 'south' ? 'z' : 'x';
    }
}
