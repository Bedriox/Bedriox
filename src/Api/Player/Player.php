<?php

declare(strict_types=1);

namespace Bedriox\Api\Player;

use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\World\Position;

/** An immutable snapshot of a connected player. */
final readonly class Player
{
    public function __construct(
        public string $name,
        public string $uuid,
        public Position $position,
        public float $yaw,
        public float $pitch,
        public bool $sneaking,
        public bool $sprinting,
        public Inventory $inventory,
        public float $health = 20.0,
        public float $maxHealth = 20.0,
        public bool $alive = true,
    ) {}
}
