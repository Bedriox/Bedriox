<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

final readonly class PlayerTeleportDecision
{
    public function __construct(
        public Position $destination,
        public float $yaw,
        public float $pitch,
    ) {}
}
