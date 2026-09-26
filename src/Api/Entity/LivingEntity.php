<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

interface LivingEntity extends Entity
{
    public function getHealth(): float;

    public function getMaximumHealth(): float;

    public function isAlive(): bool;

    public function isOnFire(): bool;

    public function getFireTicks(): int;
}
