<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Player\Player;
use Bedriox\Server\Simulation\Event\WorldEvent;

/** Authoritative player aggregate and bounded source-world projection work. */
final readonly class PlayerTransferDeparture
{
    /**
     * @param list<PlayerSnapshot> $previousPeers
     * @param list<WorldEvent>     $events
     */
    public function __construct(
        public Player $player,
        public array $previousPeers,
        public array $events,
    ) {}
}
