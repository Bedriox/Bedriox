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

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\SnifferEntity;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Environment\EnvironmentTickScheduler;
use Bedriox\Server\World\Environment\EnvironmentTickType;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;
use ReflectionProperty;

final class SnifferLifecycleQualificationTest extends TestCase
{
    public function testEggPlacementSchedulesMossAtHalfTheNormalDelay(): void
    {
        self::assertSame(4_000, self::placeEggAndTicksUntilFirstCrack('minecraft:moss_block'));
        self::assertSame(8_000, self::placeEggAndTicksUntilFirstCrack('minecraft:dirt'));
    }

    public function testEggCracksTwiceThenHatchesOneBabyAndRemovesTheBlock(): void
    {
        [$simulation, , $world, $states] = self::fixture();
        $position = new BlockPosition(2, 64, 0);
        $world->setBlockState(2, 63, 0, $states->internalId(CanonicalBlockState::from('minecraft:moss_block')));
        $world->setBlockState(2, 64, 0, $states->internalId(CanonicalBlockState::from(
            'minecraft:sniffer_egg',
            ['cracked_state' => 'no_cracks'],
        )));
        $scheduler = self::environmentTicks($simulation);

        $stages = ['cracked', 'max_cracked'];
        foreach ($stages as $stage) {
            self::assertTrue($scheduler->schedule($position, EnvironmentTickType::SNIFFER_EGG, 0, 0));
            $events = $simulation->tick()->events;
            self::assertCount(1, self::eventsOf($events, BlockChanged::class));
            self::assertSame($stage, self::blockProperties($world, $states, $position)['cracked_state'] ?? null);
            self::assertSame([], self::eventsOf($events, EntityActorSpawned::class));
            self::assertTrue($scheduler->cancel($position, EnvironmentTickType::SNIFFER_EGG));
        }

        self::assertTrue($scheduler->schedule($position, EnvironmentTickType::SNIFFER_EGG, 0, 0));
        $events = self::advanceEnvironmentalBlocks($simulation);
        self::assertCount(1, self::eventsOf($events, BlockChanged::class));
        array_push($events, ...$simulation->tick()->events);
        $spawns = self::eventsOf($events, EntityActorSpawned::class);
        self::assertCount(1, $spawns);
        self::assertInstanceOf(SnifferEntity::class, $spawns[0]->entity);
        self::assertTrue($spawns[0]->entity->isBaby());
        self::assertSame('minecraft:air', self::blockIdentifier($world, $states, $position));
        self::assertSame(0, $scheduler->count());

        for ($tick = 0; $tick < 20; ++$tick) {
            self::assertSame([], self::eventsOf($simulation->tick()->events, EntityActorSpawned::class));
        }
    }

    public function testBreedingTwoAdultSniffersDropsOneEggInsteadOfSpawningAnOffspring(): void
    {
        [$simulation, $commands] = self::fixture();
        $first = self::spawnSniffer($simulation, new Position(1.5, 64.0, 0.5));
        $second = self::spawnSniffer($simulation, new Position(2.5, 64.0, 0.5));
        self::assertTrue($simulation->enqueue($commands->giveItem('player', 'minecraft:torchflower_seeds', 2)));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $first->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();
        self::assertTrue($first->isReadyToBreed());

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'player',
            $second->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;
        $eggDrops = array_values(array_filter(
            self::eventsOf($events, ItemEntitySpawned::class),
            static fn(ItemEntitySpawned $event): bool => $event->entity->stack->identifier === 'minecraft:sniffer_egg',
        ));
        self::assertCount(1, $eggDrops);
        self::assertFalse($first->isReadyToBreed());
        self::assertFalse($second->isReadyToBreed());
        self::assertCount(0, array_filter(
            self::eventsOf($events, EntityActorSpawned::class),
            static fn(EntityActorSpawned $event): bool => $event->entity instanceof SnifferEntity,
        ));
        self::assertNull($simulation->authoritativePlayer('identity')?->inventory->selectedStack());
    }

    public function testWorldTicksCompleteOneDigRememberTheSiteAndDropExactlyOneSeed(): void
    {
        [$simulation, , , , $items] = self::fixture();
        $sniffer = self::spawnSniffer($simulation, new Position(2.5, 64.0, 0.5));

        $seedDrops = [];
        for ($tick = 0; $tick < 200; ++$tick) {
            foreach (self::eventsOf($simulation->tick()->events, ItemEntitySpawned::class) as $event) {
                if (in_array($event->entity->stack->identifier, ['minecraft:torchflower_seeds', 'minecraft:pitcher_pod'], true)) {
                    $seedDrops[] = $event;
                }
            }
        }

        self::assertCount(1, $seedDrops);
        self::assertSame(1, $items->count());
        self::assertTrue($sniffer->hasDugAt(2, 63, 0));
        self::assertSame(1, $sniffer->getRememberedDigSiteCount());

        for ($tick = 0; $tick < 400; ++$tick) {
            foreach (self::eventsOf($simulation->tick()->events, ItemEntitySpawned::class) as $event) {
                self::assertFalse(in_array(
                    $event->entity->stack->identifier,
                    ['minecraft:torchflower_seeds', 'minecraft:pitcher_pod'],
                    true,
                ));
            }
        }
    }

    public function testWorldTickInvalidGroundInterruptsDigWithoutDropOrRememberingSite(): void
    {
        [$simulation, , $world, $states, $items] = self::fixture();
        $sniffer = self::spawnSniffer($simulation, new Position(2.5, 64.0, 0.5));
        self::tickUntil($simulation, static fn(): bool => $sniffer->isDigging(), 40);
        $world->setBlockState(2, 63, 0, $states->internalId(CanonicalBlockState::from('minecraft:stone')));
        self::tickUntil($simulation, static fn(): bool => !$sniffer->isDigging(), 40);

        self::assertSame(0, $items->count());
        self::assertSame(0, $sniffer->getRememberedDigSiteCount());
        self::assertFalse($sniffer->hasDugAt(2, 63, 0));
    }

    public function testFullDropCapacityDefersCompletedDigWithoutLosingOrDuplicatingTheReward(): void
    {
        $items = new ItemEntityRegistry(1, 90_000);
        $filler = $items->spawn(new InventoryStack('minecraft:stone', 1, 1), new Position(50.0, 64.0, 50.0));
        [$simulation] = self::fixture($items);
        $sniffer = self::spawnSniffer($simulation, new Position(2.5, 64.0, 0.5));

        for ($tick = 0; $tick < 200; ++$tick) {
            $simulation->tick();
        }
        self::assertSame(1, $items->count());
        self::assertSame(0, $sniffer->getRememberedDigSiteCount(), 'A failed output must not commit the dig site.');

        $items->remove($filler->runtimeEntityId);
        $drops = [];
        for ($tick = 0; $tick < 240; ++$tick) {
            foreach (self::eventsOf($simulation->tick()->events, ItemEntitySpawned::class) as $event) {
                if (in_array($event->entity->stack->identifier, ['minecraft:torchflower_seeds', 'minecraft:pitcher_pod'], true)) {
                    $drops[] = $event;
                }
            }
        }
        self::assertCount(1, $drops);
        self::assertSame(1, $sniffer->getRememberedDigSiteCount());
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, World, BlockStateRegistry, ItemEntityRegistry} */
    private static function fixture(?ItemEntityRegistry $items = null): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $flat = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('sniffer-qualification', 41),
            new FlatWorldGenerator($flat),
            new ChunkRepository(8),
        );
        $blocks = BlockCatalog::vanilla($states, $data->blockItemMappingRegistry());
        $catalog = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            $blocks,
            $data->creativeInventoryRegistry(),
            $data->blockItemMappingRegistry(),
        );
        $items ??= new ItemEntityRegistry();
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $flat,
            itemCatalog: $catalog,
            blockCatalog: $blocks,
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
            itemEntities: $items,
            entityAiEnabled: false,
            spawnAnimals: false,
            spawnMonsters: false,
        );
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', 'identity', 'Player')));
        $simulation->tick();

        return [$simulation, $commands, $world, $states, $items];
    }

    private static function spawnSniffer(WorldSimulation $simulation, Position $position): SnifferEntity
    {
        $outcome = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::SNIFFER,
            SpawnCause::COMMAND,
            'world',
            $position,
        ));
        self::assertInstanceOf(SnifferEntity::class, $outcome->entity);

        return $outcome->entity;
    }

    private static function placeEggAndTicksUntilFirstCrack(string $groundIdentifier): int
    {
        [$simulation, $commands, $world, $states] = self::fixture();
        $ground = new BlockPosition(2, 63, 0);
        $egg = new BlockPosition(2, 64, 0);
        $world->setBlockState($ground->x, $ground->y, $ground->z, $states->internalId(
            CanonicalBlockState::from($groundIdentifier),
        ));
        self::assertTrue($simulation->enqueue($commands->giveItem('player', 'minecraft:sniffer_egg', 1)));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'player',
            1,
            $ground,
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $simulation->tick();
        self::assertSame('minecraft:sniffer_egg', self::blockIdentifier($world, $states, $egg));
        self::assertSame('no_cracks', self::blockProperties($world, $states, $egg)['cracked_state'] ?? null);
        self::assertSame(1, self::environmentTicks($simulation)->count());
        self::assertNull($simulation->authoritativePlayer('identity')?->inventory->selectedStack());

        for ($ticks = 1; $ticks <= 8_001; ++$ticks) {
            $simulation->tick();
            if ((self::blockProperties($world, $states, $egg)['cracked_state'] ?? null) === 'cracked') {
                return $ticks;
            }
        }

        self::fail('The Sniffer egg did not reach its first crack inside the bounded timing window.');
    }

    private static function environmentTicks(WorldSimulation $simulation): EnvironmentTickScheduler
    {
        $scheduler = (new ReflectionProperty(WorldSimulation::class, 'environmentTicks'))->getValue($simulation);
        self::assertInstanceOf(EnvironmentTickScheduler::class, $scheduler);

        return $scheduler;
    }

    /** @return list<WorldEvent> */
    private static function advanceEnvironmentalBlocks(WorldSimulation $simulation): array
    {
        $events = (new ReflectionMethod(WorldSimulation::class, 'advanceEnvironmentalBlocks'))->invoke($simulation);
        if (!is_array($events) || !array_is_list($events)) {
            self::fail('Environmental advancement returned a malformed event list.');
        }
        foreach ($events as $event) {
            if (!$event instanceof WorldEvent) {
                self::fail('Environmental advancement returned a non-world event.');
            }
        }

        return $events;
    }

    private static function blockIdentifier(
        World $world,
        BlockStateRegistry $states,
        BlockPosition $position,
    ): string {
        return $states->state($world->blockStateAt($position->x, $position->y, $position->z))->identifier();
    }

    /** @return array<string, bool|int|string> */
    private static function blockProperties(
        World $world,
        BlockStateRegistry $states,
        BlockPosition $position,
    ): array {
        return $states->state($world->blockStateAt($position->x, $position->y, $position->z))->properties();
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $class
     * @return list<T>
     */
    private static function eventsOf(array $events, string $class): array
    {
        return array_values(array_filter($events, static fn(object $event): bool => $event instanceof $class));
    }

    /** @param callable(): bool $condition */
    private static function tickUntil(WorldSimulation $simulation, callable $condition, int $maximumTicks): void
    {
        for ($tick = 0; $tick < $maximumTicks; ++$tick) {
            if ($condition()) {
                return;
            }
            $simulation->tick();
        }
        self::assertTrue($condition(), 'The expected Sniffer state was not reached within the bounded tick window.');
    }
}
