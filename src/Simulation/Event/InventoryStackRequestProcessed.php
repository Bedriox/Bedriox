<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;

final readonly class InventoryStackRequestProcessed implements WorldEvent
{
    /**
     * @param list<InventorySlotReference> $affectedSlots
     * @param list<InventoryStack|null> $mainInventory
     * @param list<InventoryStack|null> $armorInventory
     * @param list<InventoryStack|null> $craftingInventory
     * @param list<string> $peerSessionIds
     */
    public function __construct(
        public string $ownerSessionId,
        public int $requestId,
        public bool $success,
        public array $affectedSlots,
        public array $mainInventory,
        public ?InventoryStack $cursorStack,
        public int $selectedHotbarSlot,
        public ?InventoryStack $selectedStack,
        public bool $selectedStackChanged,
        public int $runtimeActorId,
        public array $peerSessionIds,
        public string $reason = '',
        public InventoryResponseMode $responseMode = InventoryResponseMode::ItemStackResponse,
        public bool $fullSync = false,
        public array $armorInventory = [],
        public ?InventoryStack $offhandStack = null,
        public array $craftingInventory = [],
    ) {}

    public function recipients(): array
    {
        return array_values(array_unique([$this->ownerSessionId, ...$this->peerSessionIds]));
    }
}
