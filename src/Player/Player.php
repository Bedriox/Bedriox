<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;

/** Authoritative mutable player aggregate; it deliberately has no packet or socket API. */
final class Player
{
    public int $chatSequence = -1;
    public int $chatTokens;
    public int $lastChatRefillTick;
    public ?int $lastEmoteTick = null;
    public int $placementSequence = -1;
    public readonly PlayerMovement $movement;
    public readonly PlayerInventory $inventory;

    public function __construct(
        public readonly string $sessionId,
        public readonly int $runtimeActorId,
        public readonly PlayerIdentity $identity,
        Position $spawn,
        int $chatTokens,
        int $tick,
        float $groundY,
        ?PlayerInventory $inventory = null,
    ) {
        $this->chatTokens = $chatTokens;
        $this->lastChatRefillTick = $tick;
        $this->movement = new PlayerMovement(
            $spawn,
            $spawn->y <= $groundY ? VerticalState::GROUNDED : VerticalState::AIRBORNE,
            $tick,
            $tick,
        );
        $this->inventory = $inventory ?? PlayerInventory::empty();
    }

    public function snapshot(): PlayerSnapshot
    {
        return new PlayerSnapshot(
            $this->sessionId,
            $this->identity->uuid,
            $this->identity->displayName,
            $this->movement->position,
            $this->movement->yaw,
            $this->movement->pitch,
            $this->movement->mode,
            $this->movement->sequence,
            $this->movement->verticalState,
            $this->movement->verticalVelocity,
            $this->runtimeActorId,
            $this->movement->headYaw,
            $this->movement->sneaking,
            $this->movement->sprinting,
        );
    }
}
