<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractLivingEntity;

/** Internal visibility projection for one non-player living actor. */
final readonly class EntityActorSpawned implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public AbstractLivingEntity $entity,
        public array $recipientSessionIds,
        public bool $noAi = false,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
