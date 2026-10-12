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

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vehicle\Minecart\RideableMinecartEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MinecartPlacementTest extends TestCase
{
    /** @return iterable<string, array{string, VanillaEntityType}> */
    public static function minecartItems(): iterable
    {
        yield 'ordinary minecart' => ['minecraft:minecart', VanillaEntityType::MINECART];
        yield 'chest minecart' => ['minecraft:chest_minecart', VanillaEntityType::CHEST_MINECART];
    }

    #[DataProvider('minecartItems')]
    public function testMinecartPlacementRequiresRailAndConsumesOneAuthoritativeItem(
        string $itemIdentifier,
        VanillaEntityType $entityType,
    ): void {
        [$simulation, $world, $states, $factory] = self::environment();
        self::joinAndGive($simulation, $factory, $itemIdentifier, 2);
        $railPosition = new BlockPosition(1, 64, 0);
        $world->setBlockState(
            $railPosition->x,
            $railPosition->y,
            $railPosition->z,
            $states->internalId(CanonicalBlockState::from('minecraft:rail', ['rail_direction' => 1])),
        );

        self::assertTrue($simulation->enqueue($factory->placeBlock(
            'session',
            1,
            $railPosition,
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $events = $simulation->tick()->events;
        $placement = self::firstEvent($events, BlockPlacementCorrected::class);

        self::assertSame('minecart_used', $placement->reason);
        self::assertSame($railPosition, $placement->placedPosition);
        self::assertSame(
            $world->loadedBlockStateAt($railPosition->x, $railPosition->y, $railPosition->z),
            $placement->placedState,
            'Minecart placement must re-project the authoritative rail instead of predicted adjacent air.',
        );
        self::assertNull($placement->heldStack);
        self::assertSame(1, self::itemCount($simulation, $itemIdentifier));
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof HeldItemChanged,
        ));
        self::assertSame(1, $simulation->entityRuntime()->registry()->countByType($entityType));
        $entity = $simulation->entityRuntime()->registry()->all()[0];
        self::assertSame(1.5, $entity->getPosition()->x);
        self::assertSame(64.5, $entity->getPosition()->y);
        self::assertSame(0.5, $entity->getPosition()->z);
    }

    #[DataProvider('minecartItems')]
    public function testRejectedOffRailPlacementCorrectsWithoutConsumingOrSpawning(
        string $itemIdentifier,
        VanillaEntityType $_entityType,
    ): void {
        [$simulation, $_world, $_states, $factory] = self::environment();
        self::joinAndGive($simulation, $factory, $itemIdentifier, 2);
        $clicked = new BlockPosition(1, 63, 0);

        self::assertTrue($simulation->enqueue($factory->placeBlock(
            'session',
            1,
            $clicked,
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        )));
        $events = $simulation->tick()->events;
        $placement = self::firstEvent($events, BlockPlacementCorrected::class);

        self::assertSame('rail_required', $placement->reason);
        self::assertSame(1, $placement->heldStack?->count);
        self::assertSame(2, self::itemCount($simulation, $itemIdentifier));
        self::assertSame(0, $simulation->entityRuntime()->registry()->count());
        self::assertCount(0, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof HeldItemChanged,
        ));
    }

    public function testRailPhysicsKeepsTheCartCentredAndHonoursAuthoritativeTrackAcceleration(): void
    {
        [$simulation, $world, $states] = self::environment();
        $railState = $states->internalId(CanonicalBlockState::from('minecraft:rail', ['rail_direction' => 1]));
        foreach ([0, 1, 2] as $x) {
            $world->setBlockState($x, 64, 0, $railState);
        }
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(1.5, 64.5, 0.5),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->applyMotion(new EntityMotion(0.04, 0.0, 0.0));
        $entity->setTrackSpeed(0.08);

        $simulation->tick();

        self::assertSame(64.5, $entity->getPosition()->y);
        self::assertGreaterThanOrEqual(0.079, abs($entity->getPosition()->x - 1.5));
        self::assertGreaterThan(0.07, abs($entity->getTrackSpeed()));
        self::assertSame(0.0, $entity->getYaw());
    }

    public function testMinecartAdvancesAcrossAdjacentLoadedRails(): void
    {
        [$simulation, $world, $states] = self::environment();
        $rail = $states->internalId(CanonicalBlockState::from('minecraft:rail', ['rail_direction' => 1]));
        foreach ([0, 1, 2] as $x) {
            $world->setBlockState($x, 64, 0, $rail);
        }
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(1.5, 64.5, 0.5),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->setTrackSpeed(0.2);

        $simulation->tick();
        $simulation->tick();

        self::assertGreaterThan(1.5, $entity->getPosition()->x);
        self::assertNotNull($entity->getCurrentRail());
    }

    public function testMinecartClimbsOntoAFullBlockHigherRail(): void
    {
        [$simulation, $world, $states] = self::environment();
        $ascending = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 2,
        ]));
        $flat = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 1,
        ]));
        $stone = $states->internalId(CanonicalBlockState::from('minecraft:stone'));
        $world->setBlockState(0, 63, 0, $stone);
        $world->setBlockState(1, 64, 0, $stone);
        $world->setBlockState(2, 64, 0, $stone);
        $world->setBlockState(0, 64, 0, $ascending);
        $world->setBlockState(1, 65, 0, $flat);
        $world->setBlockState(2, 65, 0, $flat);
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(0.5, 65.0, 0.5),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->setTrackSpeed(0.2);

        for ($tick = 0; $tick < 8; ++$tick) {
            $simulation->tick();
        }

        self::assertGreaterThan(1.0, $entity->getPosition()->x);
        self::assertSame(65.5, $entity->getPosition()->y);
        self::assertNotNull($entity->getCurrentRail());
    }

    public function testMinecartKeepsItsDirectionAcrossACurvedRailTransition(): void
    {
        [$simulation, $world, $states] = self::environment();
        $curve = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 6,
        ]));
        $eastWest = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 1,
        ]));
        $world->setBlockState(0, 64, 0, $curve);
        $world->setBlockState(1, 64, 0, $eastWest);
        $world->setBlockState(2, 64, 0, $eastWest);
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(0.55, 64.5, 0.95),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->setTrackSpeed(0.2);

        for ($tick = 0; $tick < 8; ++$tick) {
            $simulation->tick();
        }

        self::assertGreaterThan(1.0, $entity->getPosition()->x);
        self::assertEqualsWithDelta(0.5, $entity->getPosition()->z, 0.001);
        self::assertGreaterThan(0.0, $entity->getTrackSpeed());
        self::assertNotNull($entity->getCurrentRail());
    }

    public function testMinecartDescendsAndLeavesAnAscendingRailInTheReverseDirection(): void
    {
        [$simulation, $world, $states] = self::environment();
        $ascending = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 2,
        ]));
        $flat = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 1,
        ]));
        $stone = $states->internalId(CanonicalBlockState::from('minecraft:stone'));
        foreach ([-2, -1, 0] as $x) {
            $world->setBlockState($x, 63, 0, $stone);
        }
        $world->setBlockState(1, 64, 0, $stone);
        $world->setBlockState(-2, 64, 0, $flat);
        $world->setBlockState(-1, 64, 0, $flat);
        $world->setBlockState(0, 64, 0, $ascending);
        $world->setBlockState(1, 65, 0, $flat);
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(0.95, 65.45, 0.5),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->setTrackSpeed(-0.2);

        for ($tick = 0; $tick < 8; ++$tick) {
            $simulation->tick();
        }

        self::assertLessThan(0.0, $entity->getPosition()->x);
        self::assertSame(64.5, $entity->getPosition()->y);
        self::assertLessThan(0.0, $entity->getTrackSpeed());
    }

    public function testMinecartLeavesACurveThroughItsReverseEndpoint(): void
    {
        [$simulation, $world, $states] = self::environment();
        $curve = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 6,
        ]));
        $northSouth = $states->internalId(CanonicalBlockState::from('minecraft:rail', [
            'rail_direction' => 0,
        ]));
        $world->setBlockState(0, 64, 0, $curve);
        $world->setBlockState(0, 64, 1, $northSouth);
        $world->setBlockState(0, 64, 2, $northSouth);
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'minecart-placement-test',
            new Position(0.95, 64.5, 0.55),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $entity);
        $entity->setTrackSpeed(-0.2);

        for ($tick = 0; $tick < 8; ++$tick) {
            $simulation->tick();
        }

        self::assertGreaterThan(1.0, $entity->getPosition()->z);
        self::assertEqualsWithDelta(0.5, $entity->getPosition()->x, 0.001);
        self::assertGreaterThan(0.0, $entity->getTrackSpeed());
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry, SimulationCommandFactory} */
    private static function environment(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $generation = GenerationBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('minecart-placement-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $simulation = new WorldSimulation(
            blockWorld: $world,
            blockPalette: $palette,
            itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry()),
            blockStateRegistry: $states,
            blockCollisionRegistry: BlockCollisionRegistry::forGenerationPalette($states, $generation),
        );

        return [$simulation, $world, $states, new SimulationCommandFactory()];
    }

    private static function joinAndGive(
        WorldSimulation $simulation,
        SimulationCommandFactory $factory,
        string $identifier,
        int $count,
    ): void {
        self::assertTrue($simulation->enqueue($factory->join('session', 'identity', 'Player')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($factory->giveItem('session', $identifier, $count)));
        $simulation->tick();
    }

    private static function itemCount(WorldSimulation $simulation, string $identifier): int
    {
        $inventory = $simulation->authoritativePlayer('identity')?->inventory;
        self::assertNotNull($inventory);
        $count = 0;
        for ($slot = 0; $slot < \Bedriox\Server\Player\PlayerInventory::SLOT_COUNT; ++$slot) {
            $stack = $inventory->stackAt($slot);
            if ($stack?->identifier === $identifier) {
                $count += $stack->count;
            }
        }

        return $count;
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $class
     * @return T
     */
    private static function firstEvent(array $events, string $class): object
    {
        foreach ($events as $event) {
            if ($event instanceof $class) {
                return $event;
            }
        }

        self::fail('Expected event ' . $class . ' was not emitted; received: ' . implode(', ', array_map(
            static fn(object $event): string => $event instanceof BlockPlacementCorrected
                ? $event::class . ':' . $event->reason
                : $event::class,
            $events,
        )));
    }
}
