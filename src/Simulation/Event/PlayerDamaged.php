<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerDamaged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public PlayerSnapshot $player,
        public float $damage,
        public DamageCause $cause,
        public array $recipientSessionIds,
        public bool $equipmentChanged = false,
    ) {}
    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
