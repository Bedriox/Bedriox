<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;

final readonly class BlockPlaced implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public int $runtimeActorId,
        public BlockPosition $position,
        public InternalBlockStateId $state,
        public int $inventorySlot,
        public ?InventoryStack $remainingStack,
        public array $recipientSessionIds,
        public ?BlockPosition $stoppedBreakingPosition = null,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
