<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

final readonly class CustomMobDespawnContext
{
    public function __construct(public Mob $mob) {}
}
