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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Runtime\ManagedWorldRuntime;
use Bedriox\Server\Runtime\OpenedWorld;
use Bedriox\Server\Runtime\RuntimeWorldActions;
use Bedriox\Server\Runtime\WorldRuntimeManager;
use Bedriox\Server\Runtime\WorldStorageRuntime;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use LogicException;
use PHPUnit\Framework\TestCase;

final class RuntimeWorldActionsTest extends TestCase
{
    public function testSpatialActionsUseTheExactDimensionWorldHandle(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $runtimeActions = new RuntimeWorldActions($states);
        $actions = $runtimeActions->actions();
        $overworldHandle = new ApiWorld(
            'world',
            1,
            $actions,
            DimensionType::OVERWORLD,
        );
        $netherHandle = new ApiWorld(
            'world',
            1,
            $actions,
            DimensionType::NETHER,
        );

        $overworld = self::runtime($overworldHandle, $states);
        $nether = self::runtime($netherHandle, $states);
        $worlds = new WorldRuntimeManager(
            'world',
            new WorldStorageRuntime([$overworld, $nether]),
            actions: $actions,
        );
        $runtimeActions->attach($worlds);
        $stone = $states->internalId(CanonicalBlockState::from('minecraft:stone'));
        $dirt = $states->internalId(CanonicalBlockState::from('minecraft:dirt'));
        $overworld->opened->world->setBlockState(0, 64, 0, $stone);
        $nether->opened->world->setBlockState(0, 64, 0, $dirt);

        self::assertSame(
            'minecraft:stone',
            $overworldHandle->getBlock(new BlockPosition(0, 64, 0))->identifier,
        );
        self::assertSame(
            'minecraft:dirt',
            $netherHandle->getBlock(new BlockPosition(0, 64, 0))->identifier,
        );
    }

    public function testWeatherIsRejectedOutsideTheOverworld(): void
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $runtimeActions = new RuntimeWorldActions($states);
        $actions = $runtimeActions->actions();
        $overworldHandle = new ApiWorld('world', 1, $actions);
        $netherHandle = new ApiWorld(
            'world',
            1,
            $actions,
            DimensionType::NETHER,
        );
        $worlds = new WorldRuntimeManager(
            'world',
            new WorldStorageRuntime([
                self::runtime($overworldHandle, $states),
                self::runtime($netherHandle, $states),
            ]),
            actions: $actions,
        );
        $runtimeActions->attach($worlds);

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Weather is only available in an Overworld dimension.');
        $netherHandle->getWeather();
    }

    private static function runtime(ApiWorld $handle, BlockStateRegistry $states): ManagedWorldRuntime
    {
        $metadata = new WorldMetadata('world', 42);
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            $metadata,
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
            dimension: $handle->getDimensionType(),
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            worldId: 'world',
            dimension: $handle->getDimensionType(),
        );

        return new ManagedWorldRuntime(
            $handle,
            new OpenedWorld(
                $world,
                new WorldData($metadata, 'flat', new SpawnPosition(0, 64, 0)),
            ),
            $simulation,
            new FixedRateWorldLoop($simulation, new SystemSimulationClock()),
        );
    }
}
