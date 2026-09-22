<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

final readonly class BlockPunch implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public BlockPosition $position,
        public InternalBlockStateId $state,
        public int $face,
        public array $recipientSessionIds,
    ) {
        if ($face < 0 || $face > 5) {
            throw new InvalidArgumentException('Block face is outside the supported range.');
        }
    }

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
