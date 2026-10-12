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

namespace Bedriox\Server\Tests\Gameplay\Block\Rail;

use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\BlockTag;
use Bedriox\Api\World\BlockTagRegistry;
use Bedriox\Api\World\Rail\RailSegment;
use Bedriox\Api\World\Rail\RailShape;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\Rail\RailBlockStateResolver;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class RailBlockStateResolverTest extends TestCase
{
    private BlockStateRegistry $states;

    private RailBlockStateResolver $resolver;

    protected function setUp(): void
    {
        $this->states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $this->resolver = new RailBlockStateResolver($this->states);
    }

    public function testRailTagContainsEveryCurrentRailFamily(): void
    {
        self::assertSame([
            'minecraft:activator_rail',
            'minecraft:detector_rail',
            'minecraft:golden_rail',
            'minecraft:rail',
        ], BlockTagRegistry::members(BlockTag::RAIL));
        self::assertFalse(BlockTagRegistry::contains(BlockTag::RAIL, 'minecraft:oak_planks'));
    }

    public function testCanonicalDirectionValuesMapToEveryRailShape(): void
    {
        foreach (RailShape::cases() as $shape) {
            $state = CanonicalBlockState::from('minecraft:rail', ['rail_direction' => $shape->value]);
            $segment = $this->resolver->segment(new ApiBlockPosition(2, 64, -3), $state);

            self::assertSame($shape, $segment->shape);
            self::assertCount(2, $segment->endpoints());
        }
    }

    public function testPlacementUsesPlayerAxisWithoutNeighbors(): void
    {
        $state = self::rail();
        $empty = static fn(BlockPosition $_position): ?CanonicalBlockState => null;

        self::assertSame(RailShape::NORTH_SOUTH, $this->shapeOf(
            $this->resolver->resolvePlacement($state, new BlockPosition(0, 64, 0), 0.0, $empty),
        ));
        self::assertSame(RailShape::EAST_WEST, $this->shapeOf(
            $this->resolver->resolvePlacement($state, new BlockPosition(0, 64, 0), 90.0, $empty),
        ));
    }

    public function testPlacementConnectsStraightCurvedAndAscendingNeighbors(): void
    {
        $origin = new BlockPosition(0, 64, 0);

        self::assertSame(RailShape::EAST_WEST, $this->resolvedShape($origin, [
            '-1:64:0' => self::rail(),
            '1:64:0' => self::rail(),
        ]));
        self::assertSame(RailShape::SOUTH_EAST, $this->resolvedShape($origin, [
            '0:64:1' => self::rail(),
            '1:64:0' => self::rail(),
        ]));
        self::assertSame(RailShape::ASCENDING_EAST, $this->resolvedShape($origin, [
            '-1:64:0' => self::rail(),
            '1:65:0' => self::rail(),
        ]));
    }

    public function testStraightOnlyRailsNeverResolveCurvesAndPreserveActivationBit(): void
    {
        $origin = new BlockPosition(0, 64, 0);
        $neighbors = [
            '0:64:1' => self::rail(),
            '1:64:0' => self::rail(),
        ];
        $state = CanonicalBlockState::from('minecraft:golden_rail', [
            'rail_data_bit' => 1,
            'rail_direction' => 0,
        ]);
        $resolved = $this->states->state($this->resolver->resolvePlacement(
            $state,
            $origin,
            90.0,
            self::stateLookup($neighbors),
        ));

        self::assertSame(RailShape::EAST_WEST->value, $resolved->properties()['rail_direction']);
        self::assertSame(1, $resolved->properties()['rail_data_bit']);
    }

    public function testSupportIncludesAscendingHighSide(): void
    {
        $origin = new BlockPosition(4, 70, 9);
        $ascending = CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => RailShape::ASCENDING_NORTH->value,
        ]);

        self::assertTrue($this->resolver->isSupported(
            $origin,
            $ascending,
            static fn(BlockPosition $position): bool => in_array(
                self::key($position),
                ['4:69:9', '4:70:8'],
                true,
            ),
        ));
        self::assertFalse($this->resolver->isSupported(
            $origin,
            $ascending,
            static fn(BlockPosition $position): bool => self::key($position) === '4:69:9',
        ));
    }

    public function testAffectedNeighborhoodIsBoundedAndIncludesThreeVerticalLevels(): void
    {
        $affected = $this->resolver->affectedPositions(new BlockPosition(0, 64, 0));

        self::assertCount(13, $affected);
        self::assertContains('0:64:0', array_map(self::key(...), $affected));
        self::assertContains('1:65:0', array_map(self::key(...), $affected));
        self::assertContains('0:63:-1', array_map(self::key(...), $affected));
    }

    public function testInvalidRailStatesAndSegmentsFailDeterministically(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new RailSegment(
            new ApiBlockPosition(0, 0, 0),
            'minecraft:golden_rail',
            RailShape::SOUTH_EAST,
        );
    }

    /** @param array<string, CanonicalBlockState> $neighbors */
    private function resolvedShape(BlockPosition $origin, array $neighbors): RailShape
    {
        return $this->shapeOf($this->resolver->resolvePlacement(
            self::rail(),
            $origin,
            0.0,
            self::stateLookup($neighbors),
        ));
    }

    private function shapeOf(\Bedriox\Server\World\Block\InternalBlockStateId $state): RailShape
    {
        $value = $this->states->state($state)->properties()['rail_direction'] ?? null;
        self::assertIsInt($value);

        return RailShape::from($value);
    }

    /** @param array<string, CanonicalBlockState> $states */
    private static function stateLookup(array $states): \Closure
    {
        return static fn(BlockPosition $position): ?CanonicalBlockState => $states[self::key($position)] ?? null;
    }

    private static function rail(): CanonicalBlockState
    {
        return CanonicalBlockState::from('minecraft:rail', ['rail_direction' => 0]);
    }

    private static function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
