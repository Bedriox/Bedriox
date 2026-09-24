<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Simulation\PlayerSnapshot;

/** Authoritative completion of a plugin-defined instant main-hand use. */
final readonly class InstantItemUsed implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public PlayerSnapshot $player,
        public InventoryStack $stack,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
