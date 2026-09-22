<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;

final readonly class ItemEntityPickedUp implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public int $itemRuntimeActorId,
        public int $collectorRuntimeActorId,
        public InventoryStack $stack,
        public string $collectorSessionId,
        public bool $removed,
        /** @var list<InventoryStack|null> */
        public array $mainInventory,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
