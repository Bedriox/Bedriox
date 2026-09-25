<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\World\BlockEntity\BlockEntity;

/** Publishes the current client-visible state of one authoritative block entity. */
final readonly class BlockEntityChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public BlockEntity $blockEntity,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
