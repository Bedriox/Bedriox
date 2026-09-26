<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractLivingEntity;

/** Internal projection emitted when authoritative living-entity flags change. */
final readonly class EntityActorMetadataChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public AbstractLivingEntity $entity,
        public int $tick,
        public array $recipientSessionIds,
        public bool $noAi = false,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
