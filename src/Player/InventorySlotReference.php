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
    ) {}

    public function key(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::CreatedOutput => 'created_output:',
        } . $this->slot;
    }

    public function responseKey(): string
    {
        return match ($this->container) {
            InventoryContainer::Main => 'main:',
            InventoryContainer::Cursor => 'cursor:',
            InventoryContainer::CreatedOutput => 'created_output:',
        }
        . ($this->responseContainerId ?? -1) . ':' . $this->slot;
    }
}
