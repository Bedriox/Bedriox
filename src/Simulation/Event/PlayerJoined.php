<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerJoined implements WorldEvent
{
    /**
     * @param list<PlayerSnapshot> $existingPeers
     * @param list<string>         $recipientSessionIds
     */
    public function __construct(
        public PlayerSnapshot $player,
        public array $existingPeers,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
