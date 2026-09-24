<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\ItemUseCancellationReason;

final readonly class ItemUseCancelled implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public int $runtimeActorId,
        public int $hotbarSlot,
        public ?InventoryStack $stack,
        public ItemUseCancellationReason $reason,
        public array $recipientSessionIds,
        public bool $sneaking = false,
        public bool $sprinting = false,
        public int $movementSequence = 0,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
