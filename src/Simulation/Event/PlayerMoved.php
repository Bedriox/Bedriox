<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerMoved implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public PlayerSnapshot $player,
        public array $recipientSessionIds,
        public bool $postureChanged = false,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
