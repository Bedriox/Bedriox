<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

final readonly class InventorySlotReference
{
    public function __construct(
        public InventoryContainer $container,
        public int $slot,
        public int $expectedStackNetworkId,
        public ?int $responseContainerId = null,
        public ?int $expectedCount = null,
        public ?int $responseSlot = null,
    ) {}

    public function key(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::Armor => 'armor:',
            InventoryContainer::Offhand => 'offhand:',
            InventoryContainer::CreatedOutput => 'created_output:',
        } . $this->slot;
    }

    public function responseKey(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::Armor => 'armor:',
            InventoryContainer::Offhand => 'offhand:',
            InventoryContainer::CreatedOutput => 'created_output:',
        }
        . ($this->responseContainerId ?? -1) . ':' . $this->responseSlotId();
    }

    public function responseSlotId(): int
    {
        return $this->responseSlot ?? $this->slot;
    }
}
