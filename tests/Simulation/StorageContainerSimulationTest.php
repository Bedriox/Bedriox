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
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Potion\BrewingRecipeCatalog;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class StorageContainerSimulationTest extends TestCase
{
    public function testIdleLoadedBrewingStandPerformsNoRecipeScans(): void
    {
        $data = BedrockDataSet::bundled();
        $registry = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('idle-brewing-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $world->setBlockEntity(BrewingStandBlockEntity::empty($position));
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $registry,
            brewingRecipes: new BrewingRecipeCatalog(
                $data->recipeRegistry()->containerMixes(),
                $data->recipeRegistry()->potionMixes(),
            ),
        );

        for ($tick = 0; $tick < 100; ++$tick) {
            $simulation->tick();
        }

        self::assertSame(0, $simulation->brewingRecipeEvaluationCount());
    }

    public function testBarrelPresentationOpensForFirstViewerAndClosesForLastViewer(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('barrel-viewer-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $closedState = CanonicalBlockState::from('minecraft:barrel', [
            'facing_direction' => 0,
            'open_bit' => 0,
        ]);
        $world->setBlockState($position->x, $position->y, $position->z, $registry->internalId($closedState));
        $world->setBlockEntity(ContainerBlockEntity::empty(BlockEntityType::Barrel, $position));
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        $firstUuid = '00000000-0000-0000-0000-000000000001';
        $secondUuid = '00000000-0000-0000-0000-000000000002';
        self::assertTrue($simulation->enqueue($factory->join('one', $firstUuid, 'One')));
        self::assertTrue($simulation->enqueue($factory->join('two', $secondUuid, 'Two')));
        $simulation->tick();

        self::assertTrue($simulation->pluginOpenWorldContainer($firstUuid, $position));
        $firstOpen = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertSame(['one', 'two'], $firstOpen->blockEventRecipientSessionIds);
        self::assertSame(1, $registry->state($world->blockStateAt(1, 64, 0))->properties()['open_bit']);

        self::assertTrue($simulation->pluginOpenWorldContainer($secondUuid, $position));
        $secondOpen = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertSame([], $secondOpen->blockEventRecipientSessionIds);

        self::assertTrue($simulation->pluginCloseWorldContainer($firstUuid, $position));
        $firstClose = self::event($simulation->tick()->events, ContainerClosed::class);
        self::assertSame([], $firstClose->blockEventRecipientSessionIds);
        self::assertSame(1, $registry->state($world->blockStateAt(1, 64, 0))->properties()['open_bit']);

        self::assertTrue($simulation->pluginCloseWorldContainer($secondUuid, $position));
        $secondClose = self::event($simulation->tick()->events, ContainerClosed::class);
        self::assertSame(['one', 'two'], $secondClose->blockEventRecipientSessionIds);
        self::assertSame(0, $registry->state($world->blockStateAt(1, 64, 0))->properties()['open_bit']);
    }

    public function testRepeatedOpenDoesNotReplaceTheActiveContainerConversation(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('container-retry-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $state = CanonicalBlockState::from('minecraft:chest', [
            'minecraft:cardinal_direction' => 'north',
        ]);
        $world->setBlockState($position->x, $position->y, $position->z, $registry->internalId($state));
        $world->setBlockEntity(ContainerBlockEntity::empty(BlockEntityType::Chest, $position));
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        $uuid = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($simulation->enqueue($factory->join('one', $uuid, 'One')));
        $simulation->tick();

        self::assertTrue($simulation->pluginOpenWorldContainer($uuid, $position));
        self::assertFalse($simulation->pluginOpenWorldContainer($uuid, $position));

        $events = $simulation->tick()->events;
        self::event($events, ContainerOpened::class);
        self::assertCount(1, array_filter($events, static fn(object $event): bool => $event instanceof ContainerOpened));
        self::assertCount(0, array_filter($events, static fn(object $event): bool => $event instanceof ContainerClosed));
    }

    public function testClientCloseIntentPassesCommandAdmissionAndClosesTheWindow(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('container-close-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $world->setBlockState(
            $position->x,
            $position->y,
            $position->z,
            $registry->internalId(CanonicalBlockState::from('minecraft:chest', [
                'minecraft:cardinal_direction' => 'north',
            ])),
        );
        $world->setBlockEntity(ContainerBlockEntity::empty(BlockEntityType::Chest, $position));
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            blockStateRegistry: $registry,
        );
        $factory = new SimulationCommandFactory();
        $uuid = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($simulation->enqueue($factory->join('one', $uuid, 'One')));
        $simulation->tick();
        self::assertTrue($simulation->pluginOpenWorldContainer($uuid, $position));
        $opened = self::event($simulation->tick()->events, ContainerOpened::class);

        self::assertTrue($simulation->enqueue($factory->closeContainer('one', $opened->windowId)));
        $closed = self::event($simulation->tick()->events, ContainerClosed::class);

        self::assertSame($opened->windowId, $closed->windowId);
        self::assertFalse($closed->serverInitiated);
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return T
     */
    private static function event(array $events, string $type): object
    {
        foreach ($events as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        self::fail('Expected event ' . $type . ' was not emitted.');
    }
}
