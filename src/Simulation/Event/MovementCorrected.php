<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class MovementCorrected implements WorldEvent
{
    /** @param list<string> $peerSessionIds */
    public function __construct(
        public PlayerSnapshot $authoritativePlayer,
        public string $reason,
        public array $peerSessionIds = [],
        public bool $postureChanged = false,
    ) {}

    public function recipients(): array
    {
        return array_values(array_unique([
            $this->authoritativePlayer->sessionId,
            ...$this->peerSessionIds,
        ]));
    }
}
