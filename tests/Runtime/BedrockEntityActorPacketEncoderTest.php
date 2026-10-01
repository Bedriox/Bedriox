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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\ActorAttribute;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEffectEvent;
use Bedriox\Protocol\Packet\MobEffectPacket;
use Bedriox\Protocol\Packet\MobEffectType;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\EntityActorAttackStarted;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorEffectChanged;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class BedrockEntityActorPacketEncoderTest extends TestCase
{
    public function testLivingActorEffectsReplayOnSpawnAndProjectLiveRemoval(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000200',
            200,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $effect = new EffectInstance(EffectType::INVISIBILITY, 200);
        $zombie->addEffect($effect);
        $encoder = new BedrockWorldEventPacketEncoder();

        $spawned = $encoder->encode(new EntityActorSpawned($zombie, ['viewer'], false), []);
        $mobEffects = array_values(array_filter(
            $spawned,
            static fn($packet): bool => $packet->packet instanceof MobEffectPacket,
        ));
        self::assertCount(1, $mobEffects);
        $spawnEffect = $mobEffects[0]->packet;
        self::assertInstanceOf(MobEffectPacket::class, $spawnEffect);
        self::assertSame(MobEffectType::Invisibility, $spawnEffect->effect);
        self::assertSame(MobEffectEvent::Add, $spawnEffect->event);

        $zombie->removeEffect(EffectType::INVISIBILITY);
        $removed = $encoder->encode(new EntityActorEffectChanged(
            $zombie,
            EffectType::INVISIBILITY,
            null,
            10,
            false,
            ['viewer'],
        ), []);
        self::assertCount(2, $removed);
        self::assertInstanceOf(MobEffectPacket::class, $removed[0]->packet);
        self::assertSame(MobEffectEvent::Remove, $removed[0]->packet->event);
        self::assertInstanceOf(SetActorDataPacket::class, $removed[1]->packet);
    }

    public function testFireResistanceSuppressesLivingActorCombustion(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000202',
            202,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $zombie->addEffect(new EffectInstance(EffectType::FIRE_RESISTANCE, 200));
        $zombie->setOnFire(100);
        self::assertFalse($zombie->isOnFire());

        $zombie->removeEffect(EffectType::FIRE_RESISTANCE);
        $zombie->setOnFire(100);
        self::assertTrue($zombie->isOnFire());
    }

    public function testLivingActorCapacityEffectsProjectHealthAndAbsorptionAttributes(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000203',
            203,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $effect = new EffectInstance(EffectType::ABSORPTION, 200, 1);
        $zombie->addEffect($effect);

        $packets = (new BedrockWorldEventPacketEncoder())->encode(new EntityActorEffectChanged(
            $zombie,
            EffectType::ABSORPTION,
            $effect,
            20,
            false,
            ['viewer'],
        ), []);

        self::assertCount(2, $packets);
        self::assertInstanceOf(MobEffectPacket::class, $packets[0]->packet);
        self::assertInstanceOf(UpdateAttributesPacket::class, $packets[1]->packet);
        self::assertSame(
            [ActorAttribute::HEALTH, 'minecraft:absorption'],
            array_map(static fn($attribute): string => $attribute->name, $packets[1]->packet->attributes),
        );
        self::assertSame(8.0, $packets[1]->packet->attributes[1]->value);
    }


    public function testZombieAttackStateIsVisibleOnlyToSuppliedRecipients(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000201',
            201,
            'world',
            new Position(0.0, 64.0, 0.0),
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new EntityActorAttackStarted($zombie, ['viewer-a', 'viewer-b']),
            [],
        );

        self::assertSame(['viewer-a', 'viewer-b'], array_map(
            static fn($entry): string => $entry->sessionId,
            $packets,
        ));
        foreach ($packets as $entry) {
            self::assertInstanceOf(ActorEventPacket::class, $entry->packet);
            self::assertTrue($entry->packet->runtimeEntityId->equals(UnsignedLong::fromInt(201)));
            self::assertSame(ActorEventType::AttackStart, $entry->packet->event);
        }
        self::assertSame($packets[0]->packet, $packets[1]->packet);
    }

    public function testEntityMovementPacketsPreserveRecipientOrderAndShareProjectionObjects(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000203',
            203,
            'world',
            new Position(4.0, 65.0, -2.0),
        );
        $zombie->moveTo('world', new Position(5.0, 66.0, -1.0), 35.0, -12.0);
        $zombie->setMotion(new EntityMotion(0.2, 0.1, -0.3));
        $zombie->setOnGround(false);
        $event = new EntityActorMoved($zombie, 81, ['viewer-a', 'viewer-b'], true);
        $encoder = new BedrockWorldEventPacketEncoder();

        $shared = $encoder->entityMovementPackets($event);
        $directed = $encoder->encode($event, []);

        self::assertCount(2, $shared);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $shared[0]);
        self::assertInstanceOf(SetActorMotionPacket::class, $shared[1]);
        self::assertSame(
            ['viewer-a', 'viewer-a', 'viewer-b', 'viewer-b'],
            array_map(static fn($entry): string => $entry->sessionId, $directed),
        );
        self::assertSame($directed[0]->packet, $directed[2]->packet);
        self::assertSame($directed[1]->packet, $directed[3]->packet);
        foreach ($directed as $index => $entry) {
            $expected = $shared[$index % 2];
            self::assertSame($expected::class, $entry->packet::class);
            self::assertSame(
                BedrockPacketCodec::encode($expected),
                BedrockPacketCodec::encode($entry->packet),
            );
        }
    }

    public function testCowSpawnUsesCompleteLivingBaselineForOnlyChunkVisibleRecipients(): void
    {
        $cow = new CowEntity(
            '00000000-0000-4000-8000-000000000100',
            100,
            'world',
            new Position(2.0, 65.0, 3.0),
            motion: new EntityMotion(0.1, 0.0, -0.2),
            yaw: 45.0,
            pitch: 10.0,
        );
        $chunkVisibleRecipients = ['viewer-a', 'viewer-b'];

        $directed = (new BedrockWorldEventPacketEncoder())->encode(
            new EntityActorSpawned($cow, $chunkVisibleRecipients, true),
            [],
        );

        self::assertSame([
            'viewer-a', 'viewer-a', 'viewer-a', 'viewer-a',
            'viewer-b', 'viewer-b', 'viewer-b', 'viewer-b',
        ], array_map(static fn($entry): string => $entry->sessionId, $directed));
        self::assertCount(8, $directed);
        foreach ([$directed[0], $directed[4]] as $entry) {
            self::assertInstanceOf(AddActorPacket::class, $entry->packet);
            self::assertSame(100, $entry->packet->actorUniqueId);
            self::assertTrue($entry->packet->runtimeEntityId->equals(UnsignedLong::fromInt(100)));
            self::assertSame('minecraft:cow', $entry->packet->identifier);
            self::assertSame([], $entry->packet->properties->integers);
            self::assertSame([], $entry->packet->properties->floats);
            self::assertSame([], $entry->packet->links);
            self::assertSame(
                $entry->packet->encode(),
                BedrockPacketCodec::decode($entry->packet->packetId(), $entry->packet->encode())->encode(),
            );
        }

        $packet = $directed[0]->packet;
        self::assertInstanceOf(AddActorPacket::class, $packet);
        self::assertSame(
            [
                'minecraft:health',
                'minecraft:follow_range',
                'minecraft:knockback_resistance',
                'minecraft:movement',
                'minecraft:attack_damage',
                'minecraft:absorption',
            ],
            array_map(static fn($attribute): string => $attribute->name, $packet->attributes),
        );
        self::assertSame(10.0, $packet->attributes[0]->maximum);
        self::assertSame(10.0, $packet->attributes[0]->value);
        self::assertSame(
            [0, 1, 3, 4, 5, 6, 7, 37, 38, 42, 53, 54, 81, 92, 120, 131],
            array_map(static fn(ActorMetadata $metadata): int => $metadata->id, $packet->metadata),
        );
        self::assertSame(0.9, self::metadataFloat($packet->metadata, 53));
        self::assertSame(1.4, self::metadataFloat($packet->metadata, 54));
        $flags = self::metadataInteger($packet->metadata, 0);
        foreach ([ActorFlag::Breathing, ActorFlag::HasCollision, ActorFlag::HasGravity, ActorFlag::NoAi] as $flag) {
            self::assertNotSame(0, $flags & $flag->mask());
        }
        self::assertSame(0, $flags & ActorFlag::CanClimb->mask());
        self::assertNotContains(130, array_map(static fn(ActorMetadata $metadata): int => $metadata->id, $packet->metadata));
    }

    public function testZombieLifecyclePreservesPerRecipientPacketOrder(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000200',
            200,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $encoder = new BedrockWorldEventPacketEncoder();
        $packets = $encoder->encode(new EntityActorSpawned($zombie, ['observer']), []);

        $zombie->moveTo('world', new Position(1.0, 64.0, 2.0), 90.0, -15.0);
        $zombie->setMotion(new EntityMotion(0.25, 0.0, -0.5));
        $zombie->setOnGround(true);
        array_push($packets, ...$encoder->encode(new EntityActorMoved($zombie, 41, ['observer']), []));

        self::assertSame(4.0, $zombie->damage(4.0));
        array_push($packets, ...$encoder->encode(new EntityActorDamaged($zombie, 42, ['observer']), []));

        self::assertSame(16.0, $zombie->damage(20.0));
        array_push($packets, ...$encoder->encode(new EntityActorDied($zombie, ['observer']), []));
        $zombie->remove();
        array_push($packets, ...$encoder->encode(new EntityActorRemoved($zombie, ['observer']), []));

        self::assertSame(
            [
                AddActorPacket::class,
                MobArmorEquipmentPacket::class,
                MobEquipmentPacket::class,
                MobEquipmentPacket::class,
                MoveActorAbsolutePacket::class,
                SetActorMotionPacket::class,
                UpdateAttributesPacket::class,
                ActorEventPacket::class,
                ActorEventPacket::class,
                RemoveActorPacket::class,
            ],
            array_map(static fn($entry): string => $entry->packet::class, $packets),
        );
        self::assertSame(array_fill(0, 10, 'observer'), array_map(static fn($entry): string => $entry->sessionId, $packets));

        $spawn = $packets[0]->packet;
        self::assertInstanceOf(AddActorPacket::class, $spawn);
        self::assertSame('minecraft:zombie', $spawn->identifier);
        self::assertSame(0.6, self::metadataFloat($spawn->metadata, 53));
        self::assertSame(1.95, self::metadataFloat($spawn->metadata, 54));
        self::assertSame([], $spawn->properties->integers);
        self::assertSame([], $spawn->properties->floats);
        self::assertSame([], $spawn->links);

        $movement = $packets[4]->packet;
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $movement);
        self::assertTrue($movement->onGround());
        self::assertSame(1.0, $movement->x);
        self::assertSame(64.0, $movement->y);
        self::assertSame(2.0, $movement->z);
        $motion = $packets[5]->packet;
        self::assertInstanceOf(SetActorMotionPacket::class, $motion);
        self::assertSame(0.25, $motion->motionX);
        self::assertSame(-0.5, $motion->motionZ);
        self::assertTrue($motion->tick->equals(UnsignedLong::fromInt(41)));

        $health = $packets[6]->packet;
        self::assertInstanceOf(UpdateAttributesPacket::class, $health);
        self::assertCount(2, $health->attributes);
        self::assertSame(ActorAttribute::HEALTH, $health->attributes[0]->name);
        self::assertSame(16.0, $health->attributes[0]->value);
        self::assertSame(20.0, $health->attributes[0]->maximum);
        self::assertSame('minecraft:absorption', $health->attributes[1]->name);
        self::assertSame(0.0, $health->attributes[1]->value);
        self::assertTrue($health->tick->equals(UnsignedLong::fromInt(42)));

        self::assertInstanceOf(ActorEventPacket::class, $packets[7]->packet);
        self::assertSame(ActorEventType::Hurt, $packets[7]->packet->event);
        self::assertInstanceOf(ActorEventPacket::class, $packets[8]->packet);
        self::assertSame(ActorEventType::Death, $packets[8]->packet->event);
        self::assertInstanceOf(RemoveActorPacket::class, $packets[9]->packet);
        self::assertSame(200, $packets[9]->packet->actorUniqueId);
    }

    public function testAuthoritativeFireStateIsProjectedAtSpawnAndOnChange(): void
    {
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000202',
            202,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $zombie->setOnFire(160);
        $encoder = new BedrockWorldEventPacketEncoder();

        $spawn = $encoder->encode(new EntityActorSpawned($zombie, ['observer']), [])[0]->packet;
        self::assertInstanceOf(AddActorPacket::class, $spawn);
        self::assertNotSame(0, self::metadataInteger($spawn->metadata, 0) & ActorFlag::OnFire->mask());

        $changed = $encoder->encode(new EntityActorMetadataChanged($zombie, 20, ['observer']), [])[0]->packet;
        self::assertInstanceOf(SetActorDataPacket::class, $changed);
        self::assertNotSame(0, self::metadataInteger($changed->metadata, 0) & ActorFlag::OnFire->mask());

        $zombie->extinguish();
        $extinguished = $encoder->encode(new EntityActorMetadataChanged($zombie, 21, ['observer']), [])[0]->packet;
        self::assertInstanceOf(SetActorDataPacket::class, $extinguished);
        self::assertSame(0, self::metadataInteger($extinguished->metadata, 0) & ActorFlag::OnFire->mask());
    }

    public function testEveryAdmittedCatalogMobHasACompleteRoundTripSafeSpawnProjection(): void
    {
        $catalog = BedrockDataSet::bundled()->entityTypeRegistry();
        $definitions = EntityDefinitionRegistry::fromData($catalog)->all();
        $encoder = new BedrockWorldEventPacketEncoder();

        self::assertGreaterThan(2, count($definitions));
        foreach ($definitions as $index => $registration) {
            $source = $catalog->definitionForIdentifier($registration->definition->type->identifier());
            self::assertTrue($source->hasSpawnEgg(), $source->identifier());
            self::assertNotSame(EntityCategory::MISCELLANEOUS, $registration->definition->category);
            $entity = ($registration->factory)(
                sprintf('00000000-0000-4000-8000-%012d', $index + 1),
                10_000 + $index,
                'world',
                new Position(0.5, 64.0, 0.5),
                0.0,
                0.0,
            );
            self::assertInstanceOf(AbstractLivingEntity::class, $entity);
            $directed = $encoder->encode(new EntityActorSpawned($entity, ['viewer']), []);

            self::assertCount(4, $directed, $source->identifier());
            $packet = $directed[0]->packet;
            self::assertInstanceOf(AddActorPacket::class, $packet);
            self::assertSame($source->identifier(), $packet->identifier);
            self::assertSame(
                $packet->encode(),
                BedrockPacketCodec::decode($packet->packetId(), $packet->encode())->encode(),
                $source->identifier(),
            );
        }
    }

    public function testEntityEquipmentFollowsActorSpawnAndLiveChangesAreProjectedBySlotGroup(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $inventory = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
        $encoder = new BedrockWorldEventPacketEncoder(inventory: $inventory);
        $zombie = new ZombieEntity(
            '00000000-0000-4000-8000-000000000203',
            203,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
        $zombie->configureEquipmentCatalog(ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        ));
        $zombie->equipmentState()->setItem(EquipmentSlot::HEAD, new ItemStack('minecraft:iron_helmet', 1));
        $zombie->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:iron_sword', 1));
        $zombie->equipmentState()->setItem(EquipmentSlot::OFF_HAND, new ItemStack('minecraft:stone_sword', 1));

        $spawn = $encoder->encode(new EntityActorSpawned($zombie, ['observer']), []);

        self::assertSame([
            AddActorPacket::class,
            MobArmorEquipmentPacket::class,
            MobEquipmentPacket::class,
            MobEquipmentPacket::class,
        ], array_map(static fn($entry): string => $entry->packet::class, $spawn));
        $armor = $spawn[1]->packet;
        $mainHandPacket = $spawn[2]->packet;
        $offHandPacket = $spawn[3]->packet;
        self::assertInstanceOf(MobArmorEquipmentPacket::class, $armor);
        self::assertInstanceOf(MobEquipmentPacket::class, $mainHandPacket);
        self::assertInstanceOf(MobEquipmentPacket::class, $offHandPacket);
        self::assertGreaterThan(0, $armor->helmet->runtimeId);
        self::assertGreaterThan(0, $mainHandPacket->item->runtimeId);
        self::assertGreaterThan(0, $offHandPacket->item->runtimeId);

        $zombie->equipmentState()->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:diamond_sword', 1));
        $mainHand = $encoder->encode(new EntityActorEquipmentChanged(
            $zombie,
            42,
            [EquipmentSlot::MAIN_HAND],
            ['observer'],
        ), []);
        self::assertCount(1, $mainHand);
        self::assertInstanceOf(MobEquipmentPacket::class, $mainHand[0]->packet);
        self::assertSame(InventoryContainerId::INVENTORY, $mainHand[0]->packet->windowId);

        $armorAndOffhand = $encoder->encode(new EntityActorEquipmentChanged(
            $zombie,
            43,
            [EquipmentSlot::HEAD, EquipmentSlot::OFF_HAND],
            ['observer'],
        ), []);
        self::assertSame([
            MobArmorEquipmentPacket::class,
            MobEquipmentPacket::class,
        ], array_map(static fn($entry): string => $entry->packet::class, $armorAndOffhand));
    }

    /** @param list<ActorMetadata> $metadata */
    private static function metadataFloat(array $metadata, int $id): float
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_float($entry->value)) {
                return $entry->value;
            }
        }

        self::fail("Missing float actor metadata {$id}.");
    }

    /** @param list<ActorMetadata> $metadata */
    private static function metadataInteger(array $metadata, int $id): int
    {
        foreach ($metadata as $entry) {
            if ($entry->id === $id && is_int($entry->value)) {
                return $entry->value;
            }
        }

        self::fail("Missing integer actor metadata {$id}.");
    }
}
