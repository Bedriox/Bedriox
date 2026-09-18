<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

/** Runtime-derived actor visibility transition; player-list membership is unchanged. */
final readonly class PlayerBecameVisible implements WorldEvent
{
    public function __construct(public PlayerSnapshot $player, public string $recipientSessionId) {}

    public function recipients(): array
    {
        return [$this->recipientSessionId];
    }
}
