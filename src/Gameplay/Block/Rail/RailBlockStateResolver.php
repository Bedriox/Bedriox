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

namespace Bedriox\Server\Gameplay\Block\Rail;

use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Api\World\Rail\RailSegment;
use Bedriox\Api\World\Rail\RailShape;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Closure;
use InvalidArgumentException;

/** Resolves rail geometry from a bounded three-level neighborhood without loading terrain. */
final readonly class RailBlockStateResolver
{
    public function __construct(private BlockStateRegistry $states) {}

    /**
     * @param Closure(BlockPosition): (CanonicalBlockState|null) $stateAt Must not load or generate terrain.
     */
    public function resolvePlacement(
        CanonicalBlockState $state,
        BlockPosition $position,
        float $playerYaw,
        Closure $stateAt,
    ): InternalBlockStateId {
        $this->assertRail($state);
        $fallback = self::shapeForYaw($playerYaw);

        return $this->resolve($state, $position, $stateAt, $fallback);
    }

    /**
     * @param Closure(BlockPosition): (CanonicalBlockState|null) $stateAt Must not load or generate terrain.
     */
    public function resolveNeighborUpdate(
        CanonicalBlockState $state,
        BlockPosition $position,
        Closure $stateAt,
    ): InternalBlockStateId {
        $this->assertRail($state);
        $current = self::shape($state);

        return $this->resolve($state, $position, $stateAt, $current);
    }

    public function segment(ApiBlockPosition $position, CanonicalBlockState $state): RailSegment
    {
        $this->assertRail($state);

        return new RailSegment($position, $state->identifier(), self::shape($state));
    }

    /**
     * @param Closure(BlockPosition): bool $supportsTopFaceAt Must not load or generate terrain.
     */
    public function isSupported(
        BlockPosition $position,
        CanonicalBlockState $state,
        Closure $supportsTopFaceAt,
    ): bool {
        $this->assertRail($state);
        if (!$supportsTopFaceAt(self::offset($position, 0, -1, 0))) {
            return false;
        }

        return match (self::shape($state)) {
            RailShape::ASCENDING_EAST => $supportsTopFaceAt(self::offset($position, 1, 0, 0)),
            RailShape::ASCENDING_WEST => $supportsTopFaceAt(self::offset($position, -1, 0, 0)),
            RailShape::ASCENDING_NORTH => $supportsTopFaceAt(self::offset($position, 0, 0, -1)),
            RailShape::ASCENDING_SOUTH => $supportsTopFaceAt(self::offset($position, 0, 0, 1)),
            default => true,
        };
    }

    /** @return list<BlockPosition> */
    public function affectedPositions(BlockPosition $position): array
    {
        $positions = [$position];
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$x, $z]) {
            foreach ([-1, 0, 1] as $y) {
                $positions[] = self::offset($position, $x, $y, $z);
            }
        }

        return $positions;
    }

    /** @param Closure(BlockPosition): (CanonicalBlockState|null) $stateAt */
    private function resolve(
        CanonicalBlockState $state,
        BlockPosition $position,
        Closure $stateAt,
        RailShape $fallback,
    ): InternalBlockStateId {
        $north = $this->railInColumn($position, 0, -1, $stateAt);
        $south = $this->railInColumn($position, 0, 1, $stateAt);
        $west = $this->railInColumn($position, -1, 0, $stateAt);
        $east = $this->railInColumn($position, 1, 0, $stateAt);

        $n = $north !== null;
        $s = $south !== null;
        $w = $west !== null;
        $e = $east !== null;
        $vertical = $n || $s;
        $horizontal = $w || $e;
        $shape = $fallback;

        if ($vertical && !$horizontal) {
            $shape = RailShape::NORTH_SOUTH;
        } elseif ($horizontal && !$vertical) {
            $shape = RailShape::EAST_WEST;
        } elseif ($state->identifier() === 'minecraft:rail') {
            $shape = self::curveFor($n, $s, $w, $e, $fallback);
        } else {
            $shape = self::axisFallback($fallback);
        }

        if (!$shape->isCurved()) {
            $shape = self::promoteAscending($shape, $north, $south, $west, $east, $position);
        }
        if ($shape->isCurved() && $state->identifier() !== 'minecraft:rail') {
            $shape = self::axisFallback($fallback);
        }

        $properties = $state->properties();
        $properties['rail_direction'] = $shape->value;

        return $this->states->internalId(CanonicalBlockState::from($state->identifier(), $properties));
    }

    /** @param Closure(BlockPosition): (CanonicalBlockState|null) $stateAt */
    private function railInColumn(BlockPosition $origin, int $x, int $z, Closure $stateAt): ?BlockPosition
    {
        foreach ([0, 1, -1] as $y) {
            $position = self::offset($origin, $x, $y, $z);
            $state = $stateAt($position);
            if ($state instanceof CanonicalBlockState
                && BlockTagRegistry::contains(BlockTag::RAIL, $state->identifier())) {
                return $position;
            }
        }

        return null;
    }

    private function assertRail(CanonicalBlockState $state): void
    {
        if (!BlockTagRegistry::contains(BlockTag::RAIL, $state->identifier())) {
            throw new InvalidArgumentException('Rail state resolver requires a canonical rail block state.');
        }
        self::shape($state);
    }

    private static function shape(CanonicalBlockState $state): RailShape
    {
        $direction = $state->properties()['rail_direction'] ?? null;
        if (!is_int($direction)) {
            throw new InvalidArgumentException('Rail block state must contain an integer rail_direction property.');
        }
        $shape = RailShape::tryFrom($direction);
        if ($shape === null || ($shape->isCurved() && $state->identifier() !== 'minecraft:rail')) {
            throw new InvalidArgumentException('Rail block state contains an unsupported rail_direction value.');
        }

        return $shape;
    }

    private static function shapeForYaw(float $yaw): RailShape
    {
        $normalized = fmod($yaw, 360.0);
        if ($normalized < 0.0) {
            $normalized += 360.0;
        }

        return ($normalized >= 45.0 && $normalized < 135.0)
            || ($normalized >= 225.0 && $normalized < 315.0)
            ? RailShape::EAST_WEST
            : RailShape::NORTH_SOUTH;
    }

    private static function axisFallback(RailShape $fallback): RailShape
    {
        return match ($fallback) {
            RailShape::EAST_WEST, RailShape::ASCENDING_EAST, RailShape::ASCENDING_WEST => RailShape::EAST_WEST,
            default => RailShape::NORTH_SOUTH,
        };
    }

    private static function curveFor(bool $north, bool $south, bool $west, bool $east, RailShape $fallback): RailShape
    {
        $currentEndpointsAvailable = match ($fallback) {
            RailShape::SOUTH_EAST => $south && $east,
            RailShape::SOUTH_WEST => $south && $west,
            RailShape::NORTH_WEST => $north && $west,
            RailShape::NORTH_EAST => $north && $east,
            default => false,
        };
        if ($currentEndpointsAvailable) {
            return $fallback;
        }

        return match (true) {
            $south && $east && !$north && !$west => RailShape::SOUTH_EAST,
            $south && $west && !$north && !$east => RailShape::SOUTH_WEST,
            $north && $west && !$south && !$east => RailShape::NORTH_WEST,
            $north && $east && !$south && !$west => RailShape::NORTH_EAST,
            $south && $east => RailShape::SOUTH_EAST,
            $south && $west => RailShape::SOUTH_WEST,
            $north && $west => RailShape::NORTH_WEST,
            $north && $east => RailShape::NORTH_EAST,
            default => self::axisFallback($fallback),
        };
    }

    private static function promoteAscending(
        RailShape $shape,
        ?BlockPosition $north,
        ?BlockPosition $south,
        ?BlockPosition $west,
        ?BlockPosition $east,
        BlockPosition $origin,
    ): RailShape {
        if ($shape === RailShape::NORTH_SOUTH) {
            if ($north?->y === $origin->y + 1) {
                return RailShape::ASCENDING_NORTH;
            }
            if ($south?->y === $origin->y + 1) {
                return RailShape::ASCENDING_SOUTH;
            }
        }
        if ($shape === RailShape::EAST_WEST) {
            if ($east?->y === $origin->y + 1) {
                return RailShape::ASCENDING_EAST;
            }
            if ($west?->y === $origin->y + 1) {
                return RailShape::ASCENDING_WEST;
            }
        }

        return $shape;
    }

    private static function offset(BlockPosition $position, int $x, int $y, int $z): BlockPosition
    {
        return new BlockPosition($position->x + $x, $position->y + $y, $position->z + $z);
    }
}
