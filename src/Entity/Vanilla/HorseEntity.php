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

use Bedriox\Api\Entity\Vanilla\Horse;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Mount\MountEntityDefinitions;
use Bedriox\Server\Entity\Mount\PersistentHorseFamilyEntity;
use Bedriox\Server\Simulation\Position;

final class HorseEntity extends PersistentHorseFamilyEntity implements Horse
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
        bool $baby = false,
        int $loveTicks = 0,
        int $babyGrowthTicks = 0,
        int $breedingCooldownTicks = 0,
        ?string $ownerUniqueId = null,
        bool $saddled = false,
        int $temper = 0,
        bool $sitting = false,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            MountEntityDefinitions::horse(),
            $worldName,
            $position,
            LandAnimalAiBehaviors::passive('horse', ['minecraft:wheat', 'minecraft:golden_carrot', 'minecraft:golden_apple'], 0.12),
            $motion,
            $yaw,
            $pitch,
            $health,
            $baby,
            $loveTicks,
            $babyGrowthTicks,
            $breedingCooldownTicks,
            $ownerUniqueId,
            $saddled,
            $temper,
            $sitting,
            1,
            1.15,
        );
    }
}
