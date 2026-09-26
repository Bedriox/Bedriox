<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

interface LivingEntityController extends EntityController
{
    public function damage(
        float $amount,
        EntityDamageCause $cause = EntityDamageCause::PLUGIN,
        ?Entity $source = null,
    ): void;

    public function heal(float $amount): void;

    public function setHealth(float $health): void;

    public function equipment(): EntityEquipment;
}
