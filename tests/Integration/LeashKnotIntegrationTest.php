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
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\Leash\LeashAttachmentRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Position;
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
use ReflectionProperty;

final class LeashKnotIntegrationTest extends TestCase
{
    public function testPlayerLeashTransfersToOneFenceKnotAndLateViewerSeesHolderFirst(): void
    {
        [$simulation, $world, $states] = self::simulation();
        $fence = new BlockPosition(0, 64, 1);
        $fenceState = $states->internalId(CanonicalBlockState::from(
            'minecraft:oak_fence',
            [
                'minecraft:connection_east' => 0,
                'minecraft:connection_north' => 0,
                'minecraft:connection_south' => 0,
                'minecraft:connection_west' => 0,
            ],
        ));
        $world->setBlockState($fence->x, $fence->y, $fence->z, $fenceState);
        $commands = new SimulationCommandFactory();
        $identity = '00000000-0000-4000-8000-000000000001';
        self::assertTrue($simulation->enqueue($commands->join('owner', $identity, 'Owner')));
        $simulation->tick();
        $owner = $simulation->authoritativePlayer($identity);
        self::assertNotNull($owner);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 1, 1));

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'leash-knot-test',
            new Position(1.5, 64.0, 1.5),
        ));
        self::assertInstanceOf(LlamaEntity::class, $spawn->entity);
        $llama = $spawn->entity;
        $llama->setGravityEnabled(false);
        $llama->setImmobile(true);
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $llama->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $interactionEvents = $simulation->tick()->events;
        self::assertSame(
            LeashHolderType::PLAYER,
            $llama->getLeashHolderType(),
            implode(', ', array_map(
                static fn(object $event): string => $event::class,
                $interactionEvents,
            )),
        );
        self::assertSame($identity, $llama->getLeashHolderUniqueId());
        self::assertNull($owner->inventory->selectedStack());
        $leashes = (new ReflectionProperty(WorldSimulation::class, 'leashes'))->getValue($simulation);
        self::assertInstanceOf(LeashAttachmentRegistry::class, $leashes);
        self::assertCount(1, $leashes->transferableFromPlayer(
            $owner,
            LeashKnotEntity::anchorPosition($fence),
        ));
        self::assertSame(
            'minecraft:oak_fence',
            $states->state($world->blockStateAt($fence->x, $fence->y, $fence->z))->identifier(),
        );

        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'owner',
            1,
            $fence,
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $placementEvents = $simulation->tick()->events;
        self::assertSame(
            'minecraft:oak_fence',
            $states->state($world->blockStateAt($fence->x, $fence->y, $fence->z))->identifier(),
        );
        self::assertSame(LeashHolderType::ENTITY, $llama->getLeashHolderType(), implode(', ', array_map(
            static fn(object $event): string => $event::class,
            $placementEvents,
        )));
        $knots = array_values(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn($entity): bool => $entity instanceof LeashKnotEntity,
        ));
        self::assertCount(1, $knots, implode(', ', array_map(
            static fn(object $event): string => $event::class,
            $placementEvents,
        )));
        $knot = $knots[0];
        self::assertInstanceOf(LeashKnotEntity::class, $knot);
        self::assertSame(LeashHolderType::ENTITY, $llama->getLeashHolderType());
        self::assertSame($knot->getUniqueId(), $llama->getLeashHolderUniqueId());
        self::assertSame($knot->getRuntimeId(), $llama->getLeashHolderRuntimeId());

        $lateViewer = new Player(
            'late',
            9_000_000,
            new PlayerIdentity('00000000-0000-4000-8000-000000000002', 'Late'),
            new Position(0.5, 64.0, 0.5),
            8,
            0,
            64.0,
        );
        $arrival = $simulation->attachTransferredPlayer(
            $lateViewer,
            'leash-knot-test',
            $lateViewer->movement->position,
            0.0,
            0.0,
        );
        $spawnOrder = [];
        foreach ($arrival->events as $event) {
            if ($event instanceof EntityActorSpawned) {
                $spawnOrder[] = $event->entity->getRuntimeId();
            }
        }
        $knotIndex = array_search($knot->getRuntimeId(), $spawnOrder, true);
        $llamaIndex = array_search($llama->getRuntimeId(), $spawnOrder, true);
        self::assertNotFalse($knotIndex);
        self::assertNotFalse($llamaIndex);
        self::assertLessThan($llamaIndex, $knotIndex);

        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'owner',
            2,
            $fence,
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $simulation->tick();
        self::assertCount(1, array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn($entity): bool => $entity instanceof LeashKnotEntity,
        ));

        $world->setBlockState(
            $fence->x,
            $fence->y,
            $fence->z,
            $states->internalId(CanonicalBlockState::from('minecraft:air')),
        );
        $cleanupEvents = $simulation->tick()->events;
        self::assertNull($simulation->entityRuntime()->registry()->getByRuntimeId($knot->getRuntimeId()));
        self::assertFalse($llama->isLeashed());
        self::assertCount(1, array_filter(
            $cleanupEvents,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
        self::assertCount(0, array_filter(
            $simulation->tick()->events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));

        $world->setBlockState($fence->x, $fence->y, $fence->z, $fenceState);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 1, 1));
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $llama->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->placeBlock(
            'owner',
            3,
            $fence,
            1,
            0,
            0,
            0.5,
            0.5,
            0.5,
        )));
        $simulation->tick();
        $replacementKnots = array_values(array_filter(
            $simulation->entityRuntime()->registry()->all(),
            static fn($entity): bool => $entity instanceof LeashKnotEntity,
        ));
        self::assertCount(1, $replacementKnots);
        $replacement = $replacementKnots[0];
        self::assertInstanceOf(LeashKnotEntity::class, $replacement);
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $replacement->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $interactionRemoval = $simulation->tick()->events;
        self::assertNull($simulation->entityRuntime()->registry()->getByRuntimeId($replacement->getRuntimeId()));
        $interactionTypes = array_map(static fn(object $event): string => $event::class, $interactionRemoval);
        $metadataIndex = array_search(EntityActorMetadataChanged::class, $interactionTypes, true);
        $removalIndex = array_search(EntityActorRemoved::class, $interactionTypes, true);
        self::assertNotFalse($metadataIndex);
        self::assertNotFalse($removalIndex);
        self::assertLessThan($removalIndex, $metadataIndex);
        self::assertCount(1, array_filter(
            $interactionRemoval,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'owner',
            $replacement->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertCount(0, array_filter(
            $simulation->tick()->events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
    }

    public function testDisconnectClearsPlayerHeldLeashBeforeRemovingTheHolder(): void
    {
        [$simulation] = self::simulation();
        $commands = new SimulationCommandFactory();
        $ownerIdentity = '00000000-0000-4000-8000-000000000011';
        self::assertTrue($simulation->enqueue($commands->join('owner', $ownerIdentity, 'Owner')));
        self::assertTrue($simulation->enqueue($commands->join(
            'peer',
            '00000000-0000-4000-8000-000000000012',
            'Peer',
        )));
        $simulation->tick();
        $owner = $simulation->authoritativePlayer($ownerIdentity);
        self::assertNotNull($owner);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 1, 1));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'leash-knot-test',
            new Position(1.5, 64.0, 1.5),
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
        self::assertTrue($llama->isLeashed());

        self::assertTrue($simulation->enqueue($commands->disconnect('owner')));
        $events = $simulation->tick()->events;
        $types = array_map(static fn(object $event): string => $event::class, $events);
        $metadataIndex = array_search(EntityActorMetadataChanged::class, $types, true);
        $disconnectIndex = array_search(PlayerDisconnected::class, $types, true);
        self::assertNotFalse($metadataIndex);
        self::assertNotFalse($disconnectIndex);
        self::assertLessThan($disconnectIndex, $metadataIndex);
        self::assertSame(['peer'], $events[$metadataIndex]->recipients());
        self::assertFalse($llama->isLeashed());
        self::assertCount(1, array_filter(
            $events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));

        self::assertTrue($simulation->enqueue($commands->join('owner-2', $ownerIdentity, 'Owner')));
        $simulation->tick();
        self::assertFalse($llama->isLeashed());
        self::assertCount(0, array_filter(
            $simulation->tick()->events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
    }

    public function testWorldTransferClearsPlayerHeldLeashBeforeSourceVisibilityRemoval(): void
    {
        [$simulation] = self::simulation();
        $commands = new SimulationCommandFactory();
        $ownerIdentity = '00000000-0000-4000-8000-000000000021';
        self::assertTrue($simulation->enqueue($commands->join('owner', $ownerIdentity, 'Owner')));
        self::assertTrue($simulation->enqueue($commands->join(
            'peer',
            '00000000-0000-4000-8000-000000000022',
            'Peer',
        )));
        $simulation->tick();
        $owner = $simulation->authoritativePlayer($ownerIdentity);
        self::assertNotNull($owner);
        $owner->inventory->replaceSlot(0, new InventoryStack('minecraft:lead', 1, 1));
        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'leash-knot-test',
            new Position(1.5, 64.0, 1.5),
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

        $departure = $simulation->detachPlayerForTransfer('owner');
        self::assertNotNull($departure);
        $types = array_map(static fn(object $event): string => $event::class, $departure->events);
        $metadataIndex = array_search(EntityActorMetadataChanged::class, $types, true);
        $disconnectIndex = array_search(PlayerDisconnected::class, $types, true);
        self::assertNotFalse($metadataIndex);
        self::assertNotFalse($disconnectIndex);
        self::assertLessThan($disconnectIndex, $metadataIndex);
        self::assertFalse($llama->isLeashed());
        self::assertCount(1, array_filter(
            $departure->events,
            static fn(object $event): bool => $event instanceof ItemEntitySpawned
                && $event->entity->stack->identifier === 'minecraft:lead',
        ));
    }

    /** @return array{WorldSimulation, World, BlockStateRegistry} */
    private static function simulation(): array
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $world = new World(
            new WorldMetadata('leash-knot-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $world->retainChunk(new ChunkPosition(0, 0));

        return [
            new WorldSimulation(
                blockWorld: $world,
                blockPalette: $palette,
                blockStateRegistry: $states,
                itemCatalog: ItemCatalog::vanilla($data->itemNetworkRegistry()),
                entityTypes: $data->entityTypeRegistry(),
                entityAiEnabled: false,
                spawnAnimals: false,
                spawnMonsters: false,
            ),
            $world,
            $states,
        ];
    }
}
