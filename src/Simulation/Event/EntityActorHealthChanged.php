<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractLivingEntity;

/** Internal projection emitted when living-entity health changes without a hurt animation. */
final readonly class EntityActorHealthChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public AbstractLivingEntity $entity,
        public int $tick,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
