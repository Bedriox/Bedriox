<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\ArmSwingSource;

final readonly class ArmSwung implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public int $runtimeActorId,
        public ArmSwingSource $source,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
