<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerRespawned implements WorldEvent
{
    /**
     * @param list<string> $recipientSessionIds
     * @param list<?InventoryStack> $inventory
     */
    public function __construct(
        public PlayerSnapshot $player,
        public array $recipientSessionIds,
        public array $inventory,
        public int $selectedHotbarSlot,
        public ?InventoryStack $selectedStack,
    ) {}
    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
