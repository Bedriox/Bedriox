<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractEntity;

/** Internal authoritative movement projection for one non-player actor. */
final readonly class EntityActorMoved implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public AbstractEntity $entity,
        public int $tick,
        public array $recipientSessionIds,
        public bool $motionChanged = true,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
