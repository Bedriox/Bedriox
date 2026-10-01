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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\Vanilla\SkeletonHorse;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Mount\MountEntityDefinitions;
use Bedriox\Server\Entity\Mount\PersistentUndeadHorseEntity;
use Bedriox\Server\Simulation\Position;

final class SkeletonHorseEntity extends PersistentUndeadHorseEntity implements SkeletonHorse
{
    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        ?string $ownerUniqueId = null,
        bool $saddled = false,
        int $temper = 0,
        bool $sitting = false,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            MountEntityDefinitions::skeletonHorse(),
            $worldName,
            $position,
            LandAnimalAiBehaviors::wandering('skeleton_horse', 0.12),
            $motion,
            $yaw,
            $pitch,
            $health,
            $ownerUniqueId,
            $saddled,
            $temper,
            $sitting,
            1,
            1.15,
            true,
        );
    }
}
