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
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Rail\RailSegment;
use Bedriox\Api\World\Rail\RailShape;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vehicle\Minecart\ChestMinecartEntity;
use Bedriox\Server\Entity\Vehicle\Minecart\RideableMinecartEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Simulation\Event\ActorMounted;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class MinecartGameplayTest extends TestCase
{
    private const string PLAYER_UUID = '00000000-0000-4000-8000-000000000020';

    public function testPlayerMountsAnEmptyMinecartThroughTheSharedMountRegistry(): void
    {
        [$simulation, $commands] = self::joinedSimulation();
        $minecart = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $minecart);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $minecart->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $mounted = self::event($simulation->tick()->events, ActorMounted::class);

        self::assertInstanceOf(ActorMounted::class, $mounted);
        self::assertSame($minecart, $simulation->mountedVehicle(self::PLAYER_UUID));
    }

    public function testMountedPlayerInputOnlyNudgesTheAuthoritativeTrackSpeed(): void
    {
        [$simulation, $commands] = self::joinedSimulation();
        $minecart = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::MINECART,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.5, 2.5),
        ))->entity;
        self::assertInstanceOf(RideableMinecartEntity::class, $minecart);
        $minecart->setCurrentRail(new RailSegment(
            new BlockPosition(0, 64, 2),
            'minecraft:rail',
            RailShape::EAST_WEST,
        ));
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $minecart->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->move(
            'session',
            1,
            0.5,
            64.5,
            2.5,
            270.0,
            0.0,
            MovementMode::WALKING,
            moveZ: 1.0,
            vehiclePitch: 0.0,
            vehicleYaw: 270.0,
            vehicleControlYaw: 270.0,
            predictedVehicleActorId: $minecart->getRuntimeId(),
        )));
        $simulation->tick();

        self::assertSame(0.04, $minecart->getTrackSpeed());
    }

    public function testPlayerOpensChestMinecartStorageInsteadOfMounting(): void
    {
        [$simulation, $commands] = self::joinedSimulation();
        $minecart = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHEST_MINECART,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(ChestMinecartEntity::class, $minecart);
        $minecart->vehicleInventory()->setStack(5, new ItemStack('minecraft:diamond', 3));

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'session',
            $minecart->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $events = $simulation->tick()->events;
        $opened = self::event($events, ContainerOpened::class);

        self::assertInstanceOf(
            ContainerOpened::class,
            $opened,
            implode(', ', array_map(static fn(object $event): string => $event instanceof CommandRejected
                ? $event::class . ':' . $event->reason
                : $event::class, $events)),
        );
        self::assertSame(ContainerType::CHEST_MINECART, $opened->containerType);
        self::assertSame($minecart->getRuntimeId(), $opened->entityRuntimeId);
        self::assertSame('minecraft:diamond', $opened->slots[5]?->identifier);
        self::assertNull($simulation->mountedVehicle(self::PLAYER_UUID));
    }

    public function testCreativeAttackDestroysChestMinecartAndDropsContentsExactlyOnce(): void
    {
        $items = ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry());
        [$simulation, $commands] = self::joinedSimulation($items);
        self::assertTrue($simulation->enqueue($commands->changeGameMode('session', GameMode::CREATIVE)));
        $simulation->tick();
        $minecart = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::CHEST_MINECART,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(ChestMinecartEntity::class, $minecart);
        $minecart->vehicleInventory()->setStack(0, new ItemStack('minecraft:diamond', 3));

        self::assertTrue($simulation->enqueue($commands->attack(
            'session',
            $minecart->getRuntimeId(),
            0,
        )));
        $events = [...$simulation->tick()->events, ...$simulation->tick()->events];

        self::assertFalse($minecart->isAlive());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof EntityActorDied,
        ));
        $drops = array_map(
            static fn(ItemEntitySpawned $event): string => $event->entity->stack->identifier,
            array_values(array_filter($events, static fn(object $event): bool => $event instanceof ItemEntitySpawned)),
        );
        self::assertSame(1, count(array_filter($drops, static fn(string $drop): bool => $drop === 'minecraft:chest_minecart')));
        self::assertSame(1, count(array_filter($drops, static fn(string $drop): bool => $drop === 'minecraft:diamond')));
    }

    /** @return array{WorldSimulation, SimulationCommandFactory} */
    private static function joinedSimulation(?ItemCatalog $items = null): array
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, itemCatalog: $items);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('session', self::PLAYER_UUID, 'Player', 1)));
        $simulation->tick();

        return [$simulation, $commands];
    }

    /**
     * @template T of object
     * @param list<object> $events
     * @param class-string<T> $type
     * @return T|null
     */
    private static function event(array $events, string $type): ?object
    {
        foreach ($events as $event) {
            if ($event instanceof $type) {
                return $event;
            }
        }

        return null;
    }
}
