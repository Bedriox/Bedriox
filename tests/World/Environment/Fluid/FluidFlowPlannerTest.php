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
use Bedriox\Server\World\Environment\Fluid\FluidFlowBatchPlanner;
use Bedriox\Server\World\Environment\Fluid\FluidFlowPlanner;
use Bedriox\Server\World\Environment\Fluid\FluidMutation;
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

    public function testHorizontalFlowReroutesWhenThePreferredOpeningIsBlocked(): void
    {
        $world = new ArrayFluidWorldView();
        $origin = new BlockPosition(0, 64, 0);
        $north = new BlockPosition(0, 64, -1);
        $east = new BlockPosition(1, 64, 0);
        $world->put($origin, self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 63, 0), self::solid());
        foreach ([$north, $east, new BlockPosition(0, 64, 1), new BlockPosition(-1, 64, 0)] as $target) {
            $world->put($target, FluidCell::air());
            $world->put(new BlockPosition($target->x, 63, $target->z), self::solid());
        }
        $world->put(new BlockPosition(0, 63, -1), FluidCell::air());

        $initial = new FluidFlowPlanner()->plan($world, $origin);
        self::assertCount(1, $initial->mutations);
        self::assertTrue($initial->mutations[0]->position->equals($north));

        $world->put($north, self::solid());
        $world->put(new BlockPosition(1, 63, 0), FluidCell::air());
        $rerouted = new FluidFlowPlanner()->plan($world, $origin);

        self::assertCount(1, $rerouted->mutations);
        self::assertTrue($rerouted->mutations[0]->position->equals($east));
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

    public function testOpenedCellBelowWaterRefillsInTheSamePlannedFrontier(): void
    {
        $world = new ArrayFluidWorldView();
        $opening = new BlockPosition(0, 64, 0);
        $world->put($opening, FluidCell::air());
        $world->put(new BlockPosition(0, 65, 0), self::fluid(FluidState::source(FluidType::WATER)));

        $batch = new FluidFlowBatchPlanner(new FluidFlowPlanner())->plan($world, [$opening]);

        self::assertCount(1, $batch->mutations);
        self::assertTrue($batch->mutations[0]->position->equals($opening));
        self::assertSame('minecraft:water', $batch->mutations[0]->state->identifier());
        self::assertSame(8, $batch->mutations[0]->state->properties()['liquid_depth']);
    }

    public function testOpenedCellBetweenTwoWaterSourcesBecomesASource(): void
    {
        $world = new ArrayFluidWorldView();
        $opening = new BlockPosition(0, 64, 0);
        $world->put($opening, FluidCell::air());
        $world->put(new BlockPosition(-1, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(1, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 64, -1), self::solid());
        $world->put(new BlockPosition(0, 64, 1), self::solid());

        $batch = new FluidFlowBatchPlanner(new FluidFlowPlanner())->plan($world, [$opening]);

        self::assertCount(1, $batch->mutations);
        self::assertSame(0, $batch->mutations[0]->state->properties()['liquid_depth']);
    }

    public function testWaterFlowHardensAdjacentLavaInTheSameBatch(): void
    {
        $world = new ArrayFluidWorldView();
        $water = new BlockPosition(0, 64, 0);
        $flowTarget = new BlockPosition(1, 64, 0);
        $lava = new BlockPosition(2, 64, 0);
        $world->put($water, self::fluid(FluidState::source(FluidType::WATER)));
        $world->put(new BlockPosition(0, 63, 0), self::solid());
        $world->put($flowTarget, FluidCell::air());
        $world->put(new BlockPosition(1, 63, 0), FluidCell::air());
        $world->put($lava, self::fluid(FluidState::source(FluidType::LAVA)));
        $world->put(new BlockPosition(-1, 64, 0), self::solid());
        $world->put(new BlockPosition(0, 64, -1), self::solid());
        $world->put(new BlockPosition(0, 64, 1), self::solid());

        $batch = new FluidFlowBatchPlanner(new FluidFlowPlanner())->plan($world, [$water]);
        $states = [];
        foreach ($batch->mutations as $mutation) {
            $states[$mutation->position->x . ':' . $mutation->position->y . ':' . $mutation->position->z]
                = $mutation->state->identifier();
        }

        self::assertSame('minecraft:water', $states['1:64:0']);
        self::assertSame('minecraft:obsidian', $states['2:64:0']);
    }

    public function testBatchOutcomeDoesNotDependOnOriginOrder(): void
    {
        $world = new ArrayFluidWorldView();
        $left = new BlockPosition(-1, 64, 0);
        $right = new BlockPosition(1, 64, 0);
        $center = new BlockPosition(0, 64, 0);
        foreach ([$left, $right] as $source) {
            $world->put($source, self::fluid(FluidState::source(FluidType::WATER)));
            $world->put(new BlockPosition($source->x, 63, 0), self::solid());
        }
        $world->put($center, FluidCell::air());
        $world->put(new BlockPosition(0, 63, 0), FluidCell::air());
        foreach ([[-1, -1], [-1, 1], [-2, 0], [1, -1], [1, 1], [2, 0]] as [$x, $z]) {
            $world->put(new BlockPosition($x, 64, $z), self::solid());
        }

        $planner = new FluidFlowBatchPlanner(new FluidFlowPlanner());
        $forward = $planner->plan($world, [$left, $right]);
        $reverse = $planner->plan($world, [$right, $left]);

        self::assertEquals($forward->mutations, $reverse->mutations);
    }

    public function testRepeatedBoundedFrontiersConvergeWithoutOscillation(): void
    {
        $world = new ArrayFluidWorldView();
        $world->put(new BlockPosition(0, 64, 0), self::fluid(FluidState::source(FluidType::WATER)));
        for ($x = 0; $x <= 3; ++$x) {
            $world->put(new BlockPosition($x, 63, 0), self::solid());
            $world->put(new BlockPosition($x, 64, -1), self::solid());
            $world->put(new BlockPosition($x, 64, 1), self::solid());
            if ($x > 0) {
                $world->put(new BlockPosition($x, 64, 0), FluidCell::air());
            }
        }
        $world->put(new BlockPosition(-1, 64, 0), self::solid());
        $world->put(new BlockPosition(4, 64, 0), self::solid());
        $planner = new FluidFlowBatchPlanner(new FluidFlowPlanner());

        for ($pass = 0; $pass < 8; ++$pass) {
            $origins = [];
            for ($x = 0; $x <= 3; ++$x) {
                $position = new BlockPosition($x, 64, 0);
                if ($world->cellAt($position)?->fluid !== null) {
                    $origins[] = $position;
                }
            }
            foreach ($planner->plan($world, $origins)->mutations as $mutation) {
                $world->apply($mutation);
            }
        }
        $settled = $world->canonicalKeys();
        for ($pass = 0; $pass < 4; ++$pass) {
            $origins = [];
            for ($x = 0; $x <= 3; ++$x) {
                $position = new BlockPosition($x, 64, 0);
                if ($world->cellAt($position)?->fluid !== null) {
                    $origins[] = $position;
                }
            }
            foreach ($planner->plan($world, $origins)->mutations as $mutation) {
                $world->apply($mutation);
            }
        }

        self::assertSame($settled, $world->canonicalKeys());
        self::assertSame(3, $world->cellAt(new BlockPosition(3, 64, 0))?->fluid?->depth);
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

    public function apply(FluidMutation $mutation): void
    {
        $this->put($mutation->position, match ($mutation->type) {
            FluidMutationType::SET_FLUID => new FluidCell($mutation->state, true, false),
            FluidMutationType::REMOVE_FLUID => FluidCell::air(),
            FluidMutationType::FORM_BLOCK => new FluidCell($mutation->state, false, true),
        });
    }

    /** @return array<string, string> */
    public function canonicalKeys(): array
    {
        $keys = [];
        foreach ($this->cells as $position => $cell) {
            $keys[$position] = $cell->block->canonicalKey();
        }
        ksort($keys, SORT_STRING);

        return $keys;
    }

    private function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
