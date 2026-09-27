<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Collision;

use Bedriox\Server\Simulation\Position;

final readonly class PlayerCollisionResult
{
    public function __construct(
        public Position $position,
        public bool $collidedX,
        public bool $collidedY,
        public bool $collidedZ,
        public bool $stepped,
        public bool $grounded,
        public bool $fastPath = false,
        public int $obstacleCount = 0,
        public bool $terrainLoaded = true,
    ) {}

    public function collided(): bool
    {
        return $this->collidedX || $this->collidedY || $this->collidedZ;
    }
}
