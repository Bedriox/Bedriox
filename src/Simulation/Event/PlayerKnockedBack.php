<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\PlayerSnapshot;

final readonly class PlayerKnockedBack implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public PlayerSnapshot $player,
        public float $motionX,
        public float $motionY,
        public float $motionZ,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
