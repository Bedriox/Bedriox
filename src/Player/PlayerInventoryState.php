<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Immutable, session-independent inventory snapshot using canonical item identities. */
final readonly class PlayerInventoryState
{
    /** @var list<PlayerInventoryEntry> */
    public array $entries;

    /** @var list<PlayerInventoryEntry> */
    public array $armor;

    /**
     * @param list<PlayerInventoryEntry> $entries
     * @param list<PlayerInventoryEntry> $armor
     */
    public function __construct(
        array $entries,
        public int $selectedHotbarSlot,
        public ?PlayerInventoryStackState $cursor = null,
        array $armor = [],
        public ?PlayerInventoryStackState $offhand = null,
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

        $armorBySlot = [];
        foreach ($armor as $entry) {
            if ($entry->slot < 0 || $entry->slot >= PlayerInventory::ARMOR_SLOT_COUNT
                || isset($armorBySlot[$entry->slot])) {
                throw new InvalidArgumentException('Armor state contains an invalid or duplicate slot.');
            }
            $armorBySlot[$entry->slot] = $entry;
        }
        ksort($armorBySlot, SORT_NUMERIC);
        $this->armor = array_values($armorBySlot);
    }
}
