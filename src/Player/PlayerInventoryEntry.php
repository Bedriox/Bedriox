<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** One occupied main-inventory slot in a session-independent inventory state. */
final readonly class PlayerInventoryEntry
{
    public function __construct(
        public int $slot,
        public PlayerInventoryStackState $stack,
    ) {
        if ($this->slot < 0 || $this->slot >= PlayerInventory::SLOT_COUNT) {
            throw new InvalidArgumentException('Inventory entry slot is outside the main inventory.');
        }
    }
}
