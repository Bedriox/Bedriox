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
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorLinkType;
use Bedriox\Protocol\Packet\ActorMetadataVector3;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerSlotType;
use Bedriox\Protocol\Packet\PlayerActorMetadata;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorLinkPacket;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\Mount\UndeadHorseEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\DonkeyEntity;
use Bedriox\Server\Entity\Vanilla\HorseEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonHorseEntity;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\ActorDismounted;
use Bedriox\Server\Simulation\Event\ActorMounted;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\EntityActorBodyEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityInteracted;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Collision\PlayerCollisionShape;
use PHPUnit\Framework\TestCase;

final class MountingIntegrationTest extends TestCase
{
    public function testDirectHorseArmorInteractionEquipsWithoutMounting(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($identity);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:diamond_horse_armor', 1, 41));
        $horse = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(HorseEntity::class, $horse);
        $horse->setOwnerUniqueId($identity);
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'rider',
            $horse->getRuntimeId(),
            0,
            EntityInteractionType::ITEM_INTERACT,
        )));
        $events = $simulation->tick()->events;

        self::assertSame('minecraft:diamond_horse_armor', $horse->getHorseArmor()?->identifier);
        self::assertNull($player->inventory->selectedStack());
        self::assertInstanceOf(EntityInteracted::class, self::event($events, EntityInteracted::class));
        self::assertInstanceOf(
            EntityActorBodyEquipmentChanged::class,
            self::event($events, EntityActorBodyEquipmentChanged::class),
        );
        self::assertNull(self::event($events, EntityActorMetadataChanged::class));
        self::assertNull(self::event($events, ActorMounted::class));
    }

    public function testHorseWindowCommitsEquipmentRejectsInvalidSwapsAndReopensAuthoritatively(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();
        $player = $simulation->authoritativePlayer($identity);
        self::assertNotNull($player);
        $player->inventory->replaceSlot(0, new InventoryStack('minecraft:saddle', 1, 41));
        $player->inventory->replaceSlot(1, new InventoryStack('minecraft:diamond_horse_armor', 1, 42));
        $player->inventory->replaceSlot(2, new InventoryStack('minecraft:stone', 1, 43));
        $horse = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(HorseEntity::class, $horse);
        $horse->setOwnerUniqueId($identity);

        self::assertTrue($simulation->enqueue($commands->move(
            'rider',
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
            'rider',
            $horse->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $opened = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertInstanceOf(ContainerOpened::class, $opened);
        self::assertCount(2, $opened->slots);
        self::assertCount(2, $opened->animalEquipmentSlots);

        // Normal movement and AI projection changes must not stale the independent equipment window.
        $horse->moveTo('world', new Position(1.75, 64.0, 0.75), 35.0, 0.0);
        $simulation->tick();

        foreach ([[0, 0], [1, 1]] as [$mainSlot, $horseSlot]) {
            $networkId = $player->inventory->stackAt($mainSlot)?->stackNetworkId;
            self::assertIsInt($networkId);
            self::assertTrue($simulation->enqueue($commands->inventoryStackRequest(
                'rider',
                -100 - $mainSlot,
                [new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::Main, $mainSlot, $networkId),
                    new InventorySlotReference(
                        InventoryContainer::OpenedContainer,
                        $horseSlot,
                        0,
                        ContainerSlotType::HorseEquipment->value,
                    ),
                    1,
                )],
            )));
            $processed = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
            self::assertInstanceOf(InventoryStackRequestProcessed::class, $processed);
            self::assertTrue($processed->success, $processed->reason);
        }
        self::assertTrue($horse->isSaddled());
        self::assertSame('minecraft:diamond_horse_armor', $horse->getHorseArmor()?->identifier);

        $armorNetworkId = $processed->openedContainerInventory[1]?->stackNetworkId;
        self::assertIsInt($armorNetworkId);
        $stoneNetworkId = $player->inventory->stackAt(2)?->stackNetworkId;
        self::assertIsInt($stoneNetworkId);
        self::assertTrue($simulation->enqueue($commands->inventoryStackRequest(
            'rider',
            -103,
            [new InventoryStackRequestAction(
                InventoryStackRequestActionType::Swap,
                new InventorySlotReference(InventoryContainer::Main, 2, $stoneNetworkId),
                new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    1,
                    $armorNetworkId,
                    ContainerSlotType::HorseEquipment->value,
                ),
            )],
        )));
        $rejected = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $rejected);
        self::assertFalse($rejected->success);
        self::assertSame('container_commit', $rejected->reason);
        self::assertSame('minecraft:stone', $player->inventory->stackAt(2)->identifier);
        self::assertSame('minecraft:diamond_horse_armor', $horse->getHorseArmor()->identifier);

        self::assertTrue($simulation->enqueue($commands->closeContainer('rider', $opened->windowId)));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'rider',
            $horse->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $reopened = self::event($simulation->tick()->events, ContainerOpened::class);
        self::assertInstanceOf(ContainerOpened::class, $reopened);
        self::assertSame('minecraft:saddle', $reopened->slots[0]?->identifier);
        self::assertSame('minecraft:diamond_horse_armor', $reopened->slots[1]?->identifier);

        self::assertTrue($simulation->enqueueKillEntity($horse->getRuntimeId(), $horse->getUniqueId()));
        $deathEvents = $simulation->tick()->events;
        $closed = self::event($deathEvents, ContainerClosed::class);
        for ($tick = 0; $tick < 40 && $closed === null; ++$tick) {
            $closed = self::event($simulation->tick()->events, ContainerClosed::class);
        }
        self::assertInstanceOf(ContainerClosed::class, $closed);
        self::assertTrue($closed->serverInitiated);
        $closePackets = (new BedrockWorldEventPacketEncoder())->encode($closed, []);
        self::assertCount(1, $closePackets);
        self::assertInstanceOf(ContainerClosePacket::class, $closePackets[0]->packet);

        $saddle = $reopened->slots[0];
        $saddleNetworkId = $saddle->stackNetworkId;
        self::assertTrue($simulation->enqueue($commands->inventoryStackRequest(
            'rider',
            -104,
            [new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    0,
                    $saddleNetworkId,
                    ContainerSlotType::HorseEquipment->value,
                ),
                new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
                1,
            )],
        )));
        $late = self::event($simulation->tick()->events, InventoryStackRequestProcessed::class);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $late);
        self::assertFalse($late->success);
        self::assertSame('container_not_open', $late->reason);
    }

    public function testSaddledPigInteractionMountsAndVehicleExitDismountsAuthoritatively(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('one', 'identity-one', 'One')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(PigEntity::class, $spawn->entity);
        $spawn->entity->setSaddled(true);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'one',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $mounted = self::event($simulation->tick()->events, ActorMounted::class);
        self::assertInstanceOf(ActorMounted::class, $mounted);
        self::assertSame($spawn->entity, $simulation->mountedVehicle('identity-one'));

        $packets = (new BedrockWorldEventPacketEncoder())->encode($mounted, []);
        self::assertCount(3, $packets);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[0]->packet);
        $seatOffset = null;
        $flags = null;
        foreach ($packets[0]->packet->metadata as $metadata) {
            if ($metadata->id === 56) {
                $seatOffset = $metadata->value;
            }
            if (PlayerActorMetadata::isFlags($metadata)) {
                $flags = $metadata->value;
            }
        }
        self::assertInstanceOf(ActorMetadataVector3::class, $seatOffset);
        self::assertEqualsWithDelta(
            $spawn->entity->mountedPassengerOffsetY(MountSeat::DRIVER, PlayerCollisionShape::HEIGHT, true),
            $seatOffset->y,
            0.000_001,
        );
        self::assertEqualsWithDelta(1.85, $seatOffset->y, 0.01);
        self::assertIsInt($flags);
        self::assertSame(1 << 2, $flags & (1 << 2));
        self::assertInstanceOf(SetActorLinkPacket::class, $packets[1]->packet);
        self::assertSame(ActorLinkType::Rider, $packets[1]->packet->link->type);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[2]->packet);
        self::assertSame($packets[0]->packet, $packets[2]->packet);

        self::assertTrue($simulation->enqueue($commands->dismountPlayer('one')));
        $dismounted = self::event($simulation->tick()->events, ActorDismounted::class);
        self::assertInstanceOf(ActorDismounted::class, $dismounted);
        self::assertNull($simulation->mountedVehicle('identity-one'));

        $packets = (new BedrockWorldEventPacketEncoder())->encode($dismounted, []);
        self::assertCount(2, $packets);
        self::assertInstanceOf(SetActorLinkPacket::class, $packets[0]->packet);
        self::assertSame(ActorLinkType::Remove, $packets[0]->packet->link->type);
    }

    public function testPowerJumpMountDismountResetsTheClientJumpPresentation(): void
    {
        $event = new ActorDismounted(71, 72, null, ['one'], true);

        $packets = (new BedrockWorldEventPacketEncoder())->encode($event, []);

        self::assertCount(2, $packets);
        self::assertInstanceOf(SetActorLinkPacket::class, $packets[0]->packet);
        self::assertSame(ActorLinkType::Remove, $packets[0]->packet->link->type);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[1]->packet);
        self::assertCount(1, $packets[1]->packet->metadata);
        self::assertSame(10, $packets[1]->packet->metadata[0]->id);
        self::assertSame(0, $packets[1]->packet->metadata[0]->value);
    }

    public function testCreativePlayerCanMountPigWhileStillHoldingTheSaddleUsedToEquipIt(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', 'creative-rider', 'Rider')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->changeGameMode('rider', GameMode::CREATIVE)));
        $simulation->tick();
        $simulation->authoritativePlayer('creative-rider')?->inventory->replaceSlot(
            0,
            new InventoryStack('minecraft:saddle', 1, 1),
        );

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(PigEntity::class, $spawn->entity);
        $interact = fn() => $commands->interactEntity(
            'rider',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        );
        self::assertTrue($simulation->enqueue($interact()));
        $simulation->tick();
        self::assertTrue($spawn->entity->isSaddled());
        self::assertTrue($simulation->enqueue($interact()));

        $events = $simulation->tick()->events;
        self::assertInstanceOf(
            ActorMounted::class,
            self::event($events, ActorMounted::class),
            implode(', ', array_map(get_debug_type(...), $events)),
        );
        self::assertSame($spawn->entity, $simulation->mountedVehicle('creative-rider'));
    }

    public function testCreativePlayerCanMountDonkeyWhileStillHoldingTheSaddleUsedToEquipIt(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();
        self::assertTrue($simulation->enqueue($commands->changeGameMode('rider', GameMode::CREATIVE)));
        $simulation->tick();
        $simulation->authoritativePlayer($identity)?->inventory->replaceSlot(
            0,
            new InventoryStack('minecraft:saddle', 1, 1),
        );
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::DONKEY,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(DonkeyEntity::class, $entity);
        $entity->setOwnerUniqueId($identity);
        $interact = fn() => $commands->interactEntity(
            'rider',
            $entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        );

        self::assertTrue($simulation->enqueue($interact()));
        $simulation->tick();
        self::assertTrue($entity->isSaddled());
        self::assertTrue($simulation->enqueue($interact()));
        self::assertInstanceOf(ActorMounted::class, self::event($simulation->tick()->events, ActorMounted::class));
        self::assertSame($entity, $simulation->mountedVehicle($identity));
    }

    public function testTamedLlamaMountsWithAnEmptyHandWithoutAcceptingASaddle(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();
        $entity = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(LlamaEntity::class, $entity);
        $entity->setOwnerUniqueId($identity);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'rider',
            $entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertInstanceOf(ActorMounted::class, self::event($simulation->tick()->events, ActorMounted::class));
        self::assertFalse($entity->isSaddled());
        self::assertSame($entity, $simulation->mountedVehicle($identity));
    }

    public function testEntityControllerUsesTheSameBoundedRelationshipRegistry(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $passenger = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::PLUGIN,
            'world',
            new Position(0.0, 64.0, 0.0),
        ))->entity;
        $vehicle = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::PLUGIN,
            'world',
            new Position(1.0, 64.0, 0.0),
        ))->entity;
        self::assertNotNull($passenger);
        self::assertNotNull($vehicle);

        $passenger->getController()->mount($vehicle, MountSeat::PASSENGER_1);
        self::assertSame($vehicle, $passenger->getVehicle());
        self::assertTrue($vehicle->hasPassengers());
        self::assertCount(1, $vehicle->getPassengers());

        $passenger->getController()->dismount();
        self::assertNull($passenger->getVehicle());
        self::assertFalse($vehicle->hasPassengers());
    }

    public function testTamedSaddledHorseUsesTheExistingAuthoritativeMountPath(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(HorseEntity::class, $spawn->entity);
        $spawn->entity->setOwnerUniqueId($identity);
        $spawn->entity->setSaddled(true);

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'rider',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        $mounted = self::event($simulation->tick()->events, ActorMounted::class);
        self::assertInstanceOf(ActorMounted::class, $mounted);
        self::assertSame($spawn->entity, $simulation->mountedVehicle($identity));

        $packets = (new BedrockWorldEventPacketEncoder())->encode($mounted, []);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[0]->packet);
        $seatOffset = null;
        foreach ($packets[0]->packet->metadata as $metadata) {
            if ($metadata->id === 56) {
                $seatOffset = $metadata->value;
            }
        }
        self::assertInstanceOf(ActorMetadataVector3::class, $seatOffset);
        self::assertEqualsWithDelta(0.0, $seatOffset->x, 0.000_001);
        self::assertEqualsWithDelta(2.32, $seatOffset->y, 0.000_001);
        self::assertEqualsWithDelta(-0.2, $seatOffset->z, 0.000_001);
    }

    public function testMountedHorseFacesItsAuthoritativeControlDirection(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(HorseEntity::class, $spawn->entity);
        $spawn->entity->setOwnerUniqueId($identity);
        $spawn->entity->setSaddled(true);
        self::assertTrue($simulation->enqueueMount($identity, $spawn->entity));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->move(
            'rider',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            moveZ: 1.0,
            vehiclePitch: 0.0,
            vehicleYaw: 90.0,
            vehicleControlYaw: 0.0,
            predictedVehicleActorId: $spawn->entity->getRuntimeId(),
        )));
        $simulation->tick();

        self::assertSame(0.0, $spawn->entity->getYaw());
        self::assertEqualsWithDelta(0.0, $spawn->entity->getMotion()->x, 0.000_001);
        self::assertGreaterThan(0.1, $spawn->entity->getMotion()->z);

        self::assertTrue($simulation->enqueue($commands->move(
            'rider',
            2,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            moveX: 1.0,
            vehiclePitch: 0.0,
            vehicleYaw: 0.0,
            vehicleControlYaw: 0.0,
            predictedVehicleActorId: $spawn->entity->getRuntimeId(),
        )));
        $simulation->tick();

        self::assertSame(270.0, $spawn->entity->getYaw());
        self::assertGreaterThan(0.1, $spawn->entity->getMotion()->x);
        self::assertEqualsWithDelta(0.0, $spawn->entity->getMotion()->z, 0.000_001);

        self::assertTrue($simulation->enqueue($commands->move(
            'rider',
            3,
            0.0,
            64.0,
            0.0,
            30.0,
            0.0,
            MovementMode::WALKING,
            moveZ: 1.0,
            vehiclePitch: 0.0,
            vehicleYaw: 180.0,
            vehicleControlYaw: 180.0,
            predictedVehicleActorId: $spawn->entity->getRuntimeId() + 1,
        )));
        $events = $simulation->tick()->events;

        self::assertInstanceOf(MovementCorrected::class, self::event($events, MovementCorrected::class));
        self::assertSame(270.0, $spawn->entity->getYaw());
    }

    public function testSkeletonHorseIsIntrinsicallyTamedAndMountsWithoutSaddle(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();

        $spawn = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::SKELETON_HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ));
        self::assertInstanceOf(SkeletonHorseEntity::class, $spawn->entity);
        self::assertTrue($spawn->entity->isTamed());
        self::assertFalse($spawn->entity->isSaddled());

        self::assertTrue($simulation->enqueue($commands->interactEntity(
            'rider',
            $spawn->entity->getRuntimeId(),
            0,
            EntityInteractionType::INTERACT,
        )));
        self::assertInstanceOf(ActorMounted::class, self::event($simulation->tick()->events, ActorMounted::class));
        self::assertSame($spawn->entity, $simulation->mountedVehicle($identity));
    }

    public function testHorseAndSkeletonHorseApplyMountedJumpInput(): void
    {
        foreach ([VanillaEntityType::HORSE, VanillaEntityType::SKELETON_HORSE] as $type) {
            $identity = EntityUuid::random();
            $simulation = new WorldSimulation(entityAiEnabled: false);
            $commands = new SimulationCommandFactory();
            self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
            $simulation->tick();
            $entity = $simulation->spawnEntity(new EntitySpawnRequest(
                $type,
                SpawnCause::COMMAND,
                'world',
                new Position(1.5, 64.0, 0.5),
            ))->entity;
            self::assertTrue($entity instanceof HorseFamilyEntity || $entity instanceof UndeadHorseEntity);
            $entity->setOwnerUniqueId($identity);
            if (!$entity instanceof SkeletonHorseEntity) {
                $entity->setSaddled(true);
            }
            $entity->setOnGround(true);
            self::assertTrue($simulation->enqueueMount($identity, $entity));
            $simulation->tick();

            self::assertTrue($simulation->enqueue($commands->move(
                'rider',
                1,
                0.0,
                64.0,
                0.0,
                0.0,
                0.0,
                MovementMode::JUMPING,
                jumpRequested: true,
                moveZ: 1.0,
                vehiclePitch: 0.0,
                vehicleYaw: 0.0,
                vehicleControlYaw: 0.0,
                predictedVehicleActorId: $entity->getRuntimeId(),
            )));
            $simulation->tick();

            self::assertEqualsWithDelta(0.42, $entity->getMotion()->y, 0.000_001, $type->value);
        }
    }

    public function testEverySteerableHorseFamilyUsesTheSharedTravelRotation(): void
    {
        foreach ([
            VanillaEntityType::HORSE,
            VanillaEntityType::DONKEY,
            VanillaEntityType::MULE,
            VanillaEntityType::CAMEL,
            VanillaEntityType::SKELETON_HORSE,
            VanillaEntityType::ZOMBIE_HORSE,
        ] as $type) {
            $identity = EntityUuid::random();
            $simulation = new WorldSimulation(entityAiEnabled: false);
            $commands = new SimulationCommandFactory();
            self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
            $simulation->tick();

            $entity = $simulation->spawnEntity(new EntitySpawnRequest(
                $type,
                SpawnCause::COMMAND,
                'world',
                new Position(1.5, 64.0, 0.5),
            ))->entity;
            if (!$entity instanceof HorseFamilyEntity && !$entity instanceof UndeadHorseEntity) {
                self::fail($type->value . ' did not resolve to a supported horse-family entity.');
            }
            $entity->setOwnerUniqueId($identity);
            if (!$entity instanceof SkeletonHorseEntity) {
                $entity->setSaddled(true);
            }
            self::assertTrue($simulation->enqueueMount($identity, $entity));
            $simulation->tick();

            self::assertTrue($simulation->enqueue($commands->move(
                'rider',
                1,
                0.0,
                64.0,
                0.0,
                0.0,
                0.0,
                MovementMode::WALKING,
                moveX: 1.0,
                vehiclePitch: 0.0,
                vehicleYaw: 0.0,
                vehicleControlYaw: 0.0,
                predictedVehicleActorId: $entity->getRuntimeId(),
            )));
            $simulation->tick();

            self::assertSame(270.0, $entity->getYaw(), $type->value);
            self::assertGreaterThan(0.1, $entity->getMotion()->x, $type->value);
            self::assertEqualsWithDelta(0.0, $entity->getMotion()->z, 0.000_001, $type->value);
        }
    }

    public function testSteerableEquinesProjectTheClientControlFlagsMissingFromLlamas(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $projector = new BedrockLivingActorProjector();
        $horse = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        $donkey = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::DONKEY,
            SpawnCause::COMMAND,
            'world',
            new Position(4.5, 64.0, 0.5),
        ))->entity;
        $skeletonHorse = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::SKELETON_HORSE,
            SpawnCause::COMMAND,
            'world',
            new Position(7.5, 64.0, 0.5),
        ))->entity;
        $llama = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(10.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(HorseEntity::class, $horse);
        self::assertInstanceOf(DonkeyEntity::class, $donkey);
        self::assertInstanceOf(SkeletonHorseEntity::class, $skeletonHorse);
        self::assertInstanceOf(LlamaEntity::class, $llama);
        $horse->setSaddled(true);
        $donkey->setSaddled(true);

        foreach ([$horse, $donkey, $skeletonHorse] as $entity) {
            $flags = $projector->flagsMetadata($entity)->value;
            self::assertIsInt($flags);
            self::assertNotSame(0, $flags & ActorFlag::WasdControlled->mask());
            self::assertNotSame(0, $flags & ActorFlag::CanPowerJump->mask());
            $jumpAttributes = array_values(array_filter(
                $projector->spawnAttributes($entity),
                static fn($attribute): bool => $attribute->name === 'minecraft:horse.jump_strength',
            ));
            self::assertCount(1, $jumpAttributes);
            self::assertEqualsWithDelta(0.7, $jumpAttributes[0]->value, 0.000_001);
        }
        $llamaFlags = $projector->flagsMetadata($llama)->value;
        self::assertIsInt($llamaFlags);
        self::assertSame(0, $llamaFlags & ActorFlag::WasdControlled->mask());
        self::assertSame(0, $llamaFlags & ActorFlag::CanPowerJump->mask());
    }

    public function testMountedLlamaRejectsSpoofedVehicleControlEvenWithCorruptSaddleState(): void
    {
        $identity = EntityUuid::random();
        $simulation = new WorldSimulation(entityAiEnabled: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('rider', $identity, 'Rider')));
        $simulation->tick();
        $llama = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::LLAMA,
            SpawnCause::COMMAND,
            'world',
            new Position(1.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(LlamaEntity::class, $llama);
        $llama->setOwnerUniqueId($identity);
        $llama->setSaddled(true);
        self::assertTrue($simulation->enqueueMount($identity, $llama));
        $simulation->tick();

        self::assertTrue($simulation->enqueue($commands->move(
            'rider',
            1,
            0.0,
            64.0,
            0.0,
            0.0,
            0.0,
            MovementMode::WALKING,
            moveX: 1.0,
            moveZ: 1.0,
            vehiclePitch: 0.0,
            vehicleYaw: 90.0,
            vehicleControlYaw: 90.0,
            predictedVehicleActorId: $llama->getRuntimeId(),
        )));
        $simulation->tick();

        self::assertSame(0.0, $llama->getMotion()->x);
        self::assertSame(0.0, $llama->getMotion()->z);
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
