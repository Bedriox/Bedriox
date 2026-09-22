<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\Item\DroppedItemEntity;

final readonly class ItemEntityMoved implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public DroppedItemEntity $entity,
        public int $tick,
        public array $recipientSessionIds,
        public bool $motionChanged = true,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
