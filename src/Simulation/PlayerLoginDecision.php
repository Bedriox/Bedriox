<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

/** Immutable result consumed by the login bootstrap after synchronous plugin dispatch. */
final readonly class PlayerLoginDecision
{
    public function __construct(
        public bool $allowed,
        public Position $destination,
        public float $yaw,
        public float $pitch,
    ) {}
}
