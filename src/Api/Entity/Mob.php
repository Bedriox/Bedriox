<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

interface Mob extends LivingEntity
{
    public function getActivationState(): MobActivationState;
}
