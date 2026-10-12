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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class FluidSimulationRegressionTest extends TestCase
{
    public function testRemovingBlockBelowWaterSchedulesTheOpenedCellForRefill(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('underwater-cavity-regression', 19),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $world->chunk(new ChunkPosition(0, 0));
        $opening = new BlockPosition(0, 64, 0);
        $world->setBlockState(0, 64, 0, $generation->state('minecraft:stone'));
        $world->setBlockState(0, 65, 0, $states->internalId(FluidState::source(FluidType::WATER)->canonicalState()));
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $flat,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
        );

        (new ReflectionMethod(WorldSimulation::class, 'setBlockStateAndSchedule'))->invoke(
            $simulation,
            $opening,
            $flat->air,
        );
        $simulation->tick();
        $simulation->tick();

        $refilled = FluidState::fromCanonical($states->state($world->blockStateAt(0, 64, 0)));
        self::assertSame(FluidType::WATER, $refilled?->type);
        self::assertTrue($refilled->falling);
    }
}
