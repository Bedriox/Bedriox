<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

final readonly class CustomMobSpawnContext
{
    public function __construct(
        public Mob $mob,
        public SpawnCause $cause,
    ) {}
}
