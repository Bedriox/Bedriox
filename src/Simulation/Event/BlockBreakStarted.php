<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\World\BlockPosition;

final readonly class BlockBreakStarted implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public BlockPosition $position,
        public int $breakRate,
        public array $recipientSessionIds,
        public ?BlockPosition $previousPosition = null,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
