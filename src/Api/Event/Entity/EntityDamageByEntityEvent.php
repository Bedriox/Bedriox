<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Player\Player;

final class EntityDamageByEntityEvent extends EntityDamageEvent
{
    public function __construct(
        public readonly Entity|Player $damager,
        LivingEntity $entity,
        EntityDamageCause $cause,
        float $damage,
    ) {
        parent::__construct($entity, $cause, $damage);
    }
}
