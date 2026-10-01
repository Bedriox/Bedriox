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

namespace Bedriox\Api\Entity\Controller;

use Bedriox\Api\Effect\EffectManager;
use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\EntityEquipment;

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

    public function effects(): EffectManager;
}
