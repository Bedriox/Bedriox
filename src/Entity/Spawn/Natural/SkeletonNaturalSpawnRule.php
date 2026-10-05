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

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\World\WorldDimension;

/** Ground admission for darkness-spawned overworld skeletons. */
final readonly class SkeletonNaturalSpawnRule implements NaturalSpawnRule
{
    public function allows(NaturalSpawnContext $context): bool
    {
        $eligibleDimension = $context->dimension === WorldDimension::OVERWORLD
            || ($context->dimension === WorldDimension::NETHER && $context->biome === 'minecraft:soulsand_valley');

        return $eligibleDimension
            && $context->medium === NaturalSpawnMedium::GROUND
            && $context->lightLevel <= 7;
    }
}
