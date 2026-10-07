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

use Bedriox\Api\Event\Processing\ComposterChangedEvent;
use Bedriox\Api\Event\Processing\ComposterChangeEvent;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Processing\CauldronBlockEntity;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\InventorySlotChanged;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class LiveComposterCauldronTest extends TestCase
{
    public function testLoadedLevelSevenComposterIsRediscoveredAndMatures(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $position = new BlockPosition(0, 64, 1);
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId(
            CanonicalBlockState::from('minecraft:composter', ['composter_fill_level' => 7]),
        ));

        for ($tick = 0; $tick < 20; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(7, self::properties($world, $states, $position)['composter_fill_level']);

        $simulation->tick();
        self::assertSame(8, self::properties($world, $states, $position)['composter_fill_level']);
    }

    public function testComposterConsumesOnceMaturesAndExtractsBoneMeal(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $position = new BlockPosition(0, 64, 1);
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId(
            CanonicalBlockState::from('minecraft:composter', ['composter_fill_level' => 6]),
        ));
        $factory = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($factory->join('one', 'identity-one', 'One')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($factory->giveItem('one', 'minecraft:cake', 1)));
        self::assertTrue($simulation->enqueue($factory->selectHotbarSlot('one', 0)));
        $simulation->tick();
        self::assertSame('minecraft:cake', $simulation->snapshot()->players[0]->selectedStack?->identifier);

        self::assertTrue($simulation->enqueue($factory->placeBlock('one', 1, $position, 1, 0, 0, 0.5, 0.5, 0.5)));
        $events = $simulation->tick()->events;
        self::assertInstanceOf(
            BlockChanged::class,
            $events[0],
            $events[0] instanceof BlockPlacementCorrected ? $events[0]->reason : '',
        );
        self::assertSame(7, self::properties($world, $states, $position)['composter_fill_level']);
        self::assertNull($simulation->snapshot()->players[0]->selectedStack);

        for ($tick = 0; $tick < 19; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(7, self::properties($world, $states, $position)['composter_fill_level']);
        $simulation->tick();
        self::assertSame(8, self::properties($world, $states, $position)['composter_fill_level']);

        self::assertTrue($simulation->enqueue($factory->placeBlock('one', 2, $position, 1, 0, 0, 0.5, 0.5, 0.5)));
        $extracted = $simulation->tick()->events;
        self::assertInstanceOf(BlockChanged::class, $extracted[0]);
        self::assertSame(0, self::properties($world, $states, $position)['composter_fill_level']);
        self::assertInstanceOf(InventorySlotChanged::class, $extracted[1]);
        self::assertSame('minecraft:bone_meal', $extracted[1]->stack?->identifier);
    }

    public function testCancelledComposterChangePreservesBlockAndInventoryAndSuppressesPostEvent(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', ComposterChangeEvent::class, static function (ComposterChangeEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', ComposterChangedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $world, $states] = self::simulation($bridge);
        $position = new BlockPosition(0, 64, 1);
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId(
            CanonicalBlockState::from('minecraft:composter', ['composter_fill_level' => 0]),
        ));
        $factory = new SimulationCommandFactory();
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->giveItem('one', 'minecraft:cake', 1));
        $simulation->enqueue($factory->selectHotbarSlot('one', 0));
        $simulation->tick();
        $simulation->enqueue($factory->placeBlock('one', 1, $position, 1, 0, 0, 0.5, 0.5, 0.5));

        self::assertInstanceOf(BlockPlacementCorrected::class, $simulation->tick()->events[0]);
        self::assertSame(0, self::properties($world, $states, $position)['composter_fill_level']);
        self::assertSame('minecraft:cake', $simulation->snapshot()->players[0]->selectedStack?->identifier);
        self::assertSame(0, $postEvents);
    }

    public function testPotionCauldronRoundTripPreservesPotionMetadataWithoutDuplicatingItems(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $position = new BlockPosition(0, 64, 1);
        $world->setBlockState($position->x, $position->y, $position->z, $states->internalId(
            CanonicalBlockState::from('minecraft:cauldron', ['cauldron_liquid' => 'water', 'fill_level' => 0]),
        ));
        $factory = new SimulationCommandFactory();
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $simulation->enqueue($factory->giveItem('one', 'minecraft:potion', 1, auxValue: 5));
        $simulation->enqueue($factory->selectHotbarSlot('one', 0));
        $simulation->tick();
        $simulation->enqueue($factory->placeBlock('one', 1, $position, 1, 0, 0, 0.5, 0.5, 0.5));

        self::assertInstanceOf(BlockChanged::class, $simulation->tick()->events[0]);
        self::assertSame(2, self::properties($world, $states, $position)['fill_level']);
        $entity = $world->blockEntityAt($position);
        self::assertInstanceOf(CauldronBlockEntity::class, $entity);
        self::assertSame(5, $entity->potionAuxValue);
        self::assertSame('minecraft:glass_bottle', $simulation->snapshot()->players[0]->selectedStack?->identifier);

        $simulation->enqueue($factory->placeBlock('one', 2, $position, 1, 0, 0, 0.5, 0.5, 0.5));
        $extracted = $simulation->tick()->events;
        self::assertInstanceOf(BlockChanged::class, $extracted[0]);
        self::assertSame(0, self::properties($world, $states, $position)['fill_level']);
        self::assertNull($world->blockEntityAt($position));
        self::assertInstanceOf(InventorySlotChanged::class, $extracted[1]);
        self::assertSame('minecraft:potion', $extracted[1]->stack?->identifier);
        self::assertSame(5, $extracted[1]->stack->auxValue);
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry} */
    private static function simulation(?PluginGameplayEventBridge $bridge = null): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('live-processing-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->retainChunk(new ChunkPosition(0, 0));

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                pluginEvents: $bridge,
                itemCatalog: ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()),
                blockStateRegistry: $states,
                dropRandom: new class implements DropRandom {
                    private int $calls = 0;

                    public function integer(int $minimum, int $maximum): int
                    {
                        ++$this->calls;
                        return $minimum;
                    }
                },
            ),
            $world,
            $states,
        ];
    }

    /** @return array<string, bool|int|string> */
    private static function properties(World $world, BlockStateRegistry $states, BlockPosition $position): array
    {
        return $states->state($world->blockStateAt($position->x, $position->y, $position->z))->properties();
    }

    /** @return array{EventDispatcher, PluginGameplayEventBridge} */
    private static function bridge(): array
    {
        $runtime = new class implements PluginRuntimeControl {
            public function isEnabled(string $plugin): bool
            {
                return $plugin === 'Example';
            }

            public function version(string $plugin): string
            {
                return '1.0.0';
            }

            public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
        };
        $dispatcher = new EventDispatcher(
            $runtime,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );

        return [$dispatcher, new PluginGameplayEventBridge($dispatcher)];
    }
}
