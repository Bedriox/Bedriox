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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\AbstractMobEntity;

/** Player-centered activation keeps near gameplay responsive and distant AI cheap. */
final readonly class MobActivationPolicy
{
    public function classify(AbstractMobEntity $entity, ?float $nearestPlayerDistanceSquared): MobActivationState
    {
        if ($entity->getActivationState() === MobActivationState::FORCED) {
            return MobActivationState::FORCED;
        }
        if ($nearestPlayerDistanceSquared === null) {
            return MobActivationState::SLEEPING;
        }

        [$active, $reduced] = match ($entity->getCategory()) {
            EntityCategory::MONSTER, EntityCategory::FLYING => [48.0, 80.0],
            EntityCategory::VILLAGER => [40.0, 72.0],
            EntityCategory::ANIMAL => [32.0, 64.0],
            EntityCategory::WATER, EntityCategory::AMBIENT => [24.0, 48.0],
            EntityCategory::MISCELLANEOUS => [16.0, 32.0],
        };
        if ($nearestPlayerDistanceSquared <= $active * $active) {
            return MobActivationState::ACTIVE;
        }
        if ($nearestPlayerDistanceSquared <= $reduced * $reduced) {
            return MobActivationState::REDUCED;
        }

        return MobActivationState::SLEEPING;
    }
}
