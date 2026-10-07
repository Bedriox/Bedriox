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

use Bedriox\Api\Event\Player\PlayerItemUseEvent;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\BrushAction;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockEntity\SuspiciousSandBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SuspiciousSandArchaeologyTest extends TestCase
{
    public function testRepeatedStartCompletesExactlyOnceAndUsesPersistedLoot(): void
    {
        [$simulation, $commands, $world, $states, $position] = self::fixture();
        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 1, BrushAction::START, $position, 1)));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 2, BrushAction::START, $position, 1)));

        $drops = [];
        for ($tick = 0; $tick < 45; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned) {
                    $drops[] = $event;
                }
            }
        }

        self::assertCount(1, $drops);
        self::assertSame('minecraft:sniffer_egg', $drops[0]->entity->stack->identifier);
        self::assertEqualsWithDelta(65.05, $drops[0]->entity->position->y, 0.000_001);
        self::assertNull($world->blockEntityAt($position));
        self::assertSame('minecraft:sand', $states->state(
            $world->blockStateAt($position->x, $position->y, $position->z),
        )->identifier());

        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 3, BrushAction::START, $position, 1)));
        $events = $simulation->tick()->events;
        self::assertCount(1, array_filter($events, static fn(object $event): bool => $event instanceof BlockChanged));
        for ($tick = 0; $tick < 45; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                self::assertFalse(
                    $event instanceof ItemEntitySpawned
                    && $event->entity->stack->identifier === 'minecraft:sniffer_egg',
                );
            }
        }
    }

    public function testStopResetsPersistedProgressWithoutExtractingLootOrWearingBrush(): void
    {
        [$simulation, $commands, $world, $states, $position] = self::fixture();
        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 1, BrushAction::START, $position, 1)));
        for ($tick = 0; $tick < 12; ++$tick) {
            $simulation->tick();
        }
        $progressed = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $progressed);
        self::assertSame(1, $progressed->progress);

        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 2, BrushAction::STOP, null, 0)));
        $events = $simulation->tick()->events;
        self::assertCount(0, array_filter($events, static fn(object $event): bool => $event instanceof ItemEntitySpawned));
        $reset = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $reset);
        self::assertSame(0, $reset->progress);
        self::assertSame(0, $states->state(
            $world->blockStateAt($position->x, $position->y, $position->z),
        )->properties()['brushed_progress']);
    }

    public function testPluginCancellationLeavesLootProgressAndToolUntouched(): void
    {
        $dispatcher = new EventDispatcher(
            new ArchaeologyRuntimeControl(),
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );
        $dispatcher->register(
            'ArchaeologyTest',
            PlayerItemUseEvent::class,
            static function (PlayerItemUseEvent $event): void {
                if ($event->kind === ItemUseKind::BRUSH) {
                    $event->cancel();
                }
            },
        );
        [$simulation, $commands, $world, , $position] = self::fixture(new PluginGameplayEventBridge($dispatcher));

        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 1, BrushAction::START, $position, 1)));
        $simulation->tick();
        for ($tick = 0; $tick < 45; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                self::assertNotInstanceOf(ItemEntitySpawned::class, $event);
            }
        }
        $entity = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $entity);
        self::assertSame(0, $entity->progress);
    }

    public function testFullItemEntityCapacityDefersExtractionWithoutRerollOrDuplication(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $filler = $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(1_000.0, 65.0, 1_000.0));
        [$simulation, $commands, $world, , $position] = self::fixture(itemEntities: $items);
        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 1, BrushAction::START, $position, 1)));
        for ($tick = 0; $tick < 45; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                self::assertFalse(
                    $event instanceof ItemEntitySpawned
                    && $event->entity->stack->identifier === 'minecraft:sniffer_egg',
                );
            }
        }
        $pending = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $pending);
        self::assertSame(3, $pending->progress);

        $items->remove($filler->runtimeEntityId);
        $drops = array_values(array_filter(
            $simulation->tick()->events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned,
        ));
        self::assertCount(1, $drops);
        self::assertSame('minecraft:sniffer_egg', $drops[0]->entity->stack->identifier);
        self::assertNull($world->blockEntityAt($position));
    }

    public function testOnePlayerOwnsBrushProgressAndStoppingReleasesTheBlockDeterministically(): void
    {
        [$simulation, $commands, $world, , $position] = self::fixture();
        self::assertTrue($simulation->enqueue($commands->join('second', 'identity-second', 'Second')));
        self::assertTrue($simulation->enqueue($commands->giveItem('second', 'minecraft:brush', 1)));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 1, BrushAction::START, $position, 5)));
        self::assertTrue($simulation->enqueue($commands->brushBlock('second', 1, BrushAction::START, $position, 4)));
        $simulation->tick();
        for ($tick = 0; $tick < 12; ++$tick) {
            $simulation->tick();
        }
        $progressed = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $progressed);
        self::assertSame(1, $progressed->progress, 'A competing session must not advance the same block twice.');

        self::assertTrue($simulation->enqueue($commands->brushBlock('second', 2, BrushAction::STOP, null, 0)));
        $simulation->tick();
        $stillOwned = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $stillOwned);
        self::assertSame(1, $stillOwned->progress, 'A non-owner stop must not reset authoritative progress.');
        self::assertTrue($simulation->enqueue($commands->brushBlock('player', 2, BrushAction::STOP, null, 0)));
        $simulation->tick();
        $reset = $world->blockEntityAt($position);
        self::assertInstanceOf(SuspiciousSandBlockEntity::class, $reset);
        self::assertSame(0, $reset->progress);

        self::assertTrue($simulation->enqueue($commands->brushBlock('second', 3, BrushAction::START, $position, 5)));
        $drops = [];
        for ($tick = 0; $tick < 45; ++$tick) {
            foreach ($simulation->tick()->events as $event) {
                if ($event instanceof ItemEntitySpawned) {
                    $drops[] = $event;
                }
            }
        }
        self::assertCount(1, $drops);
        self::assertGreaterThan(1.0, $drops[0]->entity->position->x);
        self::assertSame(0, $simulation->authoritativePlayer('identity')?->inventory->selectedStack()?->damage);
        self::assertSame(1, $simulation->authoritativePlayer('identity-second')?->inventory->selectedStack()?->damage);
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, World, BlockStateRegistry, BlockPosition} */
    private static function fixture(
        ?PluginGameplayEventBridge $bridge = null,
        ?ItemEntityRegistry $itemEntities = null,
    ): array {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $blocks = new World(
            new WorldMetadata('archaeology-test', 0),
            new FlatWorldGenerator($flat),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $blocks->setBlockState(
            $position->x,
            $position->y,
            $position->z,
            $states->internalId(CanonicalBlockState::from('minecraft:suspicious_sand', [
                'brushed_progress' => 0,
                'hanging' => 0,
            ])),
        );
        $blocks->setBlockEntity(new SuspiciousSandBlockEntity(
            $position,
            new ContainerItemStack('minecraft:sniffer_egg', 1),
            413,
            SuspiciousSandBlockEntity::WARM_OCEAN_RUIN_PROVENANCE,
        ));
        $blocksCatalog = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $items = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocksCatalog,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $simulation = new WorldSimulation(
            blockWorld: $blocks,
            blockPalette: $flat,
            pluginEvents: $bridge,
            itemCatalog: $items,
            blockCatalog: $blocksCatalog,
            blockStateRegistry: $states,
            itemEntities: $itemEntities,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        self::assertTrue($simulation->enqueue($commands->giveItem('player', 'minecraft:brush', 1)));
        $simulation->tick();

        return [$simulation, $commands, $blocks, $states, $position];
    }
}

final class ArchaeologyRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'ArchaeologyTest';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
