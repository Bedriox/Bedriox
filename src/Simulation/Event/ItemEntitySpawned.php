<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\Item\DroppedItemEntity;

final readonly class ItemEntitySpawned implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(public DroppedItemEntity $entity, public array $recipientSessionIds) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
