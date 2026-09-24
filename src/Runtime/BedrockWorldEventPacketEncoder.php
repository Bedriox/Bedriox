<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\TranslatableMessage;
use Bedriox\Protocol\Packet\AbilityLayer;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\AddItemActorPacket;
use Bedriox\Protocol\Packet\AddPlayerPacket;
use Bedriox\Protocol\Packet\BlockPosition as ProtocolBlockPosition;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\CommandPermissionLevel;
use Bedriox\Protocol\Packet\CorrectPlayerMovePredictionPacket;
use Bedriox\Protocol\Packet\DeathInfoPacket;
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
use Bedriox\Protocol\Packet\LevelEventPacket;
use Bedriox\Protocol\Packet\LevelEventPosition;
use Bedriox\Protocol\Packet\LevelEventType;
use Bedriox\Protocol\Packet\LevelSoundEventName;
use Bedriox\Protocol\Packet\LevelSoundEventPacket;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MoveActorAbsoluteFlag;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\MovePlayerMode;
use Bedriox\Protocol\Packet\MovePlayerPacket;
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
use Bedriox\Protocol\Packet\PredictionType;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Protocol\Packet\SetPlayerGameTypePacket;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\TakeItemActorPacket;
use Bedriox\Protocol\Packet\TranslatedTextPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\UpdateBlockFlag;
use Bedriox\Protocol\Packet\UpdateBlockPacket;
use Bedriox\Protocol\Packet\UpdatePlayerGameTypePacket;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
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
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerGameModeChanged;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\VerticalState;

/** Stateless current-Bedrock projection of authoritative simulation events. */
final class BedrockWorldEventPacketEncoder implements WorldEventPacketEncoder
{
    public function __construct(
        private readonly ?BedrockChunkPacketSerializer $chunks = null,
        private readonly ?BedrockInventoryPacketProjector $inventory = null,
    ) {}

    public function encode(WorldEvent $event, array $sessions): array
    {
        return match (true) {
            $event instanceof PlayerJoined => $this->joined($event, $sessions),
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
            $event instanceof ChatBroadcast => array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket(
                    $recipient,
                    new ChatPacket($event->senderDisplayName, $event->message),
                ),
                $event->recipientSessionIds,
            ),
            $event instanceof EmotePerformed => $this->emote($event, $sessions),
            $event instanceof PlayerDisconnected => $this->disconnected($event),
            $event instanceof BlockBreakStarted => $this->blockBreakStarted($event),
            $event instanceof BlockPunch => $this->blockPunch($event),
            $event instanceof BlockBreakStopped => $this->blockBreakStopped($event),
            $event instanceof BlockChanged => $this->blockChanged($event),
            $event instanceof BlockPlaced => $this->blockPlaced($event),
            $event instanceof BlockPlacementCorrected => $this->blockPlacementCorrected($event),
            $event instanceof HeldItemChanged => $this->heldItemChanged($event),
            $event instanceof InventoryStackRequestProcessed => $this->inventoryStackRequestProcessed($event),
            $event instanceof ItemUseStarted => $this->itemUseStarted($event),
            $event instanceof ItemUseCancelled => $this->itemUseCancelled($event),
            $event instanceof ItemConsumed => $this->itemConsumed($event),
            $event instanceof NutritionChanged => [new DirectedPacket(
                $event->player->sessionId,
                $this->nutritionPacket($event->player),
            )],
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
            $event instanceof PlayerGameModeChanged => $this->gameModeChanged($event),
            $event instanceof PlayerDamaged => $this->damaged($event),
            $event instanceof PlayerHealed => [new DirectedPacket(
                $event->player->sessionId,
                $this->healthPacket($event->player),
            )],
            $event instanceof PlayerKnockedBack => array_map(
                static fn(string $recipient): DirectedPacket => new DirectedPacket(
                    $recipient,
                    new SetActorMotionPacket(
                        UnsignedLong::fromInt($event->player->runtimeActorId),
                        $event->motionX,
                        $event->motionY,
                        $event->motionZ,
                        new UnsignedLong($event->clientTick->high, $event->clientTick->low),
                    ),
                ),
                $event->recipientSessionIds,
            ),
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
        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $motion);
            if ($event->postureChanged) {
                $packets[] = new DirectedPacket(
                    $recipient,
                    SetActorDataPacket::playerPosture(
                        UnsignedLong::fromInt($event->player->runtimeActorId),
                        new UnsignedLong($event->clientTick->high, $event->clientTick->low),
                        $event->player->sneaking,
                        $event->player->sprinting,
                    ),
                );
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
            [new PlayerAttribute('minecraft:health', 0.0, 20.0, $player->health, 0.0, 20.0, 20.0)],
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
        foreach ($event->recipientSessionIds as $recipient) {
            // The joining client already received its own list entry in the bootstrap.
            if ($recipient === $event->player->sessionId) {
                continue;
            }
            $packets[] = new DirectedPacket($recipient, new PlayerListAddPacket([$this->listEntry($event->player, $joined)]));
        }
        foreach ($event->existingPeers as $peer) {
            $session = $sessions[$peer->sessionId] ?? null;
            if (!$session instanceof RuntimeSession || $session->play === null) {
                continue;
            }
            $packets[] = new DirectedPacket(
                $event->player->sessionId,
                new PlayerListAddPacket([$this->listEntry($peer, $session)]),
            );
        }

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function peerMovement(PlayerMoved $event): array
    {
        $player = $event->player;
        $packet = new MoveActorAbsolutePacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            $player->pitch,
            $player->yaw,
            $player->headYaw,
            $player->verticalState === VerticalState::GROUNDED ? [MoveActorAbsoluteFlag::OnGround] : [],
        );

        $packets = [];
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $packet);
            if ($event->postureChanged) {
                $packets[] = new DirectedPacket(
                    $recipient,
                    SetActorDataPacket::playerPosture(
                        UnsignedLong::fromInt($player->runtimeActorId),
                        UnsignedLong::fromInt(max(0, $player->movementSequence)),
                        $player->sneaking,
                        $player->sprinting,
                    ),
                );
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
            metadata: PlayerActorMetadata::baseline(
                $player->displayName,
                $player->sneaking,
                $player->sprinting,
            ),
        );
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
            throw new \LogicException('A visible player must own authenticated play state.');
        }

        return [
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
                $slotsByContainer[$containerId][] = new ItemStackResponseSlot(
                    $reference->responseSlotId(),
                    $reference->responseSlotId(),
                    $stack === null ? 0 : $stack->count,
                    $stack?->stackNetworkId,
                    durabilityCorrection: $stack === null ? 0 : $stack->damage,
                );
            }
            foreach ($slotsByContainer as $containerId => $slots) {
                $containers[] = new ItemStackResponseContainer(new FullContainerName($containerId), $slots);
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
                            self::windowId($reference->container),
                            $reference->slot,
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
            InventoryContainer::CreatedOutput => null,
        };
    }

    private static function fullContainerNameId(InventoryContainer $container): int
    {
        return match ($container) {
            InventoryContainer::Main => FullContainerName::INVENTORY,
            InventoryContainer::Cursor => FullContainerName::CURSOR,
            InventoryContainer::Armor => FullContainerName::ARMOR,
            InventoryContainer::Offhand => FullContainerName::OFFHAND,
            InventoryContainer::CreatedOutput => FullContainerName::CREATED_OUTPUT,
        };
    }

    private static function windowId(InventoryContainer $container): int
    {
        return match ($container) {
            InventoryContainer::Main => InventoryContainerId::INVENTORY,
            InventoryContainer::Cursor, InventoryContainer::CreatedOutput => InventoryContainerId::UI,
            InventoryContainer::Armor => InventoryContainerId::ARMOR,
            InventoryContainer::Offhand => InventoryContainerId::OFFHAND,
        };
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
