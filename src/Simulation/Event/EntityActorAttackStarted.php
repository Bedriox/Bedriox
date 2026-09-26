<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractMobEntity;

/** Visible authoritative melee-attack state for one non-player actor. */
final readonly class EntityActorAttackStarted implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public AbstractMobEntity $entity,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
