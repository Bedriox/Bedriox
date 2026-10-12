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

namespace Bedriox\Server\Tests\World\Environment\Fluid;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Environment\Fluid\FluidCell;
use Bedriox\Server\World\Environment\Fluid\FluidCurrentResolver;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\Environment\Fluid\FluidWorldView;
use PHPUnit\Framework\TestCase;

final class FluidCurrentResolverTest extends TestCase
{
    public function testDepthGradientPushesAnEntityTowardTheDeeperFlow(): void
    {
        $world = new TestFluidCurrentWorld();
        $world->put(new BlockPosition(0, 64, 0), self::water(1));
        $world->put(new BlockPosition(-1, 64, 0), self::water(0));
        $world->put(new BlockPosition(1, 64, 0), self::water(2));

        $current = (new FluidCurrentResolver($world))->currentAt(new Position(0.5, 64.0, 0.5), 0.25);

        self::assertGreaterThan(0.99, $current->x);
        self::assertSame(0.0, $current->y);
        self::assertEqualsWithDelta(0.0, $current->z, 0.000_001);
    }

    public function testStillSourceDoesNotPushAnEntity(): void
    {
        $world = new TestFluidCurrentWorld();
        $world->put(new BlockPosition(0, 64, 0), self::water(0));
        foreach ([[-1, 0], [1, 0], [0, -1], [0, 1]] as [$x, $z]) {
            $world->put(new BlockPosition($x, 64, $z), self::water(0));
        }

        $current = (new FluidCurrentResolver($world))->currentAt(new Position(0.5, 64.0, 0.5), 1.0);

        self::assertSame(0.0, $current->x);
        self::assertSame(0.0, $current->y);
        self::assertSame(0.0, $current->z);
    }

    public function testUnavailableNeighborStopsAtTheLoadedBoundary(): void
    {
        $world = new TestFluidCurrentWorld(defaultAir: false);
        $world->put(new BlockPosition(0, 64, 0), self::water(1));

        $current = (new FluidCurrentResolver($world))->currentAt(new Position(0.5, 64.0, 0.5), 1.0);

        self::assertSame(0.0, $current->x);
        self::assertSame(0.0, $current->y);
        self::assertSame(0.0, $current->z);
    }

    private static function water(int $depth): FluidCell
    {
        return new FluidCell((new FluidState(FluidType::WATER, $depth, false))->canonicalState(), true, false);
    }
}

final class TestFluidCurrentWorld implements FluidWorldView
{
    /** @var array<string, FluidCell> */
    private array $cells = [];

    public function __construct(private readonly bool $defaultAir = true) {}

    public function put(BlockPosition $position, FluidCell $cell): void
    {
        $this->cells[$position->x . ':' . $position->y . ':' . $position->z] = $cell;
    }

    public function cellAt(BlockPosition $position): ?FluidCell
    {
        return $this->cells[$position->x . ':' . $position->y . ':' . $position->z]
            ?? ($this->defaultAir
                ? new FluidCell(CanonicalBlockState::from('minecraft:air'), true, false)
                : null);
    }
}
