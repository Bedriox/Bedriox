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

namespace Bedriox\Server\Tests\World\Collision;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\StateAwareBlockCollisionResolver;
use PHPUnit\Framework\TestCase;

final class StateAwareBlockCollisionResolverTest extends TestCase
{
    public function testSlabAndPressurePlateShapesDoNotBehaveAsFullCubes(): void
    {
        $slab = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:oak_slab',
            ['minecraft:vertical_half' => 'bottom'],
        ));
        self::assertNotNull($slab);
        self::assertTrue($slab->intersectsAt(new AxisAlignedBox(0.1, 0.1, 0.1, 0.9, 0.4, 0.9), 0, 0, 0));
        self::assertFalse($slab->intersectsAt(new AxisAlignedBox(0.1, 0.6, 0.1, 0.9, 0.9, 0.9), 0, 0, 0));

        $plate = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:stone_pressure_plate',
            ['redstone_signal' => 0],
        ));
        self::assertNotNull($plate);
        self::assertTrue($plate->isEmpty());
    }

    public function testConnectedFenceExtendsTowardItsNeighborsAndAboveOneBlock(): void
    {
        $fence = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:oak_fence',
            [
                'minecraft:connection_east' => 0,
                'minecraft:connection_north' => 1,
                'minecraft:connection_south' => 0,
                'minecraft:connection_west' => 0,
            ],
        ));
        self::assertNotNull($fence);
        self::assertTrue($fence->intersectsAt(new AxisAlignedBox(0.45, 1.2, 0.05, 0.55, 1.4, 0.2), 0, 0, 0));
        self::assertFalse($fence->intersectsAt(new AxisAlignedBox(0.05, 0.2, 0.05, 0.2, 1.4, 0.2), 0, 0, 0));
    }

    public function testBedUsesItsNineSixteenthsHighCollisionBox(): void
    {
        $bed = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:bed',
            ['direction' => 0, 'head_piece_bit' => 1, 'occupied_bit' => 0],
        ));
        self::assertNotNull($bed);
        self::assertTrue($bed->intersectsAt(new AxisAlignedBox(0.1, 0.1, 0.1, 0.9, 0.55, 0.9), 0, 0, 0));
        self::assertFalse($bed->intersectsAt(new AxisAlignedBox(0.1, 0.57, 0.1, 0.9, 0.95, 0.9), 0, 0, 0));
    }

    public function testStairLeavesTheUpperRearQuarterOpen(): void
    {
        $stair = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:oak_stairs',
            ['minecraft:corner' => 'none', 'upside_down_bit' => 0, 'weirdo_direction' => 0],
        ));
        self::assertNotNull($stair);
        self::assertTrue($stair->intersectsAt(new AxisAlignedBox(0.6, 0.6, 0.2, 0.9, 0.9, 0.4), 0, 0, 0));
        self::assertFalse($stair->intersectsAt(new AxisAlignedBox(0.1, 0.6, 0.2, 0.4, 0.9, 0.4), 0, 0, 0));
    }

    public function testDoorCollisionRotatesWithItsDirectionHingeAndOpenState(): void
    {
        $closed = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:wooden_door',
            [
                'door_hinge_bit' => 0,
                'minecraft:cardinal_direction' => 'south',
                'open_bit' => 0,
                'upper_block_bit' => 0,
            ],
        ));
        self::assertNotNull($closed);
        self::assertTrue($closed->intersectsAt(new AxisAlignedBox(0.01, 0.0, 0.4, 0.1, 1.0, 0.6), 0, 0, 0));
        self::assertFalse($closed->intersectsAt(new AxisAlignedBox(0.3, 0.0, 0.4, 0.9, 1.0, 0.6), 0, 0, 0));

        $openLeft = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:wooden_door',
            [
                'door_hinge_bit' => 0,
                'minecraft:cardinal_direction' => 'south',
                'open_bit' => 1,
                'upper_block_bit' => 1,
            ],
        ));
        self::assertNotNull($openLeft);
        self::assertTrue($openLeft->intersectsAt(new AxisAlignedBox(0.4, 0.0, 0.01, 0.6, 1.0, 0.1), 0, 0, 0));
        self::assertFalse($openLeft->intersectsAt(new AxisAlignedBox(0.4, 0.0, 0.3, 0.6, 1.0, 0.9), 0, 0, 0));

        $openRight = StateAwareBlockCollisionResolver::resolve(CanonicalBlockState::from(
            'minecraft:iron_door',
            [
                'door_hinge_bit' => 1,
                'minecraft:cardinal_direction' => 'south',
                'open_bit' => 1,
                'upper_block_bit' => 0,
            ],
        ));
        self::assertNotNull($openRight);
        self::assertTrue($openRight->intersectsAt(new AxisAlignedBox(0.4, 0.0, 0.9, 0.6, 1.0, 0.99), 0, 0, 0));
        self::assertFalse($openRight->intersectsAt(new AxisAlignedBox(0.4, 0.0, 0.1, 0.6, 1.0, 0.7), 0, 0, 0));
    }
}
