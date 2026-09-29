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
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Environment\Fluid\FluidCell;
use Bedriox\Server\World\Environment\Fluid\FluidFlowPlanner;
use Bedriox\Server\World\Environment\Fluid\FluidMutationType;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\Environment\Fluid\FluidWorldView;
use PHPUnit\Framework\TestCase;

final class FluidFlowPlannerTest extends TestCase
{
    public function testCanonicalStateRoundTripsEveryLiquidDepth(): void
    {
        foreach (FluidType::cases() as $type) {
            for ($depth = 0; $depth <= 7; ++$depth) {
                foreach ([false, true] as $falling) {
                    $state = new FluidState($type, $depth, $falling);
                    self::assertEquals($state, FluidState::fromCanonical($state->canonicalState()));
                }
            }
        }
        self::assertNull(FluidState::fromCanonical(CanonicalBlockState::from('minecraft:stone')));
    }

    public function testSourceFlowsDownAndUsesOnlyCanonicalStates(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 63, 0), FluidCell::air());
        $this->surroundHorizontally($world, $origin, self::solid());

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertCount(1, $plan->mutations);
        self::assertSame(FluidMutationType::SET_FLUID, $plan->mutations[0]->type);
        self::assertSame('minecraft:water', $plan->mutations[0]->state->identifier());
        self::assertSame(8, $plan->mutations[0]->state->properties()['liquid_depth']);
    }

    public function testHorizontalFlowSelectsNearestDownwardOpening(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 63, 0), self::solid());
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$x, $z]) {
            $world->put(new BlockPosition($x, 64, $z), FluidCell::air());
            $world->put(new BlockPosition($x, 63, $z), self::solid());
        }
        $world->put(new BlockPosition(0, 63, -1), FluidCell::air());

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertCount(1, $plan->mutations);
        self::assertTrue($plan->mutations[0]->position->equals(new BlockPosition(0, 64, -1)));
        self::assertSame(1, $plan->mutations[0]->state->properties()['liquid_depth']);
    }

    public function testTwoWaterSourcesCreateSourceAboveSolidSupport(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(new FluidState(FluidType::WATER, 2, false)));
        $world->put(new BlockPosition(-1, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(1, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 64, -1), self::solid());
        $world->put(new BlockPosition(0, 64, 1), self::solid());
        $world->put(new BlockPosition(0, 63, 0), self::solid());
        $world->put(new BlockPosition(0, 65, 0), FluidCell::air());

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertSame(0, $plan->mutations[0]->state->properties()['liquid_depth']);
    }

    public function testLavaHardensBeforeItCanSpread(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::LAVA)));
        $world->put(new BlockPosition(1, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertCount(1, $plan->mutations);
        self::assertSame(FluidMutationType::FORM_BLOCK, $plan->mutations[0]->type);
        self::assertSame('minecraft:obsidian', $plan->mutations[0]->state->identifier());
    }

    public function testLavaFormsBasaltOverSoulSoilNextToBlueIce(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::LAVA)));
        $world->put(new BlockPosition(0, 63, 0), new FluidCell(
            CanonicalBlockState::from('minecraft:soul_soil'),
            false,
            true,
        ));
        $world->put(new BlockPosition(1, 64, 0), new FluidCell(
            CanonicalBlockState::from('minecraft:blue_ice'),
            false,
            true,
        ));

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertCount(1, $plan->mutations);
        self::assertSame('minecraft:basalt', $plan->mutations[0]->state->identifier());
    }

    public function testHorizontalFlowDoesNotReplaceAFullerExistingFlow(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 63, 0), self::solid());
        $target = new BlockPosition(1, 64, 0);
        $world->put($target, self::fluid(new FluidState(FluidType::WATER, 0, true)));
        $world->put(new BlockPosition(1, 63, 0), FluidCell::air());
        $world->put(new BlockPosition(-1, 64, 0), self::solid());
        $world->put(new BlockPosition(0, 64, -1), self::solid());
        $world->put(new BlockPosition(0, 64, 1), self::solid());

        $plan = new FluidFlowPlanner()->plan($world, $origin);

        self::assertSame([], $plan->mutations);
    }

    public function testUnavailableCellsAreDeferredAndNeverLoadedByPlanner(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::WATER)));

        $plan = new FluidFlowPlanner(16)->plan($world, $origin);

        self::assertNotEmpty($plan->deferredPositions);
        self::assertLessThanOrEqual(16, $plan->visitedCells);
        self::assertSame([], $plan->mutations);
    }

    private function surroundHorizontally(ArrayFluidWorldView $world, BlockPosition $origin, FluidCell $cell): void
    {
        foreach ([[0, -1], [0, 1], [-1, 0], [1, 0]] as [$x, $z]) {
            $world->put(new BlockPosition($origin->x + $x, $origin->y, $origin->z + $z), $cell);
        }
    }

    private static function fluid(FluidState $state): FluidCell
    {
        return new FluidCell($state->canonicalState(), true, false);
    }

    private static function solid(): FluidCell
    {
        return new FluidCell(CanonicalBlockState::from('minecraft:stone'), false, true);
    }
}

final class ArrayFluidWorldView implements FluidWorldView
{
    /** @var array<string, FluidCell> */
    private array $cells = [];

    public function put(BlockPosition $position, FluidCell $cell): void
    {
        $this->cells[$this->key($position)] = $cell;
    }

    public function cellAt(BlockPosition $position): ?FluidCell
    {
        return $this->cells[$this->key($position)] ?? null;
    }

    private function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
