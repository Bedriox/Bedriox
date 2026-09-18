<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;

final readonly class BlockChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public BlockPosition $position,
        public InternalBlockStateId $state,
        public array $recipientSessionIds,
        public bool $stopBreaking = false,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
