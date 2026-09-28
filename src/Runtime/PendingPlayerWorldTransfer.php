<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Server\Simulation\PlayerTeleportDecision;
use Bedriox\Server\Simulation\Position;

/** @internal Main-thread ownership ticket for one staged same-dimension transfer. */
final readonly class PendingPlayerWorldTransfer
{
    public function __construct(
        public string $identity,
        public string $sessionId,
        public string $sourceWorldId,
        public string $targetWorldId,
        public PlayerTeleportDecision $decision,
        public Position $from,
        public float $fromYaw,
        public float $fromPitch,
        public int $startedNanoseconds,
    ) {}
}
