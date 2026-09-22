<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\Position;

final readonly class MovePlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $sequence,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public MovementMode $mode,
        public float $deltaX = 0.0,
        public float $deltaY = 0.0,
        public float $deltaZ = 0.0,
        public bool $jumpRequested = false,
        public ?float $headYaw = null,
        public ?bool $sneaking = null,
        public ?bool $sprinting = null,
        public ClientInputTick $clientTick = new ClientInputTick(0, 0),
        public bool $flying = false,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 96 + strlen($this->session);
    }
}
