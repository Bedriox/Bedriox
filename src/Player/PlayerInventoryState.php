<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Immutable, session-independent inventory snapshot using canonical item identities. */
final readonly class PlayerInventoryState
{
    /** @var list<PlayerInventoryEntry> */
    public array $entries;

    /**
     * @param list<PlayerInventoryEntry> $entries
     */
    public function __construct(
        array $entries,
        public int $selectedHotbarSlot,
        public ?PlayerInventoryStackState $cursor = null,
    ) {
        if ($this->selectedHotbarSlot < 0 || $this->selectedHotbarSlot >= PlayerInventory::HOTBAR_SIZE) {
            throw new InvalidArgumentException('Selected slot is outside the hotbar.');
        }
        $bySlot = [];
        foreach ($entries as $entry) {
            if (isset($bySlot[$entry->slot])) {
                throw new InvalidArgumentException('Inventory state contains a duplicate slot.');
            }
            $bySlot[$entry->slot] = $entry;
        }
        ksort($bySlot, SORT_NUMERIC);
        $this->entries = array_values($bySlot);
    }
}
