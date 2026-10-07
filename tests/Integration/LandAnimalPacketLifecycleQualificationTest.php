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

namespace Bedriox\Server\Tests\Integration;

use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\LeashHolderType;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\HorseEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\CommandValidationException;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class LandAnimalPacketLifecycleQualificationTest extends TestCase
{
    public function testCapacityBlockedDisconnectClearsWireStateAndDefersExactlyOneLead(): void
    {
        $items = new ItemEntityRegistry(1, 5_000);
        $filler = $items->spawn(
            new InventoryStack('minecraft:stone', 1, 1),
            new Position(1_000.0, 64.0, 1_000.0),
        );
        [$simulation, $commands] = self::simulation($items);
        $ownerIdentity = '00000000-0000-4000-8000-000000000101';
        self::assertTrue($simulation->enqueue($commands->join('owner', $ownerIdentity, 'Owner')));
        self::assertTrue($simulation->enqueue($commands->join(
            'peer',
            '00000000-0000-4000-8000-000000000102',
            'Peer',
        )));
        $simulation->tick();
        $owner = $simulation->authoritativePlayer($ownerIdentity);
        self::assertNotNull($owner);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 1, 2));

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
        $llama = $spawn->entity;
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $llama->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();
        self::assertSame(LeashHolderType::PLAYER, $llama->getLeashHolderType());

        self::assertTrue($simulation->enqueue($commands->disconnect('owner')));
        $departure = $simulation->tick()->events;
        $types = array_map(static fn(object $event): string => $event::class, $departure);
        $metadataIndex = array_search(EntityActorMetadataChanged::class, $types, true);
        $disconnectIndex = array_search(PlayerDisconnected::class, $types, true);
        self::assertNotFalse($metadataIndex);
        self::assertNotFalse($disconnectIndex);
        self::assertLessThan($disconnectIndex, $metadataIndex);
        self::assertFalse($llama->isLeashed());
        self::assertSame(['peer'], $departure[$metadataIndex]->recipients());
        self::assertCount(0, self::leadSpawns($departure));

        $items->remove($filler->runtimeEntityId);
        $leadSpawns = [];
        for ($tick = 0; $tick < 4; ++$tick) {
            array_push($leadSpawns, ...self::leadSpawns($simulation->tick()->events));
        }
        self::assertCount(1, $leadSpawns);

        self::assertTrue($simulation->enqueue($commands->join('owner-2', $ownerIdentity, 'Owner')));
        $simulation->tick();
        for ($tick = 0; $tick < 20; ++$tick) {
            self::assertCount(0, self::leadSpawns($simulation->tick()->events));
        }
    }

    public function testDuplicateAndReorderedInteractionsCannotDuplicateLeashOwnership(): void
    {
        [$simulation, $commands] = self::simulation();
        $ownerIdentity = '00000000-0000-4000-8000-000000000111';
        self::assertTrue($simulation->enqueue($commands->join('owner', $ownerIdentity, 'Owner')));
        $simulation->tick();
        $owner = $simulation->authoritativePlayer($ownerIdentity);
        self::assertNotNull($owner);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 2, 2));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
        $llama = $spawn->entity;

        $interaction = $commands->interactEntity(
            'owner',
            $llama->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        );
        self::assertTrue($simulation->enqueue($interaction));
        self::assertTrue($simulation->enqueue($interaction));
        $simulation->tick();
        self::assertSame(LeashHolderType::PLAYER, $llama->getLeashHolderType());
        self::assertSame($ownerIdentity, $llama->getLeashHolderUniqueId());
        self::assertSame(1, $owner->inventory->selectedStack()?->count);

        self::assertTrue($simulation->enqueue($commands->disconnect('owner')));
        self::assertFalse(
            $simulation->enqueue($interaction),
            'A same-batch interaction after disconnect must not enter the authoritative queue.',
        );
        $events = $simulation->tick()->events;
        self::assertFalse($llama->isLeashed());
        self::assertCount(1, self::leadSpawns($events));

        foreach ([0, -1, PHP_INT_MIN] as $runtimeId) {
            try {
                $commands->interactEntity('owner', $runtimeId, 0, EntityInteractionType::INTERACT);
                self::fail('Malformed actor identity reached the simulation queue.');
            } catch (CommandValidationException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testHorseDeathClosesItsWindowExactlyOnceBeforeActorRemoval(): void
    {
        [$simulation, $commands] = self::simulation();
        $ownerIdentity = '00000000-0000-4000-8000-000000000121';
        self::assertTrue($simulation->enqueue($commands->join('owner', $ownerIdentity, 'Owner')));
        $simulation->tick();
        $horse = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(HorseEntity::class, $horse);
        $horse->setOwnerUniqueId($ownerIdentity);
        self::assertTrue($simulation->enqueue($commands->move(
            'owner',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::CROUCHING,
            sneaking: true,
        )));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $horse->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $openEvents = $simulation->tick()->events;
        $opened = array_values(array_filter(
            $openEvents,
            static fn(object $event): bool => $event instanceof \Bedriox\Server\Simulation\Event\ContainerOpened,
        ));
        self::assertCount(1, $opened);
        $windowId = $opened[0]->windowId;

        self::assertTrue($simulation->enqueueKillEntity($horse->getRuntimeId(), $horse->getUniqueId()));
        $lifecycle = [];
        for ($tick = 0; $tick < 40; ++$tick) {
            array_push($lifecycle, ...$simulation->tick()->events);
            if (array_any($lifecycle, static fn(object $event): bool => $event instanceof EntityActorRemoved)) {
                break;
            }
        }
        $closeIndexes = [];
        $removeIndexes = [];
        foreach ($lifecycle as $index => $event) {
            if ($event instanceof ContainerClosed) {
                $closeIndexes[] = $index;
                self::assertTrue($event->serverInitiated);
                self::assertSame($windowId, $event->windowId);
            }
            if ($event instanceof EntityActorRemoved && $event->entity === $horse) {
                $removeIndexes[] = $index;
            }
        }
        self::assertCount(1, $closeIndexes);
        self::assertCount(1, $removeIndexes);
        self::assertLessThan($removeIndexes[0], $closeIndexes[0]);

        self::assertTrue($simulation->enqueue($commands->closeContainer('owner', $windowId)));
        $lateClose = $simulation->tick()->events;
        self::assertCount(1, array_filter(
            $lateClose,
            static fn(object $event): bool => $event instanceof CommandRejected
                && $event->reason === 'container_not_open',
        ));
        self::assertCount(0, array_filter(
            $lateClose,
            static fn(object $event): bool => $event instanceof ContainerClosed,
        ));
    }

    /** @return array{WorldSimulation, SimulationCommandFactory} */
    private static function simulation(?ItemEntityRegistry $items = null): array
    {
        $data = BedrockDataSet::bundled();

        return [
            new WorldSimulation(
                itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry()),
                itemEntities: $items,
                entityTypes: $data->entityTypeRegistry(),
                entityAiEnabled: false,
                spawnAnimals: false,
                spawnMonsters: false,
            ),
            new SimulationCommandFactory(),
        ];
    }

    /** @param list<object> $events
     * @return list<ItemEntitySpawned>
     */
    private static function leadSpawns(array $events): array
    {
        return array_values(array_filter(
            $events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
    }
}
