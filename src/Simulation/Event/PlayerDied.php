<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerDied implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(public PlayerSnapshot $player, public DamageCause $cause, public array $recipientSessionIds) {}
    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
