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

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType as ApiContainerType;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\BlockFace;
use Bedriox\Api\World\Particle\BlockParticle;
use Bedriox\Api\World\Particle\BlockParticleType;
use Bedriox\Api\World\Particle\ColoredParticle;
use Bedriox\Api\World\Particle\ColoredParticleType;
use Bedriox\Api\World\Particle\DragonEggTeleportParticle;
use Bedriox\Api\World\Particle\ItemBreakParticle;
use Bedriox\Api\World\Particle\MobSpawnParticle;
use Bedriox\Api\World\Particle\ParticleColor;
use Bedriox\Api\World\Particle\ScalarParticle;
use Bedriox\Api\World\Particle\ScalarParticleType;
use Bedriox\Api\World\Particle\SimpleParticle;
use Bedriox\Api\World\Particle\StandardParticle;
use Bedriox\Api\World\Particle\StandardParticleType;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Protocol\Codec\UnsignedVarInt;
use Bedriox\Protocol\Packet\AbilityLayer;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\ActorMetadata;
use Bedriox\Protocol\Packet\ActorProperties;
use Bedriox\Protocol\Packet\AddActorPacket;
use Bedriox\Protocol\Packet\AddItemActorPacket;
use Bedriox\Protocol\Packet\AddPlayerPacket;
use Bedriox\Protocol\Packet\AnimatePacket;
use Bedriox\Protocol\Packet\AreaEffectCloudActorMetadata;
use Bedriox\Protocol\Packet\BlockActorDataPacket;
use Bedriox\Protocol\Packet\BlockEventPacket;
use Bedriox\Protocol\Packet\BlockPosition as ProtocolBlockPosition;
use Bedriox\Protocol\Packet\BrewingStandProperty;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\CommandPermissionLevel;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\ContainerSetDataPacket;
use Bedriox\Protocol\Packet\ContainerType;
use Bedriox\Protocol\Packet\CorrectPlayerMovePredictionPacket;
use Bedriox\Protocol\Packet\DeathInfoPacket;
use Bedriox\Protocol\Packet\DimensionId;
use Bedriox\Protocol\Packet\EmoteFlag;
use Bedriox\Protocol\Packet\EmotePacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Protocol\Packet\InventorySlotPacket;
use Bedriox\Protocol\Packet\ItemStackResponse;
use Bedriox\Protocol\Packet\ItemStackResponseContainer;
use Bedriox\Protocol\Packet\ItemStackResponsePacket;
use Bedriox\Protocol\Packet\ItemStackResponseSlot;
use Bedriox\Protocol\Packet\LevelEventBlockFace;
use Bedriox\Protocol\Packet\LevelEventPacket;
use Bedriox\Protocol\Packet\LevelEventParticleColor;
use Bedriox\Protocol\Packet\LevelEventParticleType;
use Bedriox\Protocol\Packet\LevelEventPosition;
use Bedriox\Protocol\Packet\LevelEventType;
use Bedriox\Protocol\Packet\LevelSoundEventName;
use Bedriox\Protocol\Packet\LevelSoundEventPacket;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEffectPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MoveActorAbsoluteFlag;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\MovePlayerMode;
use Bedriox\Protocol\Packet\MovePlayerPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PlayerAbilities;
use Bedriox\Protocol\Packet\PlayerActorMetadata;
use Bedriox\Protocol\Packet\PlayerAttribute;
use Bedriox\Protocol\Packet\PlayerListAddEntry;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\PlayerListRemovePacket;
use Bedriox\Protocol\Packet\PlayerPermission;
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\PotionProjectileActorMetadata;
use Bedriox\Protocol\Packet\PredictionType;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Protocol\Packet\SetPlayerGameTypePacket;
use Bedriox\Protocol\Packet\SpawnParticleEffectPacket;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\TakeItemActorPacket;
use Bedriox\Protocol\Packet\TippedArrowActorMetadata;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\UpdateBlockFlag;
use Bedriox\Protocol\Packet\UpdateBlockPacket;
use Bedriox\Protocol\Packet\UpdatePlayerGameTypePacket;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Gameplay\Potion\PotionColorMixer;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Simulation\Event\AreaEffectCloudRemoved;
use Bedriox\Server\Simulation\Event\AreaEffectCloudSpawned;
use Bedriox\Server\Simulation\Event\AreaEffectCloudUpdated;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\BrewingCompleted;
use Bedriox\Server\Simulation\Event\BrewingStandUpdated;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerContentsChanged;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\CraftingTableOpened;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\EntityActorAttackStarted;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorEffectChanged;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorHealthChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventorySlotChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemConsumed;
use Bedriox\Server\Simulation\Event\ItemEntityDespawned;
use Bedriox\Server\Simulation\Event\ItemEntityMoved;
use Bedriox\Server\Simulation\Event\ItemEntityPickedUp;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\ItemUseCancelled;
use Bedriox\Server\Simulation\Event\ItemUseStarted;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\ParticleSpawned;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerEffectChanged;
use Bedriox\Server\Simulation\Event\PlayerEnvironmentChanged;
use Bedriox\Server\Simulation\Event\PlayerGameModeChanged;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\PotionProjectileImpacted;
use Bedriox\Server\Simulation\Event\PotionProjectileMoved;
use Bedriox\Server\Simulation\Event\PotionProjectileRemoved;
use Bedriox\Server\Simulation\Event\PotionProjectileSpawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\WeatherChanged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockEntityCodec;

/** Stateless current-Bedrock projection of authoritative simulation events. */
final class BedrockWorldEventPacketEncoder implements ChatBroadcastPacketEncoder, EntityMovementPacketEncoder, PlayerMovementPacketEncoder, WorldEventPacketEncoder
{
    /** Leaves room for the packet header inside the protocol's 1 MiB packet-frame ceiling. */
    private const int MAXIMUM_PLAYER_LIST_BODY_BYTES = 1_000_000;

    private readonly PersistentBlockEntityCodec $blockEntities;

    private readonly BedrockLivingActorProjector $livingActors;

    public function __construct(
        private readonly ?BedrockChunkPacketSerializer $chunks = null,
        private readonly ?BedrockInventoryPacketProjector $inventory = null,
        ?PersistentBlockEntityCodec $blockEntities = null,
        ?BedrockLivingActorProjector $livingActors = null,
    ) {
        $this->blockEntities = $blockEntities ?? new PersistentBlockEntityCodec();
        $this->livingActors = $livingActors ?? new BedrockLivingActorProjector();
    }

    public function encode(WorldEvent $event, array $sessions): array
    {
        return match (true) {
            $event instanceof ParticleSpawned => $this->particle($event, $sessions),
            $event instanceof PlayerJoined => $this->joined($event, $sessions),
            $event instanceof WeatherChanged => $this->weatherChanged($event),
            $event instanceof PlayerBecameVisible => $this->visible($event, $sessions),
            $event instanceof PlayerBecameHidden => [
                new DirectedPacket($event->recipientSessionId, new RemoveActorPacket($event->runtimeActorId)),
            ],
            $event instanceof PlayerMoved => $this->peerMovement($event),
            $event instanceof MovementCorrected => [
                ...$this->movementCorrection(
                    $event,
                    [$event->authoritativePlayer->sessionId],
                ),
                ...$this->peerMovement(new PlayerMoved(
                    $event->authoritativePlayer,
                    $event->peerSessionIds,
                    $event->postureChanged,
                )),
            ],
            $event instanceof ChatBroadcast => $this->chatBroadcast($event),
            $event instanceof CraftingTableOpened => [new DirectedPacket(
                $event->ownerSessionId,
                new ContainerOpenPacket(
                    1,
                    ContainerType::Workbench,
                    new ProtocolBlockPosition($event->position->x, $event->position->y, $event->position->z),
                    -1,
                ),
            )],
            $event instanceof ContainerOpened => $this->containerOpened($event),
            $event instanceof ContainerClosed => $this->containerClosed($event),
            $event instanceof ContainerContentsChanged => $this->containerContentsChanged($event),
            $event instanceof BrewingStandUpdated => $this->brewingStandUpdated($event),
            $event instanceof BrewingCompleted => $this->brewingCompleted($event),
            $event instanceof EmotePerformed => $this->emote($event, $sessions),
            $event instanceof ArmSwung => $this->armSwung($event),
            $event instanceof PlayerDisconnected => $this->disconnected($event),
            $event instanceof BlockBreakStarted => $this->blockBreakStarted($event),
            $event instanceof BlockPunch => $this->blockPunch($event),
            $event instanceof BlockBreakStopped => $this->blockBreakStopped($event),
            $event instanceof BlockChanged => $this->blockChanged($event),
            $event instanceof BlockEntityChanged => $this->blockEntityChanged($event),
            $event instanceof BlockPlaced => $this->blockPlaced($event),
            $event instanceof BlockPlacementCorrected => $this->blockPlacementCorrected($event),
            $event instanceof HeldItemChanged => $this->heldItemChanged($event),
            $event instanceof InventoryStackRequestProcessed => $this->inventoryStackRequestProcessed($event),
            $event instanceof InventorySlotChanged => $this->inventorySlotChanged($event),
            $event instanceof ItemUseStarted => $this->itemUseStarted($event),
            $event instanceof ItemUseCancelled => $this->itemUseCancelled($event),
            $event instanceof ItemConsumed => $this->itemConsumed($event),
            $event instanceof NutritionChanged => $this->nutritionChanged($event),
            $event instanceof PlayerEffectChanged => $this->playerEffectChanged($event),
            $event instanceof PlayerEnvironmentChanged => $this->playerEnvironmentChanged($event),
            $event instanceof PotionProjectileSpawned => $this->potionProjectileSpawned($event),
            $event instanceof PotionProjectileMoved => $this->potionProjectileMoved($event),
            $event instanceof PotionProjectileImpacted => $this->potionProjectileImpacted($event),
            $event instanceof PotionProjectileRemoved => array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket(
                    $recipient,
                    new RemoveActorPacket($event->runtimeEntityId),
                ),
                $event->recipientSessionIds,
            ),
            $event instanceof AreaEffectCloudSpawned => $this->areaEffectCloudSpawned($event),
            $event instanceof AreaEffectCloudUpdated => $this->areaEffectCloudUpdated($event),
            $event instanceof AreaEffectCloudRemoved => array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket(
                    $recipient,
                    new RemoveActorPacket($event->runtimeEntityId),
                ),
                $event->recipientSessionIds,
            ),
            $event instanceof ItemEntitySpawned => $this->itemEntitySpawned($event),
            $event instanceof ItemEntityMoved => $this->itemEntityMoved($event),
            $event instanceof ItemEntityPickedUp => $this->itemEntityPickedUp($event),
            $event instanceof ItemEntityDespawned => array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket(
                    $recipient,
                    new RemoveActorPacket($event->runtimeActorId),
                ),
                $event->recipientSessionIds,
            ),
            $event instanceof EntityActorSpawned => $this->entityActorSpawned($event),
            $event instanceof EntityActorEffectChanged => $this->entityActorEffectChanged($event),
            $event instanceof EntityActorEquipmentChanged => $this->entityActorEquipmentChanged($event),
            $event instanceof EntityActorHealthChanged => $this->entityActorHealthChanged($event),
            $event instanceof EntityActorMetadataChanged => $this->entityActorMetadataChanged($event),
            $event instanceof EntityActorMoved => $this->entityActorMoved($event),
            $event instanceof EntityActorAttackStarted => $this->entityActorAttackStarted($event),
            $event instanceof EntityActorDamaged => $this->entityActorDamaged($event),
            $event instanceof EntityActorDied => $this->entityActorDied($event),
            $event instanceof EntityActorRemoved => $this->entityActorRemoved($event),
            $event instanceof PlayerGameModeChanged => $this->gameModeChanged($event),
            $event instanceof PlayerDamaged => $this->damaged($event),
            $event instanceof PlayerHealed => [new DirectedPacket(
                $event->player->sessionId,
                $this->healthPacket($event->player),
            )],
            $event instanceof PlayerKnockedBack => $this->playerKnockedBack($event),
            $event instanceof PlayerMotionChanged => $this->motionChanged($event),
            $event instanceof PlayerDied => $this->died($event),
            $event instanceof PlayerRespawned => $this->respawned($event),
            $event instanceof RespawnAcknowledged => [new DirectedPacket(
                $event->player->sessionId,
                $this->respawnPacket($event->player, RespawnState::ServerReady),
            )],
            default => [],
        };
    }

    /** @return list<DirectedPacket> */
    private function chatBroadcast(ChatBroadcast $event): array
    {
        $packet = $this->chatPacket($event);

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    public function chatPacket(ChatBroadcast $event): Packet
    {
        return new ChatPacket($event->senderDisplayName, $event->message);
    }

    /** @return list<DirectedPacket> */
    private function entityActorAttackStarted(EntityActorAttackStarted $event): array
    {
        $packet = new ActorEventPacket(
            UnsignedLong::fromInt($event->entity->getRuntimeId()),
            ActorEventType::AttackStart,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function playerKnockedBack(PlayerKnockedBack $event): array
    {
        $packet = new SetActorMotionPacket(
            UnsignedLong::fromInt($event->player->runtimeActorId),
            $event->motionX,
            $event->motionY,
            $event->motionZ,
            new UnsignedLong($event->clientTick->high, $event->clientTick->low),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function containerOpened(ContainerOpened $event): array
    {
        $position = $event->position === null
            ? new ProtocolBlockPosition(0, 0, 0)
            : self::protocolBlockPosition($event->position);
        $packets = [
            new DirectedPacket($event->ownerSessionId, ContainerOpenPacket::blockInventory(
                $event->windowId,
                self::protocolContainerType($event->containerType, $event->layout),
                $position,
            )),
            new DirectedPacket($event->ownerSessionId, new InventoryContentPacket(
                $event->windowId,
                array_map($this->requireInventoryProjector()->toProtocol(...), $event->slots),
            )),
        ];

        // A custom virtual title requires a future bounded presentation adapter which supplies a temporary
        // block actor. The wire layer deliberately does not spoof world state merely to carry that title.
        array_push($packets, ...$this->containerBlockStatePackets(
            $event->containerType,
            $event->position,
            $event->pairedPosition,
            $event->blockEventRecipientSessionIds,
            true,
        ));

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function containerClosed(ContainerClosed $event): array
    {
        $packets = [];
        if ($event->serverInitiated) {
            $packets[] = new DirectedPacket($event->ownerSessionId, new ContainerClosePacket(
                $event->windowId,
                self::protocolContainerType($event->containerType, $event->layout),
                true,
            ));
        }
        array_push($packets, ...$this->containerBlockStatePackets(
            $event->containerType,
            $event->position,
            $event->pairedPosition,
            $event->blockEventRecipientSessionIds,
            false,
        ));

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function containerContentsChanged(ContainerContentsChanged $event): array
    {
        $viewers = [new \Bedriox\Server\Simulation\Event\ContainerViewerProjection(
            $event->ownerSessionId,
            $event->windowId,
            $event->slots,
        ), ...$event->additionalViewers];
        $packets = [];
        foreach ($viewers as $viewer) {
            if ($event->changedSlots === []) {
                $packets[] = new DirectedPacket($viewer->sessionId, new InventoryContentPacket(
                    $viewer->windowId,
                    array_map($this->requireInventoryProjector()->toProtocol(...), $viewer->slots),
                ));
                continue;
            }
            foreach ($event->changedSlots as $slot) {
                array_push($packets, ...$this->inventorySlotCorrection(
                    $viewer->sessionId,
                    $viewer->windowId,
                    $slot,
                    $viewer->slots[$slot],
                ));
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function brewingStandUpdated(BrewingStandUpdated $event): array
    {
        $packets = [];
        foreach ($event->viewers as $viewer) {
            if ($event->changedSlots !== []) {
                $packets[] = new DirectedPacket($viewer->sessionId, new InventoryContentPacket(
                    $viewer->windowId,
                    array_map($this->requireInventoryProjector()->toProtocol(...), $viewer->slots),
                ));
            }
            foreach ([
                [BrewingStandProperty::BrewTime, $event->brewTime],
                [BrewingStandProperty::FuelAmount, $event->fuelAmount],
                [BrewingStandProperty::FuelTotal, $event->fuelTotal],
            ] as [$property, $value]) {
                $packets[] = new DirectedPacket(
                    $viewer->sessionId,
                    ContainerSetDataPacket::brewingStand($viewer->windowId, $property, $value),
                );
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function brewingCompleted(BrewingCompleted $event): array
    {
        $position = new LevelEventPosition(
            $event->position->x + 0.5,
            $event->position->y + 0.5,
            $event->position->z + 0.5,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket(
                $recipient,
                new LevelSoundEventPacket(LevelSoundEventName::potionBrewed(), $position),
            ),
            $event->recipientSessionIds,
        );
    }

    /**
     * @param list<string> $recipients
     * @return list<DirectedPacket>
     */
    private function containerBlockStatePackets(
        ApiContainerType $type,
        ?\Bedriox\Server\World\BlockPosition $position,
        ?\Bedriox\Server\World\BlockPosition $pairedPosition,
        array $recipients,
        bool $open,
    ): array {
        if ($position === null || in_array($type, [ApiContainerType::VIRTUAL, ApiContainerType::BARREL], true)) {
            return [];
        }
        $positions = [$position];
        if ($pairedPosition !== null) {
            $positions[] = $pairedPosition;
        }
        $packets = [];
        foreach (array_values(array_unique($recipients)) as $recipient) {
            foreach ($positions as $blockPosition) {
                $packets[] = new DirectedPacket(
                    $recipient,
                    BlockEventPacket::containerState(self::protocolBlockPosition($blockPosition), $open),
                );
            }
        }

        return $packets;
    }

    private static function protocolContainerType(
        ApiContainerType $type,
        ?ContainerLayout $layout,
    ): ContainerType {
        if ($type !== ApiContainerType::VIRTUAL) {
            return $type === ApiContainerType::BREWING_STAND
                ? ContainerType::BrewingStand
                : ContainerType::Container;
        }

        return match ($layout) {
            ContainerLayout::HOPPER => ContainerType::Hopper,
            ContainerLayout::DISPENSER => ContainerType::Dispenser,
            ContainerLayout::DROPPER => ContainerType::Dropper,
            ContainerLayout::SINGLE_CHEST, ContainerLayout::DOUBLE_CHEST => ContainerType::Container,
            null => throw new \LogicException('Virtual container projection requires a layout.'),
        };
    }

    private static function protocolBlockPosition(
        \Bedriox\Server\World\BlockPosition $position,
    ): ProtocolBlockPosition {
        return new ProtocolBlockPosition($position->x, $position->y, $position->z);
    }

    /** @return list<DirectedPacket> */
    private function damaged(PlayerDamaged $event): array
    {
        $packets = [new DirectedPacket(
            $event->player->sessionId,
            $this->healthPacket($event->player),
        )];
        $animation = new ActorEventPacket(
            UnsignedLong::fromInt($event->player->runtimeActorId),
            ActorEventType::Hurt,
        );
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $animation);
        }
        if ($event->equipmentChanged && $this->inventory !== null) {
            $packets = [
                ...$packets,
                ...$this->inventoryContentCorrection(
                    $event->player->sessionId,
                    InventoryContainerId::ARMOR,
                    self::normalizeArmor($event->player->armor),
                ),
            ];
            $armor = $this->armorEquipment($event->player);
            foreach ($event->recipientSessionIds as $recipient) {
                if ($recipient !== $event->player->sessionId) {
                    $packets[] = new DirectedPacket($recipient, $armor);
                }
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function itemUseStarted(ItemUseStarted $event): array
    {
        $packets = [];
        $metadata = SetActorDataPacket::playerPosture(
            UnsignedLong::fromInt($event->runtimeActorId),
            UnsignedLong::fromInt(max(0, $event->movementSequence)),
            $event->sneaking,
            $event->sprinting,
            true,
        );
        $animation = $this->eatingAnimation($event->runtimeActorId, $event->stack);
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $metadata);
            if ($animation !== null) {
                $packets[] = new DirectedPacket($recipient, $animation);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function itemUseCancelled(ItemUseCancelled $event): array
    {
        $metadata = SetActorDataPacket::playerPosture(
            UnsignedLong::fromInt($event->runtimeActorId),
            UnsignedLong::fromInt(max(0, $event->movementSequence)),
            $event->sneaking,
            $event->sprinting,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $metadata),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function itemConsumed(ItemConsumed $event): array
    {
        $player = $event->player;
        $packets = [];
        $metadata = SetActorDataPacket::playerPosture(
            UnsignedLong::fromInt($player->runtimeActorId),
            UnsignedLong::fromInt(max(0, $player->movementSequence)),
            $player->sneaking,
            $player->sprinting,
        );
        $animation = $this->eatingAnimation($player->runtimeActorId, $event->consumedStack);
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $metadata);
            if ($animation !== null) {
                $packets[] = new DirectedPacket($recipient, $animation);
            }
        }
        if ($this->inventory !== null) {
            foreach ($event->affectedSlots as $reference) {
                $packets = [
                    ...$packets,
                    ...$this->inventorySlotCorrection(
                        $player->sessionId,
                        InventoryContainerId::INVENTORY,
                        $reference->slot,
                        $event->mainInventory[$reference->slot] ?? null,
                    ),
                ];
            }
            $equipment = new MobEquipmentPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                $event->hotbarSlot,
                $event->hotbarSlot,
                InventoryContainerId::INVENTORY,
                $this->visualStack($event->selectedStack),
            );
            foreach ($event->recipientSessionIds as $recipient) {
                $packets[] = new DirectedPacket($recipient, $equipment);
            }
        }

        return $packets;
    }

    private function eatingAnimation(int $runtimeActorId, \Bedriox\Server\Player\InventoryStack $stack): ?ActorEventPacket
    {
        if ($this->inventory === null) {
            return null;
        }
        $network = $this->inventory->toProtocol($stack);
        $data = (($network->runtimeId & 0xffff) << 16) | ($network->aux & 0xffff);
        if ($data > 0x7fffffff) {
            $data -= 0x100000000;
        }

        return new ActorEventPacket(
            UnsignedLong::fromInt($runtimeActorId),
            ActorEventType::EatingItem,
            $data,
        );
    }

    /** @return list<DirectedPacket> */
    private function motionChanged(PlayerMotionChanged $event): array
    {
        $motion = new SetActorMotionPacket(
            UnsignedLong::fromInt($event->player->runtimeActorId),
            $event->motionX,
            $event->motionY,
            $event->motionZ,
            new UnsignedLong($event->clientTick->high, $event->clientTick->low),
        );
        $posture = $event->postureChanged
            ? SetActorDataPacket::playerPosture(
                UnsignedLong::fromInt($event->player->runtimeActorId),
                new UnsignedLong($event->clientTick->high, $event->clientTick->low),
                $event->player->sneaking,
                $event->player->sprinting,
            )
            : null;
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $motion);
            if ($posture !== null) {
                $packets[] = new DirectedPacket($recipient, $posture);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function died(PlayerDied $event): array
    {
        $animation = new ActorEventPacket(
            UnsignedLong::fromInt($event->player->runtimeActorId),
            ActorEventType::Death,
        );
        $packets = [];
        foreach ($event->animationRecipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $animation);
        }
        $packets[] = new DirectedPacket(
            $event->player->sessionId,
            $this->respawnPacket($event->player, RespawnState::ServerSearching),
        );
        $screen = $event->deathScreenMessage;
        $packets[] = new DirectedPacket(
            $event->player->sessionId,
            new DeathInfoPacket(
                $screen instanceof TranslatableMessage ? $screen->key : ($screen ?? ''),
                $screen instanceof TranslatableMessage ? $screen->parameters : [],
            ),
        );
        if ($event->deathMessage !== null) {
            $message = $event->deathMessage instanceof TranslatableMessage
                ? new TranslatedTextPacket($event->deathMessage->key, $event->deathMessage->parameters)
                : new SystemTextPacket($event->deathMessage);
            foreach ($event->messageRecipientSessionIds as $recipient) {
                $packets[] = new DirectedPacket($recipient, $message);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function respawned(PlayerRespawned $event): array
    {
        $player = $event->player;
        $packets = [
            new DirectedPacket($player->sessionId, $this->healthPacket($player)),
            new DirectedPacket($player->sessionId, $this->nutritionPacket($player)),
            new DirectedPacket($player->sessionId, new MovePlayerPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                $player->position->x,
                PlayerPositionProjection::feetToWireY($player->position->y),
                $player->position->z,
                $player->pitch,
                $player->yaw,
                $player->headYaw,
                MovePlayerMode::RESPAWN,
                $player->verticalState === VerticalState::GROUNDED,
                UnsignedLong::fromInt(0),
                UnsignedLong::fromInt(max(0, $player->movementSequence)),
            )),
        ];
        $animation = new ActorEventPacket(UnsignedLong::fromInt($player->runtimeActorId), ActorEventType::Respawn);
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $animation);
        }
        foreach ($this->peerMovement(new PlayerMoved($player, array_values(array_filter(
            $event->recipientSessionIds,
            static fn(string $recipient): bool => $recipient !== $player->sessionId,
        )))) as $packet) {
            $packets[] = $packet;
        }
        if ($this->inventory !== null) {
            $armor = $event->armor !== [] ? $event->armor : $player->armor;
            $offhand = $event->offhand ?? $player->offhand;
            $packets = [
                ...$packets,
                ...$this->inventoryContentCorrection(
                    $player->sessionId,
                    InventoryContainerId::INVENTORY,
                    $event->inventory,
                ),
                ...$this->inventoryContentCorrection(
                    $player->sessionId,
                    InventoryContainerId::ARMOR,
                    self::normalizeArmor($armor),
                ),
                ...$this->inventoryContentCorrection(
                    $player->sessionId,
                    InventoryContainerId::OFFHAND,
                    [$offhand],
                ),
            ];
            $packets[] = new DirectedPacket($player->sessionId, new MobEquipmentPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                $event->selectedHotbarSlot,
                $event->selectedHotbarSlot,
                InventoryContainerId::INVENTORY,
                $this->inventory->toProtocol($event->selectedStack),
            ));
        }

        return $packets;
    }

    private function healthPacket(PlayerSnapshot $player): UpdateAttributesPacket
    {
        return new UpdateAttributesPacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            [new PlayerAttribute(
                'minecraft:health',
                0.0,
                $player->maximumHealth,
                $player->health,
                0.0,
                $player->maximumHealth,
                $player->maximumHealth,
            ), new PlayerAttribute(
                'minecraft:absorption',
                0.0,
                1_024.0,
                $player->absorption,
                0.0,
                1_024.0,
                0.0,
            )],
            UnsignedLong::fromInt(max(0, $player->movementSequence)),
        );
    }

    private function nutritionPacket(PlayerSnapshot $player): UpdateAttributesPacket
    {
        return new UpdateAttributesPacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            [
                new PlayerAttribute('minecraft:player.hunger', 0.0, 20.0, $player->food, 0.0, 20.0, 20.0),
                new PlayerAttribute('minecraft:player.saturation', 0.0, 20.0, $player->saturation, 0.0, 20.0, 20.0),
                new PlayerAttribute('minecraft:player.exhaustion', 0.0, 4.0, $player->exhaustion, 0.0, 4.0, 0.0),
            ],
            UnsignedLong::fromInt(max(0, $player->movementSequence)),
        );
    }

    /** @return list<DirectedPacket> */
    private function nutritionChanged(NutritionChanged $event): array
    {
        // Exhaustion is an internal accumulator. Projecting every fractional sprint update creates
        // ordered network work without changing the visible hunger bar. The latest exhaustion value
        // is included whenever food or saturation actually changes.
        if ($event->player->food === $event->previousFood
            && $event->player->saturation === $event->previousSaturation) {
            return [];
        }

        return [new DirectedPacket(
            $event->player->sessionId,
            $this->nutritionPacket($event->player),
        )];
    }

    private function respawnPacket(PlayerSnapshot $player, RespawnState $state): RespawnPacket
    {
        return new RespawnPacket(
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            $state,
            UnsignedLong::fromInt($player->runtimeActorId),
        );
    }

    /**
     * @param array<string, RuntimeSession> $sessions
     * @return list<DirectedPacket>
     */
    private function emote(EmotePerformed $event, array $sessions): array
    {
        $sender = $sessions[$event->senderSessionId] ?? null;
        if (!$sender instanceof RuntimeSession) {
            return [];
        }
        $packet = new EmotePacket(
            $sender->runtimeEntityId,
            $event->emoteId,
            0,
            '',
            '',
            [EmoteFlag::ServerSide, EmoteFlag::MuteEmoteChat],
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            array_values(array_filter(
                $event->recipientSessionIds,
                static fn(string $recipient): bool => $recipient !== $event->senderSessionId,
            )),
        );
    }

    /** @return list<DirectedPacket> */
    private function armSwung(ArmSwung $event): array
    {
        $packet = new AnimatePacket(
            AnimatePacket::SWING,
            UnsignedLong::fromInt($event->runtimeActorId),
            0.0,
            null,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            array_values(array_filter(
                $event->recipientSessionIds,
                static fn(string $recipient): bool => $recipient !== $event->ownerSessionId,
            )),
        );
    }

    /**
     * @param array<string, RuntimeSession> $sessions
     * @return list<DirectedPacket>
     */
    private function joined(PlayerJoined $event, array $sessions): array
    {
        $joined = $sessions[$event->player->sessionId] ?? null;
        if (!$joined instanceof RuntimeSession || $joined->play === null) {
            return [];
        }
        $packets = [];
        $joinedEntry = new PlayerListAddPacket([$this->listEntry($event->player, $joined)]);
        foreach ($event->recipientSessionIds as $recipient) {
            // The joining client already received its own list entry in the bootstrap.
            if ($recipient === $event->player->sessionId) {
                continue;
            }
            $packets[] = new DirectedPacket($recipient, $joinedEntry);
        }
        $existingEntries = [];
        foreach ($event->existingPeers as $peer) {
            $session = $sessions[$peer->sessionId] ?? null;
            if (!$session instanceof RuntimeSession || $session->play === null) {
                continue;
            }
            $existingEntries[] = $this->listEntry($peer, $session);
        }
        foreach ($this->boundedPlayerListPackets($existingEntries) as $packet) {
            $packets[] = new DirectedPacket($event->player->sessionId, $packet);
        }
        $environmentMetadata = array_values(array_filter(
            $this->playerMetadata($event->player),
            static fn(ActorMetadata $entry): bool => PlayerActorMetadata::isFlags($entry)
                || PlayerActorMetadata::isAirSupply($entry)
                || PlayerActorMetadata::isMaximumAirSupply($entry),
        ));
        $packets[] = new DirectedPacket($event->player->sessionId, new SetActorDataPacket(
            UnsignedLong::fromInt($event->player->runtimeActorId),
            UnsignedLong::fromInt(max(0, $event->player->movementSequence)),
            $environmentMetadata,
        ));
        foreach ($event->player->effects as $effect) {
            $packets[] = new DirectedPacket(
                $event->player->sessionId,
                $this->effectPacket(
                    $event->player->runtimeActorId,
                    $effect,
                    0,
                    false,
                ),
            );
        }
        if ($event->weather !== null) {
            foreach ($this->weatherPackets($event->weather) as $packet) {
                $packets[] = new DirectedPacket($event->player->sessionId, $packet);
            }
        }
        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function weatherChanged(WeatherChanged $event): array
    {
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            foreach ($this->weatherPackets($event->weather) as $packet) {
                $packets[] = new DirectedPacket($recipient, $packet);
            }
        }

        return $packets;
    }

    /** @return list<LevelEventPacket> */
    private function weatherPackets(WeatherState $weather): array
    {
        return match ($weather->type) {
            WeatherType::CLEAR => [
                LevelEventPacket::weather(LevelEventType::StopRain, 0),
                LevelEventPacket::weather(LevelEventType::StopThunder, 0),
            ],
            WeatherType::RAIN => [
                LevelEventPacket::weather(LevelEventType::StartRain),
                LevelEventPacket::weather(LevelEventType::StopThunder, 0),
            ],
            WeatherType::THUNDER => [
                LevelEventPacket::weather(LevelEventType::StartRain),
                LevelEventPacket::weather(LevelEventType::StartThunder),
            ],
        };
    }

    /**
     * Splits skin-heavy player-list snapshots before they reach the protocol packet-frame ceiling.
     *
     * @param list<PlayerListAddEntry> $entries
     * @return list<PlayerListAddPacket>
     */
    private function boundedPlayerListPackets(array $entries): array
    {
        $packets = [];
        $batch = [];
        $entryBytes = 0;
        foreach ($entries as $entry) {
            $encodedEntryBytes = strlen((new PlayerListAddPacket([$entry]))->encode()) - 1;
            $candidateCount = count($batch) + 1;
            $candidateBytes = strlen(UnsignedVarInt::encode($candidateCount)) + $entryBytes + $encodedEntryBytes;
            if ($batch !== [] && $candidateBytes > self::MAXIMUM_PLAYER_LIST_BODY_BYTES) {
                $packets[] = new PlayerListAddPacket($batch);
                $batch = [];
                $entryBytes = 0;
                $candidateCount = 1;
                $candidateBytes = 1 + $encodedEntryBytes;
            }
            if ($candidateBytes > self::MAXIMUM_PLAYER_LIST_BODY_BYTES) {
                throw new \UnexpectedValueException('A player-list entry exceeds the supported packet size.');
            }
            $batch[] = $entry;
            $entryBytes += $encodedEntryBytes;
        }
        if ($batch !== []) {
            $packets[] = new PlayerListAddPacket($batch);
        }

        return $packets;
    }

    /** @return non-empty-list<Packet> */
    public function playerMovementPackets(PlayerMoved $event): array
    {
        $player = $event->player;
        $packets = [new MoveActorAbsolutePacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            $player->pitch,
            $player->yaw,
            $player->headYaw,
            $player->verticalState === VerticalState::GROUNDED ? [MoveActorAbsoluteFlag::OnGround] : [],
        )];
        if ($event->postureChanged) {
            $flags = array_values(array_filter(
                $this->playerMetadata($player),
                static fn(ActorMetadata $entry): bool => PlayerActorMetadata::isFlags($entry),
            ));
            $packets[] = new SetActorDataPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                UnsignedLong::fromInt(max(0, $player->movementSequence)),
                $flags,
            );
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function peerMovement(PlayerMoved $event): array
    {
        $sharedPackets = $this->playerMovementPackets($event);

        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            foreach ($sharedPackets as $packet) {
                $packets[] = new DirectedPacket($recipient, $packet);
            }
        }

        return $packets;
    }

    /**
     * @param list<string> $recipients
     * @return list<DirectedPacket>
     */
    private function movementCorrection(MovementCorrected $event, array $recipients): array
    {
        $player = $event->authoritativePlayer;
        if ($event->clientTick !== null) {
            $packet = new CorrectPlayerMovePredictionPacket(
                PredictionType::Player,
                $player->position->x,
                PlayerPositionProjection::feetToWireY($player->position->y),
                $player->position->z,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0,
                0.0,
                $player->verticalState === VerticalState::GROUNDED,
                new UnsignedLong($event->clientTick->high, $event->clientTick->low),
            );

            return array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
                $recipients,
            );
        }

        if ($event->reason !== 'plugin_teleport') {
            return [];
        }

        $packet = new MovePlayerPacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            $player->pitch,
            $player->yaw,
            $player->headYaw,
            MovePlayerMode::TELEPORT,
            $player->verticalState === VerticalState::GROUNDED,
            UnsignedLong::fromInt(0),
            UnsignedLong::fromInt(max(0, $player->movementSequence)),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $recipients,
        );
    }

    /** @return list<DirectedPacket> */
    private function disconnected(PlayerDisconnected $event): array
    {
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, new PlayerListRemovePacket([$event->identity]));
        }

        return $packets;
    }

    private function listEntry(PlayerSnapshot $player, RuntimeSession $session): PlayerListAddEntry
    {
        $login = $session->play?->login();
        if ($login === null) {
            throw new \LogicException('A visible player must own authenticated play state.');
        }

        return new PlayerListAddEntry(
            $player->identity,
            $player->runtimeActorId,
            $player->displayName,
            $login->xuid,
            '',
            BuildPlatform::Unknown,
            PlayerSkin::fromVerifiedClientData($login->clientData),
            colorArgb: 0xffffffff,
            trustedSkin: false,
        );
    }

    private function addPlayer(PlayerSnapshot $player): AddPlayerPacket
    {
        $abilities = new PlayerAbilities(
            $player->runtimeActorId,
            PlayerPermission::Member,
            CommandPermissionLevel::Normal,
            [new AbilityLayer(1, 0x000fffff, 0x0000003f, 0.05, 1.0, 0.1)],
        );

        return new AddPlayerPacket(
            $player->identity,
            $player->displayName,
            UnsignedLong::fromInt($player->runtimeActorId),
            '',
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            0.0,
            0.0,
            0.0,
            $player->pitch,
            $player->yaw,
            $player->headYaw,
            0,
            $abilities,
            buildPlatform: BuildPlatform::Unknown,
            metadata: $this->playerMetadata($player),
        );
    }

    /** @return list<ActorMetadata> */
    private function playerMetadata(PlayerSnapshot $player): array
    {
        $metadata = PlayerActorMetadata::baseline(
            $player->displayName,
            $player->sneaking,
            $player->sprinting,
        );
        $flags = PlayerActorMetadata::flags($player->sneaking, $player->sprinting);
        if ($player->fireTicks > 0) {
            $flags |= ActorFlag::OnFire->mask();
        }
        if (isset($player->effects[\Bedriox\Api\Effect\EffectType::INVISIBILITY->value])) {
            $flags |= ActorFlag::Invisible->mask();
        }
        foreach ($metadata as $index => $entry) {
            $metadata[$index] = match (true) {
                PlayerActorMetadata::isFlags($entry) => PlayerActorMetadata::flagsEntry($flags),
                PlayerActorMetadata::isAirSupply($entry) => PlayerActorMetadata::airSupply($player->airTicks),
                PlayerActorMetadata::isMaximumAirSupply($entry) => PlayerActorMetadata::maximumAirSupply(
                    \Bedriox\Server\Player\PlayerVitals::MAX_AIR_TICKS,
                ),
                default => $entry,
            };
        }

        return $metadata;
    }

    /** @return list<DirectedPacket> */
    private function playerEnvironmentChanged(PlayerEnvironmentChanged $event): array
    {
        $metadata = $this->playerMetadata($event->player);
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $owner = $recipient === $event->player->sessionId;
            $entries = array_values(array_filter(
                $metadata,
                static fn(ActorMetadata $entry): bool => ($event->fireStateChanged
                        && PlayerActorMetadata::isFlags($entry))
                    || ($owner && $event->airSupplyChanged && (
                        PlayerActorMetadata::isAirSupply($entry)
                        || PlayerActorMetadata::isMaximumAirSupply($entry)
                    )),
            ));
            if ($entries !== []) {
                $packets[] = new DirectedPacket($recipient, new SetActorDataPacket(
                    UnsignedLong::fromInt($event->player->runtimeActorId),
                    UnsignedLong::fromInt(max(0, $event->tick)),
                    $entries,
                ));
            }
        }

        return $packets;
    }

    /**
     * @param array<string, RuntimeSession> $sessions
     * @return list<DirectedPacket>
     */
    private function visible(PlayerBecameVisible $event, array $sessions): array
    {
        $session = $sessions[$event->player->sessionId] ?? null;
        $login = $session?->play?->login();
        if ($login === null) {
            return [];
        }

        $packets = [
            new DirectedPacket($event->recipientSessionId, $this->addPlayer($event->player)),
            new DirectedPacket($event->recipientSessionId, new PlayerSkinPacket(
                $event->player->identity,
                PlayerSkin::fromVerifiedClientData($login->clientData),
                $login->clientData->skinId,
                '',
            )),
            new DirectedPacket($event->recipientSessionId, new MobEquipmentPacket(
                UnsignedLong::fromInt($event->player->runtimeActorId),
                $event->player->selectedHotbarSlot,
                $event->player->selectedHotbarSlot,
                InventoryContainerId::INVENTORY,
                $this->visualStack($event->player->selectedStack),
            )),
            new DirectedPacket($event->recipientSessionId, $this->armorEquipment($event->player)),
            new DirectedPacket($event->recipientSessionId, new MobEquipmentPacket(
                UnsignedLong::fromInt($event->player->runtimeActorId),
                0,
                0,
                InventoryContainerId::OFFHAND,
                $this->visualStack($event->player->offhand),
            )),
        ];
        foreach ($event->player->effects as $effect) {
            $packets[] = new DirectedPacket(
                $event->recipientSessionId,
                $this->effectPacket($event->player->runtimeActorId, $effect, 0, false),
            );
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function playerEffectChanged(PlayerEffectChanged $event): array
    {
        $packet = $event->effect === null
            ? MobEffectPacket::remove(
                UnsignedLong::fromInt($event->player->runtimeActorId),
                BedrockEffectTranslator::type($event->type),
                UnsignedLong::fromInt(max(0, $event->tick)),
            )
            : $this->effectPacket(
                $event->player->runtimeActorId,
                $event->effect,
                $event->tick,
                $event->replacesExisting,
            );

        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $packet);
            if ($event->type === \Bedriox\Api\Effect\EffectType::HEALTH_BOOST
                || $event->type === \Bedriox\Api\Effect\EffectType::ABSORPTION) {
                $packets[] = new DirectedPacket($recipient, $this->healthPacket($event->player));
            }
            if ($event->type === \Bedriox\Api\Effect\EffectType::INVISIBILITY) {
                $metadata = $this->playerMetadata($event->player);
                $flags = array_values(array_filter(
                    $metadata,
                    static fn(ActorMetadata $entry): bool => PlayerActorMetadata::isFlags($entry),
                ));
                $packets[] = new DirectedPacket($recipient, new SetActorDataPacket(
                    UnsignedLong::fromInt($event->player->runtimeActorId),
                    UnsignedLong::fromInt(max(0, $event->tick)),
                    $flags,
                ));
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function potionProjectileSpawned(PotionProjectileSpawned $event): array
    {
        $projectile = $event->projectile;
        $packet = new AddActorPacket(
            $projectile->uniqueEntityId,
            UnsignedLong::fromInt($projectile->runtimeEntityId),
            $projectile->tippedArrow ? 'minecraft:arrow' : 'minecraft:splash_potion',
            $projectile->position->x,
            $projectile->position->y,
            $projectile->position->z,
            $projectile->motion->x,
            $projectile->motion->y,
            $projectile->motion->z,
            0.0,
            0.0,
            0.0,
            0.0,
            metadata: $projectile->tippedArrow
                ? TippedArrowActorMetadata::baseline($projectile->potionType->value)
                : PotionProjectileActorMetadata::baseline(
                    $projectile->potionType->value,
                    $projectile->lingering,
                ),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function potionProjectileMoved(PotionProjectileMoved $event): array
    {
        $projectile = $event->projectile;
        $packet = new MoveActorAbsolutePacket(
            UnsignedLong::fromInt($projectile->runtimeEntityId),
            $projectile->position->x,
            $projectile->position->y,
            $projectile->position->z,
            0.0,
            0.0,
            0.0,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function potionProjectileImpacted(PotionProjectileImpacted $event): array
    {
        $position = new LevelEventPosition($event->position->x, $event->position->y, $event->position->z);
        $argb = PotionColorMixer::forPotion($event->potionType);
        $color = new LevelEventParticleColor(
            ($argb >> 16) & 0xff,
            ($argb >> 8) & 0xff,
            $argb & 0xff,
            ($argb >> 24) & 0xff,
        );
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, LevelEventPacket::potionSplashParticle($position, $color));
            $packets[] = new DirectedPacket(
                $recipient,
                new LevelSoundEventPacket(LevelSoundEventName::glass(), $position),
            );
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function areaEffectCloudSpawned(AreaEffectCloudSpawned $event): array
    {
        $cloud = $event->cloud;
        $packet = new AddActorPacket(
            $cloud->uniqueEntityId,
            UnsignedLong::fromInt($cloud->runtimeEntityId),
            'minecraft:area_effect_cloud',
            $cloud->position->x,
            $cloud->position->y,
            $cloud->position->z,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            0.0,
            metadata: AreaEffectCloudActorMetadata::baseline(
                $cloud->radius,
                PotionColorMixer::forPotion($cloud->potionType),
            ),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function areaEffectCloudUpdated(AreaEffectCloudUpdated $event): array
    {
        $cloud = $event->cloud;
        $packet = new SetActorDataPacket(
            UnsignedLong::fromInt($cloud->runtimeEntityId),
            UnsignedLong::fromInt($cloud->ageTicks),
            AreaEffectCloudActorMetadata::baseline(
                $cloud->radius,
                PotionColorMixer::forPotion($cloud->potionType),
            ),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    private function effectPacket(
        int $runtimeId,
        \Bedriox\Api\Effect\EffectInstance $effect,
        int $tick,
        bool $modify,
    ): MobEffectPacket {
        $arguments = [
            UnsignedLong::fromInt($runtimeId),
            BedrockEffectTranslator::type($effect->type),
            $effect->amplifier,
            $effect->visible,
            $effect->infinite ? -1 : $effect->durationTicks,
            UnsignedLong::fromInt(max(0, $tick)),
            $effect->ambient,
        ];

        return $modify ? MobEffectPacket::modify(...$arguments) : MobEffectPacket::add(...$arguments);
    }

    /**
     * @param array<string, RuntimeSession> $sessions
     * @return list<DirectedPacket>
     */
    private function particle(ParticleSpawned $event, array $sessions): array
    {
        $position = new LevelEventPosition($event->position->x, $event->position->y, $event->position->z);
        $particle = $event->particle;
        $packet = match (true) {
            $particle instanceof SimpleParticle => new SpawnParticleEffectPacket(
                match ($event->dimension) {
                    \Bedriox\Server\Simulation\WorldDimension::OVERWORLD => DimensionId::Overworld,
                    \Bedriox\Server\Simulation\WorldDimension::NETHER => DimensionId::Nether,
                    \Bedriox\Server\Simulation\WorldDimension::END => DimensionId::End,
                },
                SpawnParticleEffectPacket::UNATTACHED_ENTITY_ID,
                $position,
                $particle->type()->value,
                $particle->variables()?->toJson(),
            ),
            $particle instanceof StandardParticle => $particle->type === StandardParticleType::ENDERMAN_TELEPORT
                ? LevelEventPacket::endermanTeleportParticle($position)
                : LevelEventPacket::particle($position, self::standardParticleType($particle->type)),
            $particle instanceof ScalarParticle => LevelEventPacket::scalarParticle(
                $position,
                self::scalarParticleType($particle->type),
                $particle->value,
            ),
            $particle instanceof ColoredParticle => $this->coloredParticle($position, $particle),
            $particle instanceof BlockParticle => $this->blockParticle($position, $particle),
            $particle instanceof ItemBreakParticle => LevelEventPacket::itemBreakParticle(
                $position,
                $this->requireInventoryProjector()->particleItemRuntimeId($particle->item->identifier),
                $particle->item->auxValue,
            ),
            $particle instanceof DragonEggTeleportParticle => LevelEventPacket::dragonEggTeleportParticle(
                $position,
                $particle->offsetX,
                $particle->offsetY,
                $particle->offsetZ,
            ),
            $particle instanceof MobSpawnParticle => LevelEventPacket::mobSpawnParticle(
                $position,
                $particle->width,
                $particle->height,
            ),
            default => throw new \LogicException('Particle type does not have a Bedrock projection.'),
        };
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $session = $sessions[$recipient] ?? null;
            if ($session?->play?->hasSentChunkAt($event->position->x, $event->position->z) !== true) {
                continue;
            }
            $packets[] = new DirectedPacket($recipient, $packet);
        }

        return $packets;
    }

    private static function standardParticleType(StandardParticleType $type): LevelEventParticleType
    {
        $name = match ($type) {
            StandardParticleType::ANGRY_VILLAGER => 'VillagerAngry',
            StandardParticleType::ENCHANTING_TABLE => 'EnchantmentTable',
            StandardParticleType::ENTITY_FLAME => 'MobFlame',
            StandardParticleType::EXPLOSION => 'Explode',
            StandardParticleType::HUGE_EXPLOSION => 'HugeExplode',
            StandardParticleType::HUGE_EXPLOSION_SEED => 'HugeExplodeSeed',
            StandardParticleType::HONEY_DRIP => 'DripHoney',
            StandardParticleType::LAVA_DRIP => 'DripLava',
            StandardParticleType::HAPPY_VILLAGER => 'VillagerHappy',
            StandardParticleType::SPORE => 'SuspendedTown',
            StandardParticleType::STALACTITE_LAVA_DRIP => 'StalactiteDripLava',
            StandardParticleType::STALACTITE_WATER_DRIP => 'StalactiteDripWater',
            StandardParticleType::WATER_DRIP => 'DripWater',
            StandardParticleType::WATER_SPLASH => 'Splash',
            default => str_replace(' ', '', ucwords(str_replace('_', ' ', strtolower($type->name)))),
        };
        $particle = constant(LevelEventParticleType::class . '::' . $name);
        if (!$particle instanceof LevelEventParticleType) {
            throw new \LogicException('Standard particle is not available in the active protocol.');
        }

        return $particle;
    }

    private static function scalarParticleType(ScalarParticleType $type): LevelEventParticleType
    {
        return match ($type) {
            ScalarParticleType::BLOCK_FORCE_FIELD => LevelEventParticleType::BlockForceField,
            ScalarParticleType::CRITICAL => LevelEventParticleType::Critical,
            ScalarParticleType::HEART => LevelEventParticleType::Heart,
            ScalarParticleType::INK => LevelEventParticleType::Ink,
            ScalarParticleType::REDSTONE => LevelEventParticleType::Redstone,
            ScalarParticleType::SMOKE => LevelEventParticleType::Smoke,
        };
    }

    private function coloredParticle(LevelEventPosition $position, ColoredParticle $particle): LevelEventPacket
    {
        $color = self::particleColor($particle->color);
        if ($particle->type === ColoredParticleType::POTION_SPLASH) {
            return LevelEventPacket::potionSplashParticle($position, $color);
        }

        return LevelEventPacket::coloredParticle($position, match ($particle->type) {
            ColoredParticleType::DUST => LevelEventParticleType::FallingDust,
            ColoredParticleType::ENCHANT => LevelEventParticleType::MobSpell,
            ColoredParticleType::AMBIENT_ENCHANT => LevelEventParticleType::MobSpellAmbient,
            ColoredParticleType::INSTANT_ENCHANT => LevelEventParticleType::MobSpellInstantaneous,
        }, $color);
    }

    private function blockParticle(LevelEventPosition $position, BlockParticle $particle): LevelEventPacket
    {
        if ($this->chunks === null) {
            throw new \LogicException('Block particles require the active block-network translator.');
        }
        $runtimeId = $this->chunks->networkRuntimeIdForCanonicalState(
            $particle->block->identifier(),
            $particle->block->properties(),
        );

        return match ($particle->type) {
            BlockParticleType::BREAK => LevelEventPacket::destroyBlock($position, $runtimeId),
            BlockParticleType::TERRAIN => LevelEventPacket::terrainParticle($position, $runtimeId),
            BlockParticleType::PUNCH => LevelEventPacket::punchBlockFace(
                $position,
                $runtimeId,
                self::particleBlockFace($particle->face ?? throw new \LogicException('Punch particle has no face.')),
            ),
        };
    }

    private static function particleColor(ParticleColor $color): LevelEventParticleColor
    {
        return new LevelEventParticleColor($color->red, $color->green, $color->blue, $color->alpha);
    }

    private static function particleBlockFace(BlockFace $face): LevelEventBlockFace
    {
        return match ($face) {
            BlockFace::DOWN => LevelEventBlockFace::Down,
            BlockFace::UP => LevelEventBlockFace::Up,
            BlockFace::NORTH => LevelEventBlockFace::North,
            BlockFace::SOUTH => LevelEventBlockFace::South,
            BlockFace::WEST => LevelEventBlockFace::West,
            BlockFace::EAST => LevelEventBlockFace::East,
        };
    }

    /** @return list<DirectedPacket> */
    private function blockBreakStarted(BlockBreakStarted $event): array
    {
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            if ($event->previousPosition !== null) {
                $packets[] = new DirectedPacket($recipient, $this->blockLevelEvent(3601, $event->previousPosition, 0));
            }
            $packets[] = new DirectedPacket($recipient, $this->blockLevelEvent(3600, $event->position, $event->breakRate));
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function blockBreakStopped(BlockBreakStopped $event): array
    {
        return array_map(
            fn(string $recipient): DirectedPacket => new DirectedPacket(
                $recipient,
                $this->blockLevelEvent(3601, $event->position, 0),
            ),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function blockPunch(BlockPunch $event): array
    {
        if ($this->chunks === null) {
            throw new \LogicException('Block events require the active block-network translator.');
        }
        $packet = LevelEventPacket::punchBlock(
            new LevelEventPosition($event->position->x + 0.5, $event->position->y + 0.5, $event->position->z + 0.5),
            $this->chunks->networkRuntimeId($event->state),
            $event->face,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function entityActorEquipmentChanged(EntityActorEquipmentChanged $event): array
    {
        $equipment = $this->livingActors->equipmentPackets(
            $event->entity,
            $this->inventory,
            $event->changedSlots,
        );
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            foreach ($equipment as $equipmentPacket) {
                $packets[] = new DirectedPacket($recipient, $equipmentPacket);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function blockChanged(BlockChanged $event): array
    {
        if ($this->chunks === null) {
            throw new \LogicException('Block events require the active block-network translator.');
        }
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            if ($event->stopBreaking) {
                $packets[] = new DirectedPacket($recipient, $this->blockLevelEvent(3601, $event->position, 0));
            }
            if ($event->destroyedState !== null) {
                $packets[] = new DirectedPacket($recipient, $this->blockLevelEvent(
                    LevelEventType::DestroyBlock->value,
                    $event->position,
                    $this->chunks->networkRuntimeId($event->destroyedState),
                ));
            }
            $packets[] = new DirectedPacket($recipient, new UpdateBlockPacket(
                new ProtocolBlockPosition($event->position->x, $event->position->y, $event->position->z),
                $this->chunks->networkRuntimeId($event->state),
                [UpdateBlockFlag::Network, UpdateBlockFlag::Priority],
                0,
            ));
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function blockEntityChanged(BlockEntityChanged $event): array
    {
        $packet = new BlockActorDataPacket(
            self::protocolBlockPosition($event->blockEntity->position),
            $this->blockEntities->encodeNetworkEntity($event->blockEntity),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    private function blockLevelEvent(int $eventId, \Bedriox\Server\World\BlockPosition $position, int $data): LevelEventPacket
    {
        return new LevelEventPacket(
            $eventId,
            new LevelEventPosition($position->x + 0.5, $position->y + 0.5, $position->z + 0.5),
            $data,
        );
    }

    /** @return list<DirectedPacket> */
    private function blockPlaced(BlockPlaced $event): array
    {
        $inventory = $this->requireInventoryProjector();
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            if ($event->stoppedBreakingPosition !== null) {
                $packets[] = new DirectedPacket($recipient, $this->blockLevelEvent(3601, $event->stoppedBreakingPosition, 0));
            }
            $packets[] = new DirectedPacket($recipient, $this->updateBlock($event->position, $event->state));
            $packets[] = new DirectedPacket($recipient, new LevelSoundEventPacket(
                LevelSoundEventName::place(),
                new LevelEventPosition($event->position->x + 0.5, $event->position->y + 0.5, $event->position->z + 0.5),
                $this->chunks?->networkRuntimeId($event->state) ?? 0,
            ));
        }
        $packets[] = new DirectedPacket($event->ownerSessionId, new InventorySlotPacket(
            0,
            $event->inventorySlot,
            $inventory->toProtocol($event->remainingStack),
        ));
        foreach ($event->recipientSessionIds as $recipient) {
            if ($recipient === $event->ownerSessionId) {
                continue;
            }
            $packets[] = new DirectedPacket($recipient, new MobEquipmentPacket(
                UnsignedLong::fromInt($event->runtimeActorId),
                $event->inventorySlot,
                $event->inventorySlot,
                0,
                $this->visualStack($event->remainingStack),
            ));
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function blockPlacementCorrected(BlockPlacementCorrected $event): array
    {
        $inventory = $this->requireInventoryProjector();
        $packets = [];
        if ($event->stoppedBreakingPosition !== null) {
            $packets[] = new DirectedPacket(
                $event->ownerSessionId,
                $this->blockLevelEvent(3601, $event->stoppedBreakingPosition, 0),
            );
        }
        $packets[] = new DirectedPacket(
            $event->ownerSessionId,
            $this->updateBlock($event->clickedPosition, $event->clickedState),
        );
        $packets[] = new DirectedPacket(
            $event->ownerSessionId,
            $this->updateBlock($event->placedPosition, $event->placedState),
        );
        $packets[] = new DirectedPacket($event->ownerSessionId, new InventorySlotPacket(
            0,
            $event->inventorySlot,
            $inventory->toProtocol($event->heldStack),
        ));

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function heldItemChanged(HeldItemChanged $event): array
    {
        $packets = $event->ownerSlotCorrection
            ? $this->inventorySlotCorrection(
                $event->ownerSessionId,
                InventoryContainerId::INVENTORY,
                $event->hotbarSlot,
                $event->stack,
            )
            : [];

        array_push($packets, ...array_map(
            fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, new MobEquipmentPacket(
                UnsignedLong::fromInt($event->runtimeActorId),
                $event->hotbarSlot,
                $event->hotbarSlot,
                0,
                $this->visualStack($event->stack),
            )),
            $event->recipientSessionIds,
        ));

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function inventorySlotChanged(InventorySlotChanged $event): array
    {
        return $this->inventorySlotCorrection(
            $event->ownerSessionId,
            InventoryContainerId::INVENTORY,
            $event->slot,
            $event->stack,
        );
    }

    /** @return list<DirectedPacket> */
    private function itemEntitySpawned(ItemEntitySpawned $event): array
    {
        $inventory = $this->requireInventoryProjector();
        $entity = $event->entity;
        $packet = new AddItemActorPacket(
            $entity->uniqueEntityId,
            UnsignedLong::fromInt($entity->runtimeEntityId),
            $inventory->toItemActorProtocol($entity->stack),
            $entity->position->x,
            $entity->position->y + 0.125,
            $entity->position->z,
            $entity->motion->x,
            $entity->motion->y,
            $entity->motion->z,
            ItemActorMetadata::baseline(),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function itemEntityMoved(ItemEntityMoved $event): array
    {
        $entity = $event->entity;
        $position = new MoveActorAbsolutePacket(
            UnsignedLong::fromInt($entity->runtimeEntityId),
            $entity->position->x,
            $entity->position->y + 0.125,
            $entity->position->z,
            0.0,
            0.0,
            0.0,
            $entity->motion->y === 0.0 ? [MoveActorAbsoluteFlag::OnGround] : [],
        );
        $motion = $event->motionChanged ? new SetActorMotionPacket(
            UnsignedLong::fromInt($entity->runtimeEntityId),
            $entity->motion->x,
            $entity->motion->y,
            $entity->motion->z,
            UnsignedLong::fromInt(0),
        ) : null;

        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $position);
            if ($motion !== null) {
                $packets[] = new DirectedPacket($recipient, $motion);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function entityActorSpawned(EntityActorSpawned $event): array
    {
        $entity = $event->entity;
        $position = $entity->internalPosition();
        $motion = $entity->getMotion();
        $runtimeId = $entity->getRuntimeId();
        $packet = new AddActorPacket(
            $runtimeId,
            UnsignedLong::fromInt($runtimeId),
            $entity->definition()->networkIdentifier,
            $position->x,
            $position->y,
            $position->z,
            $motion->x,
            $motion->y,
            $motion->z,
            $entity->getPitch(),
            $entity->getYaw(),
            $entity->getYaw(),
            $entity->getYaw(),
            $this->livingActors->spawnAttributes($entity),
            $this->livingActors->metadata($entity, $event->noAi),
            new ActorProperties(),
            [],
        );

        $equipment = $this->livingActors->equipmentPackets($entity, $this->inventory);
        $effects = array_map(
            fn(\Bedriox\Api\Effect\EffectInstance $effect): MobEffectPacket => $this->effectPacket(
                $runtimeId,
                $effect,
                0,
                false,
            ),
            array_values($entity->effectState()->snapshot()),
        );
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $packet);
            foreach ($equipment as $equipmentPacket) {
                $packets[] = new DirectedPacket($recipient, $equipmentPacket);
            }
            foreach ($effects as $effectPacket) {
                $packets[] = new DirectedPacket($recipient, $effectPacket);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function entityActorEffectChanged(EntityActorEffectChanged $event): array
    {
        $packet = $event->effect === null
            ? MobEffectPacket::remove(
                UnsignedLong::fromInt($event->entity->getRuntimeId()),
                BedrockEffectTranslator::type($event->type),
                UnsignedLong::fromInt(max(0, $event->tick)),
            )
            : $this->effectPacket(
                $event->entity->getRuntimeId(),
                $event->effect,
                $event->tick,
                $event->replacesExisting,
            );

        $attributes = null;
        if ($event->type === \Bedriox\Api\Effect\EffectType::HEALTH_BOOST
            || $event->type === \Bedriox\Api\Effect\EffectType::ABSORPTION) {
            $attributes = new UpdateAttributesPacket(
                UnsignedLong::fromInt($event->entity->getRuntimeId()),
                [
                    $this->livingActors->healthAttribute($event->entity),
                    $this->livingActors->absorptionAttribute($event->entity),
                ],
                UnsignedLong::fromInt(max(0, $event->tick)),
            );
        }
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $packet);
            if ($attributes !== null) {
                $packets[] = new DirectedPacket($recipient, $attributes);
            }
            if ($event->type === \Bedriox\Api\Effect\EffectType::INVISIBILITY) {
                $packets[] = new DirectedPacket($recipient, new SetActorDataPacket(
                    UnsignedLong::fromInt($event->entity->getRuntimeId()),
                    UnsignedLong::fromInt(max(0, $event->tick)),
                    [$this->livingActors->flagsMetadata($event->entity)],
                ));
            }
        }

        return $packets;
    }

    /** @return non-empty-list<Packet> */
    public function entityMovementPackets(EntityActorMoved $event): array
    {
        $entity = $event->entity;
        $position = $entity->internalPosition();
        $motion = $entity->getMotion();
        $runtimeId = UnsignedLong::fromInt($entity->getRuntimeId());
        $packets = [new MoveActorAbsolutePacket(
            $runtimeId,
            $position->x,
            $position->y,
            $position->z,
            $entity->getPitch(),
            $entity->getYaw(),
            $entity->getYaw(),
            $entity->isOnGround() ? [MoveActorAbsoluteFlag::OnGround] : [],
        )];
        if ($event->motionChanged) {
            $packets[] = new SetActorMotionPacket(
                $runtimeId,
                $motion->x,
                $motion->y,
                $motion->z,
                UnsignedLong::fromInt(max(0, $event->tick)),
            );
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function entityActorMoved(EntityActorMoved $event): array
    {
        $sharedPackets = $this->entityMovementPackets($event);

        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            foreach ($sharedPackets as $packet) {
                $packets[] = new DirectedPacket($recipient, $packet);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function entityActorDamaged(EntityActorDamaged $event): array
    {
        $runtimeId = UnsignedLong::fromInt($event->entity->getRuntimeId());
        $health = new UpdateAttributesPacket(
            $runtimeId,
            [
                $this->livingActors->healthAttribute($event->entity),
                $this->livingActors->absorptionAttribute($event->entity),
            ],
            UnsignedLong::fromInt(max(0, $event->tick)),
        );
        $hurt = new ActorEventPacket($runtimeId, ActorEventType::Hurt);
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $health);
            $packets[] = new DirectedPacket($recipient, $hurt);
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function entityActorHealthChanged(EntityActorHealthChanged $event): array
    {
        $packet = new UpdateAttributesPacket(
            UnsignedLong::fromInt($event->entity->getRuntimeId()),
            [
                $this->livingActors->healthAttribute($event->entity),
                $this->livingActors->absorptionAttribute($event->entity),
            ],
            UnsignedLong::fromInt(max(0, $event->tick)),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function entityActorMetadataChanged(EntityActorMetadataChanged $event): array
    {
        $packet = new SetActorDataPacket(
            UnsignedLong::fromInt($event->entity->getRuntimeId()),
            UnsignedLong::fromInt(max(0, $event->tick)),
            $this->livingActors->metadata($event->entity, $event->noAi),
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function entityActorDied(EntityActorDied $event): array
    {
        $packet = new ActorEventPacket(
            UnsignedLong::fromInt($event->entity->getRuntimeId()),
            ActorEventType::Death,
        );

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function entityActorRemoved(EntityActorRemoved $event): array
    {
        $packet = new RemoveActorPacket($event->entity->getRuntimeId());

        return array_map(
            static fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, $packet),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function itemEntityPickedUp(ItemEntityPickedUp $event): array
    {
        $packets = [];
        $take = new TakeItemActorPacket(
            UnsignedLong::fromInt($event->itemRuntimeActorId),
            UnsignedLong::fromInt($event->collectorRuntimeActorId),
        );
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $take);
            if ($event->removed) {
                $packets[] = new DirectedPacket($recipient, new RemoveActorPacket($event->itemRuntimeActorId));
            }
        }
        $inventory = $this->requireInventoryProjector();
        $packets[] = new DirectedPacket($event->collectorSessionId, new InventoryContentPacket(
            0,
            array_map($inventory->toProtocol(...), $event->mainInventory),
        ));

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function gameModeChanged(PlayerGameModeChanged $event): array
    {
        $projector = new GameModePacketProjector();
        $packets = [new DirectedPacket(
            $event->player->sessionId,
            new SetPlayerGameTypePacket($projector->gameType($event->gameMode)),
        ), new DirectedPacket(
            $event->player->sessionId,
            new MovePlayerPacket(
                UnsignedLong::fromInt($event->player->runtimeActorId),
                $event->player->position->x,
                PlayerPositionProjection::feetToWireY($event->player->position->y),
                $event->player->position->z,
                $event->player->pitch,
                $event->player->yaw,
                $event->player->headYaw,
                MovePlayerMode::TELEPORT,
                $event->player->verticalState === VerticalState::GROUNDED,
                UnsignedLong::fromInt(0),
                UnsignedLong::fromInt(max(0, $event->player->movementSequence)),
            ),
        )];
        $peerPacket = new UpdatePlayerGameTypePacket(
            $projector->gameType($event->gameMode),
            $event->player->runtimeActorId,
            UnsignedLong::fromInt($event->tick),
        );
        foreach ($event->recipientSessionIds as $recipient) {
            if ($recipient !== $event->player->sessionId) {
                $packets[] = new DirectedPacket($recipient, $peerPacket);
            }
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function inventoryStackRequestProcessed(InventoryStackRequestProcessed $event): array
    {
        $containers = [];
        if ($event->success) {
            $slotsByContainer = [];
            foreach ($event->affectedSlots as $reference) {
                $containerId = $reference->responseContainerId ?? self::fullContainerNameId($reference->container);
                $stack = self::processedStack($event, $reference);
                $containerKey = $containerId . ':' . ($reference->responseContainerDynamicId ?? '');
                $slotsByContainer[$containerKey][] = new ItemStackResponseSlot(
                    $reference->responseSlotId(),
                    $reference->responseSlotId(),
                    $stack === null ? 0 : $stack->count,
                    $stack?->stackNetworkId,
                    filteredCustomName: '',
                    durabilityCorrection: $stack === null ? 0 : $stack->damage,
                );
            }
            foreach ($slotsByContainer as $containerKey => $slots) {
                [$containerId, $dynamicPart] = explode(':', (string) $containerKey, 2);
                $dynamicId = $dynamicPart === '' ? null : (int) $dynamicPart;
                $containers[] = new ItemStackResponseContainer(
                    new FullContainerName((int) $containerId, $dynamicId),
                    $slots,
                );
            }
        }
        $packets = [];
        if ($event->responseMode === InventoryResponseMode::ItemStackResponse) {
            $response = new ItemStackResponse(
                $event->success ? ItemStackResponse::STATUS_SUCCESS : ItemStackResponse::STATUS_ERROR,
                $event->requestId,
                $containers,
            );
            $packets[] = new DirectedPacket($event->ownerSessionId, new ItemStackResponsePacket([$response]));
        }
        if ($event->fullSync) {
            $packets = [
                ...$packets,
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::INVENTORY,
                    $event->mainInventory,
                ),
                ...$this->inventorySlotCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::UI,
                    0,
                    $event->cursorStack,
                ),
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::ARMOR,
                    self::normalizeArmor($event->armorInventory),
                ),
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::OFFHAND,
                    [$event->offhandStack],
                ),
                ...$this->craftingGridCorrection($event),
                ...$this->openedContainerCorrection($event),
            ];
        } elseif (!$event->success) {
            $packets = [
                ...$packets,
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::INVENTORY,
                    $event->mainInventory,
                ),
                ...$this->inventorySlotCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::UI,
                    0,
                    $event->cursorStack,
                ),
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::ARMOR,
                    self::normalizeArmor($event->armorInventory),
                ),
                ...$this->inventoryContentCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::OFFHAND,
                    [$event->offhandStack],
                ),
                ...$this->craftingGridCorrection($event),
                ...$this->openedContainerCorrection($event),
            ];
        } elseif ($event->responseMode === InventoryResponseMode::LegacySlotSync) {
            $synchronized = [];
            foreach ($event->affectedSlots as $reference) {
                if (isset($synchronized[$reference->key()])) {
                    continue;
                }
                $synchronized[$reference->key()] = true;
                $stack = self::processedStack($event, $reference);
                if ($reference->container === InventoryContainer::Offhand) {
                    $packets = [
                        ...$packets,
                        ...$this->inventoryContentCorrection(
                            $event->ownerSessionId,
                            InventoryContainerId::OFFHAND,
                            [$stack],
                        ),
                    ];
                } else {
                    $packets = [
                        ...$packets,
                        ...$this->inventorySlotCorrection(
                            $event->ownerSessionId,
                            $reference->container === InventoryContainer::OpenedContainer
                                ? ($event->openedContainerWindowId ?? throw new \LogicException(
                                    'Opened-container correction requires its dynamic window ID.',
                                ))
                                : self::windowId($reference->container),
                            $reference->responseSlotId(),
                            $stack,
                        ),
                    ];
                }
            }
        }
        if ($event->success && $event->selectedStackChanged) {
            foreach ($event->peerSessionIds as $recipient) {
                $packets[] = new DirectedPacket($recipient, new MobEquipmentPacket(
                    UnsignedLong::fromInt($event->runtimeActorId),
                    $event->selectedHotbarSlot,
                    $event->selectedHotbarSlot,
                    0,
                    $this->visualStack($event->selectedStack),
                ));
            }
        }
        $armorChanged = false;
        $offhandChanged = false;
        foreach ($event->affectedSlots as $reference) {
            $armorChanged = $armorChanged || $reference->container === InventoryContainer::Armor;
            $offhandChanged = $offhandChanged || $reference->container === InventoryContainer::Offhand;
        }
        if ($event->success && ($armorChanged || $offhandChanged)) {
            $armor = new MobArmorEquipmentPacket(
                UnsignedLong::fromInt($event->runtimeActorId),
                $this->visualStack($event->armorInventory[0] ?? null),
                $this->visualStack($event->armorInventory[1] ?? null),
                $this->visualStack($event->armorInventory[2] ?? null),
                $this->visualStack($event->armorInventory[3] ?? null),
            );
            $offhand = new MobEquipmentPacket(
                UnsignedLong::fromInt($event->runtimeActorId),
                0,
                0,
                InventoryContainerId::OFFHAND,
                $this->visualStack($event->offhandStack),
            );
            foreach ($event->peerSessionIds as $recipient) {
                if ($armorChanged) {
                    $packets[] = new DirectedPacket($recipient, $armor);
                }
                if ($offhandChanged) {
                    $packets[] = new DirectedPacket($recipient, $offhand);
                }
            }
        }

        return $packets;
    }

    private function armorEquipment(PlayerSnapshot $player): MobArmorEquipmentPacket
    {
        return new MobArmorEquipmentPacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            $this->visualStack($player->armor[0] ?? null),
            $this->visualStack($player->armor[1] ?? null),
            $this->visualStack($player->armor[2] ?? null),
            $this->visualStack($player->armor[3] ?? null),
        );
    }

    private function visualStack(?\Bedriox\Server\Player\InventoryStack $stack): ProtocolInventoryItemStack
    {
        $projected = $this->requireInventoryProjector()->toProtocol($stack);

        return new ProtocolInventoryItemStack(
            $projected->runtimeId,
            $projected->count,
            $projected->aux,
            null,
            $projected->blockRuntimeId,
            $projected->userData,
        );
    }

    /**
     * @param list<?\Bedriox\Server\Player\InventoryStack> $stacks
     * @return list<DirectedPacket>
     */
    private function inventoryContentCorrection(string $sessionId, int $containerId, array $stacks): array
    {
        $inventory = $this->requireInventoryProjector();
        $empty = array_map(
            static fn(): ProtocolInventoryItemStack => ProtocolInventoryItemStack::empty(),
            $stacks,
        );
        $actual = array_map($inventory->toProtocol(...), $stacks);

        return [
            new DirectedPacket($sessionId, new InventoryContentPacket($containerId, $empty)),
            new DirectedPacket($sessionId, new InventoryContentPacket($containerId, $actual)),
        ];
    }

    /** @return list<DirectedPacket> */
    private function inventorySlotCorrection(
        string $sessionId,
        int $containerId,
        int $slot,
        ?\Bedriox\Server\Player\InventoryStack $stack,
    ): array {
        $actual = $this->requireInventoryProjector()->toProtocol($stack);
        $packets = [];
        if ($actual->stackNetworkId !== null) {
            $packets[] = new DirectedPacket(
                $sessionId,
                new InventorySlotPacket($containerId, $slot, ProtocolInventoryItemStack::empty()),
            );
        }
        $packets[] = new DirectedPacket($sessionId, new InventorySlotPacket($containerId, $slot, $actual));

        return $packets;
    }

    /**
     * @param list<?\Bedriox\Server\Player\InventoryStack> $armor
     * @return list<?\Bedriox\Server\Player\InventoryStack>
     */
    private static function normalizeArmor(array $armor): array
    {
        return [
            $armor[0] ?? null,
            $armor[1] ?? null,
            $armor[2] ?? null,
            $armor[3] ?? null,
        ];
    }

    private static function processedStack(
        InventoryStackRequestProcessed $event,
        \Bedriox\Server\Player\InventorySlotReference $reference,
    ): ?\Bedriox\Server\Player\InventoryStack {
        return match ($reference->container) {
            InventoryContainer::Main => $event->mainInventory[$reference->slot] ?? null,
            InventoryContainer::Cursor => $event->cursorStack,
            InventoryContainer::Armor => $event->armorInventory[$reference->slot] ?? null,
            InventoryContainer::Offhand => $event->offhandStack,
            InventoryContainer::CraftingInput => $event->craftingInventory[$reference->slot] ?? null,
            InventoryContainer::CreatedOutput => null,
            InventoryContainer::OpenedContainer => $event->openedContainerInventory[$reference->slot] ?? null,
        };
    }

    private static function fullContainerNameId(InventoryContainer $container): int
    {
        return match ($container) {
            InventoryContainer::Main => FullContainerName::INVENTORY,
            InventoryContainer::Cursor => FullContainerName::CURSOR,
            InventoryContainer::Armor => FullContainerName::ARMOR,
            InventoryContainer::Offhand => FullContainerName::OFFHAND,
            InventoryContainer::CraftingInput => FullContainerName::CRAFTING_INPUT,
            InventoryContainer::CreatedOutput => FullContainerName::CREATED_OUTPUT,
            InventoryContainer::OpenedContainer => FullContainerName::LEVEL_ENTITY,
        };
    }

    private static function windowId(InventoryContainer $container): int
    {
        return match ($container) {
            InventoryContainer::Main => InventoryContainerId::INVENTORY,
            InventoryContainer::Cursor, InventoryContainer::CreatedOutput => InventoryContainerId::UI,
            InventoryContainer::Armor => InventoryContainerId::ARMOR,
            InventoryContainer::Offhand => InventoryContainerId::OFFHAND,
            InventoryContainer::CraftingInput => InventoryContainerId::UI,
            InventoryContainer::OpenedContainer => throw new \LogicException(
                'Opened-container correction requires its dynamic window ID.',
            ),
        };
    }

    /** @return list<DirectedPacket> */
    private function openedContainerCorrection(InventoryStackRequestProcessed $event): array
    {
        if ($event->openedContainerWindowId === null || $event->openedContainerInventory === []) {
            return [];
        }

        return $this->inventoryContentCorrection(
            $event->ownerSessionId,
            $event->openedContainerWindowId,
            $event->openedContainerInventory,
        );
    }

    /** @return list<DirectedPacket> */
    private function craftingGridCorrection(InventoryStackRequestProcessed $event): array
    {
        $count = count($event->craftingInventory);
        if ($count !== 4 && $count !== 9) {
            return [];
        }
        $offset = $count === 4 ? 28 : 32;
        $packets = [];
        foreach ($event->craftingInventory as $slot => $stack) {
            $packets = [
                ...$packets,
                ...$this->inventorySlotCorrection(
                    $event->ownerSessionId,
                    InventoryContainerId::UI,
                    $offset + $slot,
                    $stack,
                ),
            ];
        }

        return $packets;
    }

    private function updateBlock(
        \Bedriox\Server\World\BlockPosition $position,
        \Bedriox\Server\World\Block\InternalBlockStateId $state,
    ): UpdateBlockPacket {
        if ($this->chunks === null) {
            throw new \LogicException('Block events require the active block-network translator.');
        }

        return new UpdateBlockPacket(
            new ProtocolBlockPosition($position->x, $position->y, $position->z),
            $this->chunks->networkRuntimeId($state),
            [UpdateBlockFlag::Network, UpdateBlockFlag::Priority],
            0,
        );
    }

    private function requireInventoryProjector(): BedrockInventoryPacketProjector
    {
        return $this->inventory
            ?? throw new \LogicException('Inventory events require the active item-network translator.');
    }

}
