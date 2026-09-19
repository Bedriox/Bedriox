<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;

/** Mutable movement state owned exclusively by the authoritative world tick. */
final class PlayerMovement
{
    public float $yaw = 0.0;
    public float $headYaw = 0.0;
    public float $pitch = 0.0;
    public MovementMode $mode = MovementMode::STOPPED;
    public bool $sneaking = false;
    public bool $sprinting = false;
    public int $sequence = -1;
    public float $verticalVelocity = 0.0;
    public float $distanceThisTick = 0.0;
    public int $jumpAuthorizedUntilTick = -1;
    public float $fallDistance = 0.0;

    public function __construct(
        public Position $position,
        public VerticalState $verticalState,
        public int $lastTick,
        public int $budgetTick,
    ) {}
}
