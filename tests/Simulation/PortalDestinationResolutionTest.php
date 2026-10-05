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

use Bedriox\Api\World\WorldDimension;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Portal\NetherPortalSystem;
use Bedriox\Server\Gameplay\Portal\PortalAxis;
use Bedriox\Server\Gameplay\Portal\PortalDestinationPlanner;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Event\EndPortalTransferRequested;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class PortalDestinationResolutionTest extends TestCase
{
    public function testFlintAndSteelIgnitesAValidFrameThroughAuthoritativePlacement(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $portals = new NetherPortalSystem($world, $states);
        $portals->build(new BlockPosition(0, 64, 0), PortalAxis::X);
        $air = $states->internalId(CanonicalBlockState::from('minecraft:air'));
        foreach ([0, 1] as $x) {
            foreach ([64, 65, 66] as $y) {
                $world->setBlockState($x, $y, 0, $air);
            }
        }
        $data = BedrockDataSet::bundled();
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $simulation = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 2.5),
            blockWorld: $world,
            blockPalette: FixedFlatBlockPalette::fromRegistry($states),
            itemCatalog: $items,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette(
                $states,
                GenerationBlockPalette::fromRegistry($states),
            ),
            worldId: 'portal-target',
            dimension: WorldDimension::NETHER,
        );
        $commands = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('identity', 'PortalTester'),
            'portal-target',
            new Position(0.5, 64.0, 2.5),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:flint_and_steel', 1)),
            ], 0),
            1,
            1,
            dimension: WorldDimension::NETHER,
        );
        self::assertTrue($simulation->enqueue($commands->join(
            'session',
            'identity',
            'PortalTester',
            bootstrap: $bootstrap,
        )));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'session',
            1,
            new BlockPosition(0, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $simulation->tick();

        self::assertSame('minecraft:portal', $states->state($world->blockStateAt(0, 64, 0))->identifier());
        self::assertSame(1, $simulation->authoritativePlayer('identity')?->inventory->selectedStack()?->damage);
    }

    public function testResolverFindsNearestPortalFromLoadedPalettes(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $portals = new NetherPortalSystem($world, $states);
        $portals->build(new BlockPosition(8, 64, 0), PortalAxis::X);
        $portals->build(new BlockPosition(2, 64, 0), PortalAxis::Z);
        $plan = (new PortalDestinationPlanner())->plan(
            WorldDimension::OVERWORLD,
            new Position(0.0, 64.0, 0.0),
            PortalAxis::X,
        );

        $destination = $simulation->resolvePortalDestination($plan);

        self::assertNotNull($destination);
        self::assertSame([3.5, 64.0, 1.0], [$destination->x, $destination->y, $destination->z]);
    }

    public function testEndPortalContactRequestsAnImmediatePairedDimensionTransfer(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('end-transfer', 90),
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
        );
        $world->setBlockState(
            0,
            64,
            0,
            $states->internalId(CanonicalBlockState::from('minecraft:end_portal')),
        );
        $simulation = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 0.5),
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette(
                $states,
                GenerationBlockPalette::fromRegistry($states),
            ),
            worldId: 'end-transfer',
        );
        $commands = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('end-identity', 'EndTester'),
            'end-transfer',
            new Position(0.5, 64.0, 0.5),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            1,
            1,
        );
        self::assertTrue($simulation->enqueue($commands->join(
            'end-session',
            'end-identity',
            'EndTester',
            bootstrap: $bootstrap,
        )));

        $events = $simulation->tick()->events;
        $requests = array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EndPortalTransferRequested,
        ));
        self::assertCount(1, $requests);
        self::assertSame(WorldDimension::OVERWORLD, $requests[0]->sourceDimension);
        self::assertSame(WorldDimension::END, $requests[0]->targetDimension);
    }

    public function testEnderEyeInsertionConsumesOneAndActivatesTheCompleteFrame(): void
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('end-activation', 91),
            new FlatWorldGenerator($palette),
            new ChunkRepository(16),
        );
        $frames = [
            [-1, -2, 'south'], [0, -2, 'south'], [1, -2, 'south'],
            [-1, 2, 'north'], [0, 2, 'north'], [1, 2, 'north'],
            [-2, -1, 'east'], [-2, 0, 'east'], [-2, 1, 'east'],
            [2, -1, 'west'], [2, 0, 'west'], [2, 1, 'west'],
        ];
        foreach ($frames as $index => [$x, $z, $direction]) {
            $world->setBlockState($x, 64, $z, $states->internalId(CanonicalBlockState::from(
                'minecraft:end_portal_frame',
                [
                    'end_portal_eye_bit' => $index === 2 ? 0 : 1,
                    'minecraft:cardinal_direction' => $direction,
                ],
            )));
        }
        $simulation = new WorldSimulation(
            spawn: new Position(1.5, 64.0, -3.5),
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette(
                $states,
                GenerationBlockPalette::fromRegistry($states),
            ),
            worldId: 'end-activation',
        );
        $commands = new SimulationCommandFactory();
        $bootstrap = new PlayerBootstrap(
            new PlayerIdentity('eye-identity', 'EyeTester'),
            'end-activation',
            new Position(1.5, 64.0, -3.5),
            0.0,
            0.0,
            new PlayerInventoryState([
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:ender_eye', 1)),
            ], 0),
            1,
            1,
        );
        self::assertTrue($simulation->enqueue($commands->join(
            'eye-session',
            'eye-identity',
            'EyeTester',
            bootstrap: $bootstrap,
        )));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'eye-session',
            1,
            new BlockPosition(1, 64, -2),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $simulation->tick();

        self::assertSame(
            1,
            $states->state($world->blockStateAt(1, 64, -2))->properties()['end_portal_eye_bit'],
        );
        for ($x = -1; $x <= 1; ++$x) {
            for ($z = -1; $z <= 1; ++$z) {
                self::assertSame(
                    'minecraft:end_portal',
                    $states->state($world->blockStateAt($x, 64, $z))->identifier(),
                );
            }
        }
        self::assertNull($simulation->authoritativePlayer('eye-identity')?->inventory->selectedStack());
    }

    public function testResolverBuildsStandardPortalWhenNoLoadedPortalExists(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $plan = (new PortalDestinationPlanner())->plan(
            WorldDimension::OVERWORLD,
            new Position(80.0, 64.0, 80.0),
            PortalAxis::X,
        );

        $destination = $simulation->resolvePortalDestination($plan);

        self::assertNotNull($destination);
        self::assertSame([11.0, 64.0, 11.5], [$destination->x, $destination->y, $destination->z]);
        $state = $states->state($world->blockStateAt(10, 64, 10));
        self::assertSame('minecraft:portal', $state->identifier());
        self::assertSame('x', $state->properties()['portal_axis']);
    }

    public function testDestinationPreparationLoadsTheBoundedThreeByThreeNeighborhood(): void
    {
        [$simulation, $world] = self::simulation();
        $plan = (new PortalDestinationPlanner())->plan(
            WorldDimension::OVERWORLD,
            new Position(320.0, 64.0, -160.0),
            PortalAxis::X,
        );

        self::assertTrue($simulation->preparePortalDestination($plan));
        $centerX = (int) floor($plan->projectedPosition->x / 16.0);
        $centerZ = (int) floor($plan->projectedPosition->z / 16.0);
        for ($chunkX = $centerX - 1; $chunkX <= $centerX + 1; ++$chunkX) {
            for ($chunkZ = $centerZ - 1; $chunkZ <= $centerZ + 1; ++$chunkZ) {
                self::assertTrue($world->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ)));
            }
        }
    }

    public function testResolverRejectsPlanOwnedByAnotherDimension(): void
    {
        [$simulation] = self::simulation();
        $plan = (new PortalDestinationPlanner())->plan(
            WorldDimension::NETHER,
            new Position(0.0, 64.0, 0.0),
            PortalAxis::X,
        );

        self::assertNull($simulation->resolvePortalDestination($plan));
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry} */
    private static function simulation(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('portal-target', 84),
            new FlatWorldGenerator($palette),
            new ChunkRepository(32),
            dimension: WorldDimension::NETHER,
        );
        $collisions = BlockCollisionRegistry::forGenerationPalette(
            $states,
            GenerationBlockPalette::fromRegistry($states),
        );
        $simulation = new WorldSimulation(
            spawn: new Position(0.5, 64.0, 0.5),
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $states,
            blockCollisionRegistry: $collisions,
            worldId: 'portal-target',
            dimension: WorldDimension::NETHER,
        );

        return [$simulation, $world, $states];
    }
}
