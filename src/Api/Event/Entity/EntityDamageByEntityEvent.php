<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

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
