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
        public ?int $responseContainerDynamicId = null,
    ) {}

    public function key(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::Armor => 'armor:',
            InventoryContainer::Offhand => 'offhand:',
            InventoryContainer::CraftingInput => 'crafting_input:',
            InventoryContainer::CreatedOutput => 'created_output:',
            InventoryContainer::OpenedContainer => 'opened_container:',
        } . $this->slot;
    }

    public function responseKey(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::Armor => 'armor:',
            InventoryContainer::Offhand => 'offhand:',
            InventoryContainer::CraftingInput => 'crafting_input:',
            InventoryContainer::CreatedOutput => 'created_output:',
            InventoryContainer::OpenedContainer => 'opened_container:',
        }
        . ($this->responseContainerId ?? -1) . ':' . ($this->responseContainerDynamicId ?? -1)
        . ':' . $this->responseSlotId();
    }

    public function responseSlotId(): int
    {
        return $this->responseSlot ?? $this->slot;
    }
}
