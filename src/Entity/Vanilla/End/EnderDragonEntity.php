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

namespace Bedriox\Server\Entity\Vanilla\End;

use Bedriox\Api\Entity\Vanilla\EnderDragon;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Gameplay\End\EnderDragonPart;
use Bedriox\Server\Simulation\Position;

final class EnderDragonEntity extends MonsterEntity implements EnderDragon
{
    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::enderDragon(), $worldName, $position, new AiBehaviorDefinition(), $motion, $yaw, $pitch, $health);
        $this->setGravityEnabled(false);
    }

    /** @internal Damage admission remains owned by the authoritative simulation. */
    public function damagePart(EnderDragonPart $part, float $damage): float
    {
        return $this->damage($damage * $part->damageMultiplier());
    }
}
