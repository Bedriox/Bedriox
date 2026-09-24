<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class ItemConsumed implements WorldEvent
{
    /**
     * @param list<InventorySlotReference> $affectedSlots
     * @param list<InventoryStack|null> $mainInventory
     * @param list<string> $recipientSessionIds
     */
    public function __construct(
        public PlayerSnapshot $player,
        public InventoryStack $consumedStack,
        public int $hotbarSlot,
        public ?InventoryStack $selectedStack,
        public array $affectedSlots,
        public array $mainInventory,
        public ?InventoryStack $droppedResidue,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
