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

namespace Bedriox\Server\World\Collision;

use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Data\CanonicalBlockState;

/** Derives immutable collision geometry from canonical Bedrock state properties. */
final class StateAwareBlockCollisionResolver
{
    public static function resolve(CanonicalBlockState $state): ?BlockCollisionShape
    {
        $identifier = $state->identifier();
        $properties = $state->properties();
        if (BlockTagRegistry::contains(BlockTag::STAIRS, $identifier)
            && array_key_exists('weirdo_direction', $properties)
            && array_key_exists('upside_down_bit', $properties)
            && array_key_exists('minecraft:corner', $properties)) {
            return self::stair($properties);
        }
        if (BlockTagRegistry::contains(BlockTag::DOOR, $identifier)
            && array_key_exists('door_hinge_bit', $properties)
            && array_key_exists('minecraft:cardinal_direction', $properties)
            && array_key_exists('open_bit', $properties)
            && array_key_exists('upper_block_bit', $properties)) {
            return self::door($properties);
        }
        if (BlockTagRegistry::contains(BlockTag::DOUBLE_SLAB, $identifier)) {
            return BlockCollisionShape::fullCube();
        }
        if (BlockTagRegistry::contains(BlockTag::SLAB, $identifier)
            && array_key_exists('minecraft:vertical_half', $properties)) {
            return $properties['minecraft:vertical_half'] === 'top'
                ? self::box(0.0, 0.5, 0.0, 1.0, 1.0, 1.0)
                : self::box(0.0, 0.0, 0.0, 1.0, 0.5, 1.0);
        }
        if (BlockTagRegistry::contains(BlockTag::WALL, $identifier)
            && array_key_exists('wall_post_bit', $properties)) {
            return self::wall($properties);
        }
        if (array_key_exists('minecraft:connection_north', $properties)) {
            return self::connected($identifier, $properties);
        }
        if (BlockTagRegistry::contains(BlockTag::BUTTON, $identifier)
            && array_key_exists('button_pressed_bit', $properties)) {
            return self::button($properties);
        }
        if (BlockTagRegistry::contains(BlockTag::PRESSURE_PLATE, $identifier)
            && array_key_exists('redstone_signal', $properties)) {
            return BlockCollisionShape::empty();
        }

        return null;
    }

    /** @param array<string, int|string> $properties */
    private static function door(array $properties): BlockCollisionShape
    {
        $facing = match ($properties['minecraft:cardinal_direction']) {
            'north' => 2,
            'south' => 3,
            'west' => 4,
            'east' => 5,
            default => throw new \LogicException('Door direction is outside its supported range.'),
        };
        if (($properties['open_bit'] ?? 0) === 1) {
            $hingeRight = ($properties['door_hinge_bit'] ?? 0) === 1;
            $facing = self::rotateHorizontal($facing, !$hingeRight);
        }

        $thickness = 73.0 / 400.0;

        return match ($facing) {
            2 => self::box(0.0, 0.0, 1.0 - $thickness, 1.0, 1.0, 1.0),
            3 => self::box(0.0, 0.0, 0.0, 1.0, 1.0, $thickness),
            4 => self::box(1.0 - $thickness, 0.0, 0.0, 1.0, 1.0, 1.0),
            5 => self::box(0.0, 0.0, 0.0, $thickness, 1.0, 1.0),
            default => throw new \LogicException('Door direction is outside its supported range.'),
        };
    }

    private static function rotateHorizontal(int $facing, bool $clockwise): int
    {
        return match ([$facing, $clockwise]) {
            [2, true] => 5,
            [5, true] => 3,
            [3, true] => 4,
            [4, true] => 2,
            [2, false] => 4,
            [4, false] => 3,
            [3, false] => 5,
            [5, false] => 2,
            default => throw new \LogicException('Door rotation requires a horizontal direction.'),
        };
    }

    /** @param array<string, int|string> $properties */
    private static function stair(array $properties): BlockCollisionShape
    {
        $upsideDown = ($properties['upside_down_bit'] ?? 0) === 1;
        $direction = $properties['weirdo_direction'] ?? 0;
        $corner = $properties['minecraft:corner'] ?? 'none';
        $boxes = [$upsideDown
            ? new AxisAlignedBox(0.0, 0.5, 0.0, 1.0, 1.0, 1.0)
            : new AxisAlignedBox(0.0, 0.0, 0.0, 1.0, 0.5, 1.0)];
        $minY = $upsideDown ? 0.0 : 0.5;
        $maxY = $upsideDown ? 0.5 : 1.0;
        [$minX, $minZ, $maxX, $maxZ] = match ($direction) {
            0 => [0.5, 0.0, 1.0, 1.0], // east
            1 => [0.0, 0.0, 0.5, 1.0], // west
            2 => [0.0, 0.5, 1.0, 1.0], // south
            3 => [0.0, 0.0, 1.0, 0.5], // north
            default => [0.0, 0.0, 1.0, 1.0],
        };
        if ($corner === 'outer_left' || $corner === 'outer_right') {
            [$minX, $minZ, $maxX, $maxZ] = self::stairOuterQuarter(
                (int) $direction,
                $corner === 'outer_left',
            );
        }
        $boxes[] = new AxisAlignedBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
        if ($corner === 'inner_left' || $corner === 'inner_right') {
            [$minX, $minZ, $maxX, $maxZ] = self::stairInnerQuarter(
                (int) $direction,
                $corner === 'inner_left',
            );
            $boxes[] = new AxisAlignedBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ);
        }

        return BlockCollisionShape::fromBoxes($boxes);
    }

    /** @return array{float, float, float, float} */
    private static function stairOuterQuarter(int $direction, bool $left): array
    {
        return match ([$direction, $left]) {
            [0, true] => [0.5, 0.0, 1.0, 0.5],
            [0, false] => [0.5, 0.5, 1.0, 1.0],
            [1, true] => [0.0, 0.5, 0.5, 1.0],
            [1, false] => [0.0, 0.0, 0.5, 0.5],
            [2, true] => [0.5, 0.5, 1.0, 1.0],
            [2, false] => [0.0, 0.5, 0.5, 1.0],
            [3, true] => [0.0, 0.0, 0.5, 0.5],
            default => [0.5, 0.0, 1.0, 0.5],
        };
    }

    /** @return array{float, float, float, float} */
    private static function stairInnerQuarter(int $direction, bool $left): array
    {
        return match ([$direction, $left]) {
            [0, true] => [0.0, 0.0, 0.5, 0.5],
            [0, false] => [0.0, 0.5, 0.5, 1.0],
            [1, true] => [0.5, 0.5, 1.0, 1.0],
            [1, false] => [0.5, 0.0, 1.0, 0.5],
            [2, true] => [0.5, 0.0, 1.0, 0.5],
            [2, false] => [0.0, 0.0, 0.5, 0.5],
            [3, true] => [0.0, 0.5, 0.5, 1.0],
            default => [0.5, 0.5, 1.0, 1.0],
        };
    }

    /** @param array<string, int|string> $properties */
    private static function connected(string $identifier, array $properties): BlockCollisionShape
    {
        $isFence = BlockTagRegistry::contains(BlockTag::FENCE, $identifier);
        $height = $isFence ? 1.5 : 1.0;
        $halfWidth = $isFence ? 0.125 : 0.0625;
        $min = 0.5 - $halfWidth;
        $max = 0.5 + $halfWidth;
        $boxes = [new AxisAlignedBox($min, 0.0, $min, $max, $height, $max)];
        if (($properties['minecraft:connection_north'] ?? 0) === 1) {
            $boxes[] = new AxisAlignedBox($min, 0.0, 0.0, $max, $height, 0.5);
        }
        if (($properties['minecraft:connection_south'] ?? 0) === 1) {
            $boxes[] = new AxisAlignedBox($min, 0.0, 0.5, $max, $height, 1.0);
        }
        if (($properties['minecraft:connection_west'] ?? 0) === 1) {
            $boxes[] = new AxisAlignedBox(0.0, 0.0, $min, 0.5, $height, $max);
        }
        if (($properties['minecraft:connection_east'] ?? 0) === 1) {
            $boxes[] = new AxisAlignedBox(0.5, 0.0, $min, 1.0, $height, $max);
        }

        return BlockCollisionShape::fromBoxes($boxes);
    }

    /** @param array<string, int|string> $properties */
    private static function wall(array $properties): BlockCollisionShape
    {
        $boxes = [];
        if (($properties['wall_post_bit'] ?? 0) === 1) {
            $boxes[] = new AxisAlignedBox(0.25, 0.0, 0.25, 0.75, 1.5, 0.75);
        }
        foreach (['north', 'east', 'south', 'west'] as $direction) {
            if (($properties['wall_connection_type_' . $direction] ?? 'none') === 'none') {
                continue;
            }
            $boxes[] = match ($direction) {
                'north' => new AxisAlignedBox(0.25, 0.0, 0.0, 0.75, 1.5, 0.5),
                'east' => new AxisAlignedBox(0.5, 0.0, 0.25, 1.0, 1.5, 0.75),
                'south' => new AxisAlignedBox(0.25, 0.0, 0.5, 0.75, 1.5, 1.0),
                'west' => new AxisAlignedBox(0.0, 0.0, 0.25, 0.5, 1.5, 0.75),
            };
        }
        if ($boxes === []) {
            $boxes[] = new AxisAlignedBox(0.25, 0.0, 0.25, 0.75, 1.5, 0.75);
        }

        return BlockCollisionShape::fromBoxes($boxes);
    }

    /** @param array<string, int|string> $properties */
    private static function button(array $properties): BlockCollisionShape
    {
        $pressed = ($properties['button_pressed_bit'] ?? 0) === 1;
        $depth = $pressed ? 1.0 / 16.0 : 2.0 / 16.0;

        return match ($properties['facing_direction'] ?? 1) {
            0 => self::box(5.0 / 16.0, 1.0 - $depth, 6.0 / 16.0, 11.0 / 16.0, 1.0, 10.0 / 16.0),
            1 => self::box(5.0 / 16.0, 0.0, 6.0 / 16.0, 11.0 / 16.0, $depth, 10.0 / 16.0),
            2 => self::box(5.0 / 16.0, 6.0 / 16.0, 0.0, 11.0 / 16.0, 10.0 / 16.0, $depth),
            3 => self::box(5.0 / 16.0, 6.0 / 16.0, 1.0 - $depth, 11.0 / 16.0, 10.0 / 16.0, 1.0),
            4 => self::box(0.0, 6.0 / 16.0, 5.0 / 16.0, $depth, 10.0 / 16.0, 11.0 / 16.0),
            5 => self::box(1.0 - $depth, 6.0 / 16.0, 5.0 / 16.0, 1.0, 10.0 / 16.0, 11.0 / 16.0),
            default => BlockCollisionShape::empty(),
        };
    }

    private static function box(
        float $minX,
        float $minY,
        float $minZ,
        float $maxX,
        float $maxY,
        float $maxZ,
    ): BlockCollisionShape {
        return BlockCollisionShape::fromBoxes([
            new AxisAlignedBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ),
        ]);
    }
}
