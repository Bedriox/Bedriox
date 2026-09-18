<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

final readonly class PlayerSnapshot
{
    public function __construct(
        public string $sessionId,
        public string $identity,
        public string $displayName,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public MovementMode $movementMode,
        public int $movementSequence,
        public VerticalState $verticalState,
        public float $verticalVelocity,
        public int $runtimeActorId = 0,
        public float $headYaw = 0.0,
        public bool $sneaking = false,
        public bool $sprinting = false,
    ) {}
}
