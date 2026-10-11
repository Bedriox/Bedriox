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

namespace Bedriox\Server\Entity\Human;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\Skin;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\World;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Simulation\Position;

/** Canonical construction boundary for sessionless player-shaped entities. */
final class HumanEntityFactory
{
    public function create(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        string $name,
        Skin $skin,
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?World $world = null,
    ): HumanEntity {
        $world ??= new World($worldName, 1);
        $human = new HumanEntity(
            $uniqueId,
            $runtimeId,
            self::definition(),
            $worldName,
            $position,
            $name,
            $skin,
            $world,
            yaw: $yaw,
            pitch: $pitch,
        );
        $human->restoreSpawnOwnership(SpawnCause::PLUGIN, EntityDespawnPolicy::EXPLICIT_ONLY);

        return $human;
    }

    public static function definition(): EntityDefinition
    {
        return new EntityDefinition(
            VanillaEntityType::PLAYER,
            EntityCategory::MISCELLANEOUS,
            VanillaEntityType::PLAYER->value,
            0.6,
            1.8,
            20.0,
            persistent: false,
        );
    }
}
