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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Protocol\Packet\ActorAttribute;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\ActorSpawnAttribute;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Player\InventoryStack;
use LogicException;

/** Stateless projection of authoritative living-entity state into current Bedrock actor values. */
final class BedrockLivingActorProjector
{
    private const float FLOAT32_MAX = 3.4028234663852886e38;

    /** @return list<ActorSpawnAttribute> */
    public function spawnAttributes(AbstractLivingEntity $entity): array
    {
        $maximumHealth = $entity->getMaximumHealth();

        return [
            new ActorSpawnAttribute(ActorAttribute::HEALTH, 0.0, $maximumHealth, $entity->getHealth()),
            new ActorSpawnAttribute('minecraft:follow_range', 0.0, 2_048.0, 16.0),
            new ActorSpawnAttribute('minecraft:knockback_resistance', 0.0, 1.0, 0.0),
            new ActorSpawnAttribute('minecraft:movement', 0.0, self::FLOAT32_MAX, 0.1),
            new ActorSpawnAttribute('minecraft:attack_damage', 0.0, self::FLOAT32_MAX, 1.0),
            new ActorSpawnAttribute('minecraft:absorption', 0.0, self::FLOAT32_MAX, $entity->getAbsorption()),
        ];
    }

    /** @return list<ActorMetadata> */
    public function metadata(AbstractLivingEntity $entity, bool $noAi = false): array
    {
        $definition = $entity->definition();

        return [
            $this->flagsMetadata($entity, $noAi),
            ActorMetadata::int(1, (int) ceil($entity->getHealth())),
            ActorMetadata::byte(3, $entity->isNameTagVisible() ? 1 : 0),
            ActorMetadata::string(4, $entity->nameTag()),
            ActorMetadata::long(5, -1),
            ActorMetadata::long(6, 0),
            ActorMetadata::short(7, 400),
            ActorMetadata::long(37, -1),
            ActorMetadata::float(38, $entity->scale()),
            ActorMetadata::short(42, 400),
            ActorMetadata::float(53, $definition->width * $entity->scale()),
            ActorMetadata::float(54, $definition->height * $entity->scale()),
            ActorMetadata::byte(81, 0),
            ActorMetadata::long(92, 0),
            ActorMetadata::float(120, 0.0),
            ActorMetadata::long(131, 0),
        ];
    }

    public function flagsMetadata(AbstractLivingEntity $entity, bool $noAi = false): ActorMetadata
    {
        $flags = ActorFlag::combine(
            ActorFlag::CanClimb,
            ActorFlag::Breathing,
            ActorFlag::HasCollision,
        );
        if ($entity->isGravityEnabled()) {
            $flags |= ActorFlag::HasGravity->mask();
        }
        if ($entity->isNameTagVisible()) {
            $flags |= ActorFlag::CanShowName->mask();
        }
        if ($entity->isInvisible() || $entity->effectState()->has(EffectType::INVISIBILITY)) {
            $flags |= ActorFlag::Invisible->mask();
        }
        if ($entity->isOnFire()) {
            $flags |= ActorFlag::OnFire->mask();
        }
        if ($noAi || $entity->isImmobile()) {
            $flags |= ActorFlag::NoAi->mask();
        }

        return ActorMetadata::long(0, $flags);
    }

    public function healthAttribute(AbstractLivingEntity $entity): ActorAttribute
    {
        $maximumHealth = $entity->getMaximumHealth();

        return new ActorAttribute(
            ActorAttribute::HEALTH,
            0.0,
            $maximumHealth,
            $entity->getHealth(),
            0.0,
            $maximumHealth,
            $maximumHealth,
        );
    }

    public function absorptionAttribute(AbstractLivingEntity $entity): ActorAttribute
    {
        return new ActorAttribute(
            'minecraft:absorption',
            0.0,
            self::FLOAT32_MAX,
            $entity->getAbsorption(),
            0.0,
            self::FLOAT32_MAX,
            0.0,
        );
    }

    /**
     * Returns a complete spawn snapshot or only the packet groups affected by the supplied slots.
     *
     * @param null|list<EquipmentSlot> $changedSlots
     * @return list<Packet>
     */
    public function equipmentPackets(
        AbstractLivingEntity $entity,
        ?BedrockInventoryPacketProjector $inventory,
        ?array $changedSlots = null,
    ): array {
        $armorChanged = $changedSlots === null;
        $mainHandChanged = $changedSlots === null;
        $offHandChanged = $changedSlots === null;
        foreach ($changedSlots ?? [] as $slot) {
            $armorChanged = $armorChanged || $slot->isArmor();
            $mainHandChanged = $mainHandChanged || $slot === EquipmentSlot::MAIN_HAND;
            $offHandChanged = $offHandChanged || $slot === EquipmentSlot::OFF_HAND;
        }

        $runtimeId = UnsignedLong::fromInt($entity->getRuntimeId());
        $equipment = $entity->equipmentState();
        $packets = [];
        if ($armorChanged) {
            $packets[] = new MobArmorEquipmentPacket(
                $runtimeId,
                $this->equipmentItem($equipment->getItem(EquipmentSlot::HEAD), $inventory),
                $this->equipmentItem($equipment->getItem(EquipmentSlot::CHEST), $inventory),
                $this->equipmentItem($equipment->getItem(EquipmentSlot::LEGS), $inventory),
                $this->equipmentItem($equipment->getItem(EquipmentSlot::FEET), $inventory),
            );
        }
        if ($mainHandChanged) {
            $packets[] = new MobEquipmentPacket(
                $runtimeId,
                0,
                0,
                InventoryContainerId::INVENTORY,
                $this->equipmentItem($equipment->getItem(EquipmentSlot::MAIN_HAND), $inventory),
            );
        }
        if ($offHandChanged) {
            $packets[] = new MobEquipmentPacket(
                $runtimeId,
                0,
                0,
                InventoryContainerId::OFFHAND,
                $this->equipmentItem($equipment->getItem(EquipmentSlot::OFF_HAND), $inventory),
            );
        }

        return $packets;
    }

    private function equipmentItem(
        ?ItemStack $item,
        ?BedrockInventoryPacketProjector $inventory,
    ): ProtocolInventoryItemStack {
        if ($item === null) {
            return ProtocolInventoryItemStack::empty();
        }
        if ($inventory === null) {
            throw new LogicException('Non-empty entity equipment requires the active item-network translator.');
        }
        $projected = $inventory->toProtocol(new InventoryStack(
            $item->identifier,
            $item->count,
            1,
            damage: $item->damage,
            nbt: $item->nbt,
            auxValue: $item->auxValue,
        ));

        return new ProtocolInventoryItemStack(
            $projected->runtimeId,
            $projected->count,
            $projected->aux,
            null,
            $projected->blockRuntimeId,
            $projected->userData,
        );
    }
}
