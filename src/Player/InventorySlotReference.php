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
        return ($this->container === InventoryContainer::Main ? 'main:' : 'cursor:') . $this->slot;
    }

    public function responseKey(): string
    {
        return ($this->container === InventoryContainer::Main ? 'main:' : 'cursor:')
            . ($this->responseContainerId ?? -1) . ':' . $this->slot;
    }
}
