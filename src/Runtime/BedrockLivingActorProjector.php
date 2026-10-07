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
use Bedriox\Api\Entity\Capability\Ageable;
use Bedriox\Api\Entity\Capability\Angerable;
use Bedriox\Api\Entity\Capability\ChestedAnimal;
use Bedriox\Api\Entity\Capability\Climbing;
use Bedriox\Api\Entity\Capability\FireImmune;
use Bedriox\Api\Entity\Capability\Rideable;
use Bedriox\Api\Entity\Capability\Shearable;
use Bedriox\Api\Entity\Capability\Sittable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Api\Entity\Capability\Trusting;
use Bedriox\Api\Entity\Value\ArmadilloState;
use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Armadillo;
use Bedriox\Api\Entity\Vanilla\Cat;
use Bedriox\Api\Entity\Vanilla\Creeper;
use Bedriox\Api\Entity\Vanilla\Fox;
use Bedriox\Api\Entity\Vanilla\Goat;
use Bedriox\Api\Entity\Vanilla\Llama as ApiLlama;
use Bedriox\Api\Entity\Vanilla\Mooshroom;
use Bedriox\Api\Entity\Vanilla\Panda;
use Bedriox\Api\Entity\Vanilla\Pig;
use Bedriox\Api\Entity\Vanilla\PolarBear;
use Bedriox\Api\Entity\Vanilla\Rabbit;
use Bedriox\Api\Entity\Vanilla\Sheep;
use Bedriox\Api\Entity\Vanilla\Shulker;
use Bedriox\Api\Entity\Vanilla\Slime;
use Bedriox\Api\Entity\Vanilla\Sniffer;
use Bedriox\Api\Entity\Vanilla\Wolf;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockFace;
use Bedriox\Protocol\Packet\ActorAttribute;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorIntProperty;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\ActorProperties;
use Bedriox\Protocol\Packet\ActorSpawnAttribute;
use Bedriox\Protocol\Packet\BlockPosition as ProtocolBlockPosition;
use Bedriox\Protocol\Packet\BoatActorMetadata;
use Bedriox\Protocol\Packet\EndCrystalActorMetadata;
use Bedriox\Protocol\Packet\HorseActorMetadata;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\ShulkerActorMetadata;
use Bedriox\Protocol\Packet\ShulkerAttachmentFace;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\Equipment\BodyEquipmentHolder;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\Mount\UndeadHorseEntity;
use Bedriox\Server\Entity\Vanilla\CamelEntity;
use Bedriox\Server\Entity\Vanilla\End\EndCrystalEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\Misc\LeashKnotEntity;
use Bedriox\Server\Entity\Vanilla\Nether\StriderEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonHorseEntity;
use Bedriox\Server\Entity\Vanilla\TraderLlamaEntity;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Player\InventoryStack;
use LogicException;

/** Stateless projection of authoritative living-entity state into current Bedrock actor values. */
final class BedrockLivingActorProjector
{
    private const float FLOAT32_MAX = 3.4028234663852886e38;

    /** @return list<ActorSpawnAttribute> */
    public function spawnAttributes(AbstractLivingEntity $entity): array
    {
        if ($entity instanceof LeashKnotEntity) {
            return [];
        }
        $maximumHealth = $entity->getMaximumHealth();

        return [
            new ActorSpawnAttribute(ActorAttribute::HEALTH, 0.0, $maximumHealth, $entity->getHealth()),
            new ActorSpawnAttribute('minecraft:follow_range', 0.0, 2_048.0, 16.0),
            new ActorSpawnAttribute('minecraft:knockback_resistance', 0.0, 1.0, 0.0),
            new ActorSpawnAttribute('minecraft:movement', 0.0, self::FLOAT32_MAX, 0.1),
            new ActorSpawnAttribute('minecraft:attack_damage', 0.0, self::FLOAT32_MAX, 1.0),
            new ActorSpawnAttribute('minecraft:absorption', 0.0, self::FLOAT32_MAX, $entity->getAbsorption()),
            ...(self::hasPowerJump($entity) ? [
                new ActorSpawnAttribute('minecraft:horse.jump_strength', 0.0, 1.0, 0.7),
            ] : []),
        ];
    }

    /** @return list<ActorMetadata> */
    public function metadata(AbstractLivingEntity $entity, bool $noAi = false): array
    {
        if ($entity instanceof BoatEntity) {
            return BoatActorMetadata::baseline(
                $entity->getVariant()->value,
                $entity->getMaximumHealth() - $entity->getHealth(),
                $entity->hurtTicks(),
                $entity->hurtDirection(),
                rowTimeLeft: $entity->paddleTimeLeft(),
                rowTimeRight: $entity->paddleTimeRight(),
            );
        }

        $metadata = [
            $this->flagsMetadata($entity, $noAi),
            ActorMetadata::int(1, (int) ceil($entity->getHealth())),
            ...($entity instanceof Rabbit || $entity instanceof Slime || $entity instanceof Cat || $entity instanceof Panda
                || $entity instanceof Mooshroom || $entity instanceof ApiLlama
                || $entity instanceof Wolf || $entity instanceof Fox ? [ActorMetadata::int(
                    2,
                    match (true) {
                        $entity instanceof Rabbit, $entity instanceof Cat, $entity instanceof Mooshroom,
                        $entity instanceof ApiLlama,
                        $entity instanceof Wolf, $entity instanceof Fox => $entity->getVariant()->value,
                        $entity instanceof Panda => $entity->getExpressedGene()->value,
                        default => $entity->getSize()->value,
                    },
                )] : []),
            ActorMetadata::byte(3, match (true) {
                $entity instanceof Sheep => self::woolColorIndex($entity->getWoolColor()),
                $entity instanceof Cat, $entity instanceof Wolf => self::woolColorIndex($entity->getCollarColor()),
                $entity instanceof ApiLlama && $entity->getCarpetColor() !== null => self::woolColorIndex($entity->getCarpetColor()),
                default => 0,
            }),
            ActorMetadata::string(4, $entity->nameTag()),
            ActorMetadata::long(5, -1),
            ActorMetadata::long(6, 0),
            ActorMetadata::short(7, 400),
            ...(self::hasPowerJump($entity) ? [HorseActorMetadata::jumpDuration(0)] : []),
            ...($entity instanceof Creeper ? [
                ActorMetadata::int(20, $entity->getFuseTicks()),
                ActorMetadata::int(21, $entity->isIgnited() ? 1 : -1),
            ] : []),
            ActorMetadata::long(37, $entity instanceof BreedableAnimalEntity
                ? ($entity->getLeashHolderRuntimeId() ?? -1)
                : -1),
            ActorMetadata::float(38, $entity->scale()),
            ActorMetadata::short(42, 400),
            ...($entity instanceof ApiLlama ? [ActorMetadata::int(43, $entity instanceof TraderLlamaEntity ? 1 : 0)] : []),
            ...($entity instanceof ChestedAnimal ? [
                ActorMetadata::byte(44, 12),
                ActorMetadata::int(45, $entity instanceof ApiLlama ? 16 : 17),
                ActorMetadata::int(46, $entity instanceof ApiLlama ? 3 : 0),
            ] : []),
            ...($entity instanceof EndCrystalEntity ? [EndCrystalActorMetadata::beamTarget(
                $entity->beamTarget() === null
                    ? new ProtocolBlockPosition(0, 0, 0)
                    : new ProtocolBlockPosition(
                        (int) floor($entity->beamTarget()->x),
                        (int) floor($entity->beamTarget()->y),
                        (int) floor($entity->beamTarget()->z),
                    ),
            )] : []),
            ActorMetadata::float(53, $entity->collisionWidth()),
            ActorMetadata::float(54, $entity->collisionHeight()),
            ...($entity instanceof ApiLlama ? [
                ActorMetadata::int(75, $entity->getStrength()),
                ActorMetadata::int(76, 5),
            ] : []),
            ...($entity instanceof Goat ? [ActorMetadata::int(
                122,
                (int) $entity->hasLeftHorn() + (int) $entity->hasRightHorn(),
            )] : []),
            ...($entity instanceof Creeper ? [ActorMetadata::int(55, 30)] : []),
            ...($entity instanceof Shulker ? ShulkerActorMetadata::presentation(
                $entity->getPeekAmount(),
                match ($entity->getAttachmentFace()) {
                    BlockFace::DOWN => ShulkerAttachmentFace::Down,
                    BlockFace::UP => ShulkerAttachmentFace::Up,
                    BlockFace::NORTH => ShulkerAttachmentFace::North,
                    BlockFace::SOUTH => ShulkerAttachmentFace::South,
                    BlockFace::WEST => ShulkerAttachmentFace::West,
                    BlockFace::EAST => ShulkerAttachmentFace::East,
                },
            ) : []),
            ActorMetadata::byte(81, 0),
            $this->flags2Metadata($entity),
            ActorMetadata::float(120, $entity->freezingEffectStrength()),
            ActorMetadata::long(131, 0),
        ];
        usort($metadata, static fn(ActorMetadata $left, ActorMetadata $right): int => $left->id <=> $right->id);

        return $metadata;
    }

    public function flagsMetadata(AbstractLivingEntity $entity, bool $noAi = false): ActorMetadata
    {
        $flags = $entity instanceof LeashKnotEntity
            ? 0
            : ActorFlag::combine(ActorFlag::Breathing, ActorFlag::HasCollision);
        if ($entity instanceof Climbing) {
            $flags |= ActorFlag::CanClimb->mask();
        }
        if ($entity instanceof Creeper && $entity->isCharged()) {
            $flags |= ActorFlag::Powered->mask();
        }
        if ($entity instanceof Creeper && $entity->isIgnited()) {
            $flags |= ActorFlag::Ignited->mask();
        }
        if ($entity instanceof FireImmune) {
            $flags |= ActorFlag::FireImmune->mask();
        }
        if ($entity instanceof ChestedAnimal && $entity->hasChest()) {
            $flags |= ActorFlag::Chested->mask();
        }
        if ($entity instanceof BreedableAnimalEntity && $entity->getLeashHolderRuntimeId() !== null) {
            $flags |= ActorFlag::Leashed->mask();
        }
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
        if ($entity instanceof Ageable && $entity->isBaby()) {
            $flags |= ActorFlag::Baby->mask();
        }
        if ($entity instanceof Pig && $entity->isSaddled()) {
            $flags |= ActorFlag::Saddled->mask();
        }
        if ($entity instanceof Rideable && $entity->isSaddled()) {
            $flags |= ActorFlag::Saddled->mask();
        }
        if ($entity instanceof Tameable && $entity->isTamed()) {
            $flags |= ActorFlag::Tamed->mask();
        }
        if (self::hasGroundVehicleControls($entity)) {
            $flags |= ActorFlag::WasdControlled->mask();
            if (!$entity instanceof CamelEntity) {
                $flags |= ActorFlag::CanPowerJump->mask();
            }
        }
        if ($entity instanceof Sittable && $entity->isSitting()) {
            $flags |= ActorFlag::Sitting->mask();
        }
        if ($entity instanceof Panda && $entity->getActivity() === PandaActivity::SITTING) {
            $flags |= ActorFlag::Sitting->mask();
        }
        if ($entity instanceof Panda && $entity->getActivity() === PandaActivity::EATING) {
            $flags |= ActorFlag::Eating->mask();
        }
        if ($entity instanceof PolarBear && $entity->isStanding()) {
            $flags |= ActorFlag::Standing->mask();
        }
        if ($entity instanceof Angerable && $entity->getRemainingAngerTicks() > 0) {
            $flags |= ActorFlag::Angry->mask();
        }
        if ($entity instanceof Shearable && $entity->isSheared()) {
            $flags |= ActorFlag::Sheared->mask();
        }
        if ($entity instanceof EndCrystalEntity && $entity->showsBase()) {
            $flags |= ActorFlag::ShowBottom->mask();
        }
        if ($entity instanceof StriderEntity && !$entity->isWarm()) {
            $flags |= ActorFlag::Shaking->mask();
        }

        return ActorMetadata::long(0, $flags);
    }

    public function properties(AbstractLivingEntity $entity): ActorProperties
    {
        if (!$entity instanceof Armadillo) {
            return new ActorProperties();
        }
        $state = match ($entity->getState()) {
            ArmadilloState::UNROLLED => 0,
            ArmadilloState::ROLLED_UP => 1,
            ArmadilloState::ROLLED_UP_PEEKING => 2,
            ArmadilloState::ROLLED_UP_RELAXING => 3,
            ArmadilloState::ROLLED_UP_UNROLLING => 4,
        };

        return new ActorProperties([new ActorIntProperty(0, $state)]);
    }

    public function flags2Metadata(AbstractLivingEntity $entity): ActorMetadata
    {
        $flags = 0;
        if ($entity instanceof Trusting && $entity->isTrusting()) {
            $flags |= ActorFlag::Trusting->mask();
        }
        if ($entity instanceof Fox && $entity->isSleeping()) {
            $flags |= ActorFlag::Sleeping->mask();
        }
        if ($entity instanceof Fox && $entity->isFaceplanted()) {
            $flags |= ActorFlag::Stunned->mask();
        }
        if ($entity instanceof Fox && $entity->isPouncing()) {
            $flags |= ActorFlag::JumpGoalJump->mask();
        }
        if ($entity instanceof Panda) {
            $flags |= match ($entity->getActivity()) {
                PandaActivity::ROLLING => ActorFlag::Rolling->mask(),
                PandaActivity::SNEEZING => ActorFlag::Sneezing->mask(),
                PandaActivity::SCARED => ActorFlag::Scared->mask(),
                default => 0,
            };
        }
        if ($entity instanceof Goat && $entity->isRamming()) {
            $flags |= ActorFlag::RamAttack->mask();
        }
        if ($entity instanceof Sniffer && $entity->isDigging()) {
            $flags |= ActorFlag::Digging->mask();
        }

        return ActorMetadata::long(92, $flags);
    }

    private static function hasGroundVehicleControls(AbstractLivingEntity $entity): bool
    {
        if ($entity instanceof LlamaEntity || $entity instanceof TraderLlamaEntity) {
            return false;
        }

        return match (true) {
            $entity instanceof SkeletonHorseEntity => true,
            $entity instanceof HorseFamilyEntity, $entity instanceof UndeadHorseEntity => $entity->isSaddled(),
            default => false,
        };
    }

    private static function hasPowerJump(AbstractLivingEntity $entity): bool
    {
        return self::hasGroundVehicleControls($entity) && !$entity instanceof CamelEntity;
    }

    private static function woolColorIndex(WoolColor $color): int
    {
        return match ($color) {
            WoolColor::WHITE => 0,
            WoolColor::ORANGE => 1,
            WoolColor::MAGENTA => 2,
            WoolColor::LIGHT_BLUE => 3,
            WoolColor::YELLOW => 4,
            WoolColor::LIME => 5,
            WoolColor::PINK => 6,
            WoolColor::GRAY => 7,
            WoolColor::LIGHT_GRAY => 8,
            WoolColor::CYAN => 9,
            WoolColor::PURPLE => 10,
            WoolColor::BLUE => 11,
            WoolColor::BROWN => 12,
            WoolColor::GREEN => 13,
            WoolColor::RED => 14,
            WoolColor::BLACK => 15,
        };
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
        if ($entity instanceof LeashKnotEntity) {
            return [];
        }
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
            $packets[] = $this->armorEquipmentPacket($entity, $inventory);
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

    public function bodyEquipmentPacket(
        AbstractLivingEntity $entity,
        ?BedrockInventoryPacketProjector $inventory,
    ): ?MobArmorEquipmentPacket {
        return $entity instanceof BodyEquipmentHolder ? $this->armorEquipmentPacket($entity, $inventory) : null;
    }

    private function armorEquipmentPacket(
        AbstractLivingEntity $entity,
        ?BedrockInventoryPacketProjector $inventory,
    ): MobArmorEquipmentPacket {
        $equipment = $entity->equipmentState();

        return new MobArmorEquipmentPacket(
            UnsignedLong::fromInt($entity->getRuntimeId()),
            $this->equipmentItem($equipment->getItem(EquipmentSlot::HEAD), $inventory),
            $this->equipmentItem($equipment->getItem(EquipmentSlot::CHEST), $inventory),
            $this->equipmentItem($equipment->getItem(EquipmentSlot::LEGS), $inventory),
            $this->equipmentItem($equipment->getItem(EquipmentSlot::FEET), $inventory),
            $this->equipmentItem(
                $entity instanceof BodyEquipmentHolder ? $entity->getBodyEquipment() : null,
                $inventory,
            ),
        );
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
