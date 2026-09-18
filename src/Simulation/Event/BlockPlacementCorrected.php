<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;

final readonly class BlockPlacementCorrected implements WorldEvent
{
    public function __construct(
        public string $ownerSessionId,
        public BlockPosition $clickedPosition,
        public InternalBlockStateId $clickedState,
        public BlockPosition $placedPosition,
        public InternalBlockStateId $placedState,
        public int $inventorySlot,
        public ?InventoryStack $heldStack,
        public ?BlockPosition $stoppedBreakingPosition = null,
        public string $reason = 'unspecified',
    ) {}

    public function recipients(): array
    {
        return [$this->ownerSessionId];
    }
}
