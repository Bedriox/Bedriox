<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;

final readonly class HeldItemChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public int $runtimeActorId,
        public int $hotbarSlot,
        public ?InventoryStack $stack,
        public array $recipientSessionIds,
        public bool $ownerSlotCorrection = false,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
