<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class RespawnAcknowledged implements WorldEvent
{
    public function __construct(public PlayerSnapshot $player) {}
    public function recipients(): array
    {
        return [$this->player->sessionId];
    }
}
