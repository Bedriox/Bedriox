<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Packet\AbilityLayer;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorEventType;
use Bedriox\Protocol\Packet\AddPlayerPacket;
use Bedriox\Protocol\Packet\BlockPosition as ProtocolBlockPosition;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\DeathInfoPacket;
use Bedriox\Protocol\Packet\EmoteFlag;
use Bedriox\Protocol\Packet\EmotePacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\InventorySlotPacket;
use Bedriox\Protocol\Packet\ItemStackResponse;
use Bedriox\Protocol\Packet\ItemStackResponseContainer;
use Bedriox\Protocol\Packet\ItemStackResponsePacket;
use Bedriox\Protocol\Packet\ItemStackResponseSlot;
use Bedriox\Protocol\Packet\LevelEventPacket;
use Bedriox\Protocol\Packet\LevelEventPosition;
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
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\RespawnPacket;
use Bedriox\Protocol\Packet\RespawnState;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\UpdateBlockFlag;
use Bedriox\Protocol\Packet\UpdateBlockPacket;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
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
                    $event->authoritativePlayer,
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
            $event instanceof BlockBreakStopped => $this->blockBreakStopped($event),
            $event instanceof BlockChanged => $this->blockChanged($event),
            $event instanceof BlockPlaced => $this->blockPlaced($event),
            $event instanceof BlockPlacementCorrected => $this->blockPlacementCorrected($event),
            $event instanceof HeldItemChanged => $this->heldItemChanged($event),
            $event instanceof InventoryStackRequestProcessed => $this->inventoryStackRequestProcessed($event),
            $event instanceof PlayerDamaged => $this->damaged($event),
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
        foreach ($event->recipientSessionIds as $recipient) {
            $packets[] = new DirectedPacket($recipient, $animation);
        }
        $packets[] = new DirectedPacket(
            $event->player->sessionId,
            $this->respawnPacket($event->player, RespawnState::ServerSearching),
        );
        $message = $event->cause === \Bedriox\Server\Simulation\DamageCause::Fall
            ? 'death.fell.accident.generic'
            : 'death.attack.generic';
        $packets[] = new DirectedPacket(
            $event->player->sessionId,
            new DeathInfoPacket($message, [$event->player->displayName]),
        );

        return $packets;
    }

    /** @return list<DirectedPacket> */
    private function respawned(PlayerRespawned $event): array
    {
        $player = $event->player;
        $packets = [
            new DirectedPacket($player->sessionId, $this->healthPacket($player)),
            new DirectedPacket($player->sessionId, new MovePlayerPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                $player->position->x,
                PlayerPositionProjection::feetToWireY($player->position->y),
                $player->position->z,
                $player->pitch,
                $player->yaw,
                $player->headYaw,
                MovePlayerMode::RESET,
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
            $owner = array_map(
                fn(?\Bedriox\Server\Player\InventoryStack $stack) => $this->inventory->toProtocol($stack),
                $event->inventory,
            );
            $packets[] = new DirectedPacket($player->sessionId, new InventoryContentPacket(0, $owner));
            $packets[] = new DirectedPacket($player->sessionId, new MobEquipmentPacket(
                UnsignedLong::fromInt($player->runtimeActorId),
                $event->selectedHotbarSlot,
                $event->selectedHotbarSlot,
                0,
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
    private function movementCorrection(PlayerSnapshot $player, array $recipients): array
    {
        $packet = new MovePlayerPacket(
            UnsignedLong::fromInt($player->runtimeActorId),
            $player->position->x,
            PlayerPositionProjection::feetToWireY($player->position->y),
            $player->position->z,
            $player->pitch,
            $player->yaw,
            $player->headYaw,
            MovePlayerMode::RESET,
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
            1,
            0,
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
                $inventory->toProtocol($event->remainingStack),
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
        $inventory = $this->requireInventoryProjector();

        return array_map(
            fn(string $recipient): DirectedPacket => new DirectedPacket($recipient, new MobEquipmentPacket(
                UnsignedLong::fromInt($event->runtimeActorId),
                $event->hotbarSlot,
                $event->hotbarSlot,
                0,
                $inventory->toProtocol($event->stack),
            )),
            $event->recipientSessionIds,
        );
    }

    /** @return list<DirectedPacket> */
    private function inventoryStackRequestProcessed(InventoryStackRequestProcessed $event): array
    {
        $inventory = $this->requireInventoryProjector();
        $containers = [];
        if ($event->success) {
            $slotsByContainer = [];
            foreach ($event->affectedSlots as $reference) {
                $containerId = $reference->responseContainerId ?? ($reference->container === InventoryContainer::Main
                    ? FullContainerName::INVENTORY
                    : FullContainerName::CURSOR);
                $stack = $reference->container === InventoryContainer::Main
                    ? $event->mainInventory[$reference->slot]
                    : $event->cursorStack;
                $slotsByContainer[$containerId][] = new ItemStackResponseSlot(
                    $reference->slot,
                    $reference->slot,
                    $stack === null ? 0 : $stack->count,
                    $stack?->stackNetworkId,
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
        if (!$event->success) {
            $main = array_map(
                fn(?\Bedriox\Server\Player\InventoryStack $stack) => $inventory->toProtocol($stack),
                $event->mainInventory,
            );
            $packets[] = new DirectedPacket($event->ownerSessionId, new InventoryContentPacket(0, $main));
            $packets[] = new DirectedPacket($event->ownerSessionId, new InventorySlotPacket(
                124,
                0,
                $inventory->toProtocol($event->cursorStack),
            ));
        } elseif ($event->responseMode === InventoryResponseMode::LegacySlotSync) {
            $synchronized = [];
            foreach ($event->affectedSlots as $reference) {
                if (isset($synchronized[$reference->key()])) {
                    continue;
                }
                $synchronized[$reference->key()] = true;
                $stack = $reference->container === InventoryContainer::Main
                    ? $event->mainInventory[$reference->slot]
                    : $event->cursorStack;
                $packets[] = new DirectedPacket($event->ownerSessionId, new InventorySlotPacket(
                    $reference->container === InventoryContainer::Main ? 0 : 124,
                    $reference->slot,
                    $inventory->toProtocol($stack),
                ));
            }
        }
        if ($event->success && $event->selectedStackChanged) {
            foreach ($event->peerSessionIds as $recipient) {
                $packets[] = new DirectedPacket($recipient, new MobEquipmentPacket(
                    UnsignedLong::fromInt($event->runtimeActorId),
                    $event->selectedHotbarSlot,
                    $event->selectedHotbarSlot,
                    0,
                    $inventory->toProtocol($event->selectedStack),
                ));
            }
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
