<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\PlayerSnapshot;

/** Authoritative velocity/posture reconciliation not caused by client movement. */
final readonly class PlayerMotionChanged implements WorldEvent
{
    /** @param list<string> $recipientSessionIds */
    public function __construct(
        public string $ownerSessionId,
        public PlayerSnapshot $player,
        public float $motionX,
        public float $motionY,
        public float $motionZ,
        public ClientInputTick $clientTick,
        public bool $postureChanged,
        public array $recipientSessionIds,
    ) {}

    public function recipients(): array
    {
        return $this->recipientSessionIds;
    }
}
