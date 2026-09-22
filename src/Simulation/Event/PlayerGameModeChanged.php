<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Simulation\PlayerSnapshot;

/** Internal authoritative commit projected to the owning client and observers. */
final readonly class PlayerGameModeChanged implements WorldEvent
{
    public function __construct(
        public PlayerSnapshot $player,
        public GameMode $previous,
        public GameMode $gameMode,
        public int $tick = 0,
        /** @var list<string> */
        public array $recipientSessionIds = [],
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
