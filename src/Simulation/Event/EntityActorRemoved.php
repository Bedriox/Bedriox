<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Entity\AbstractEntity;

/** Internal visibility teardown for one non-player actor. */
final readonly class EntityActorRemoved implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(public AbstractEntity $entity, public array $recipientSessionIds) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
