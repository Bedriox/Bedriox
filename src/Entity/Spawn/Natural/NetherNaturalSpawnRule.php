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

use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\World\WorldDimension;

final readonly class NetherNaturalSpawnRule implements NaturalSpawnRule
{
    public function __construct(private VanillaEntityType $type) {}

    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->dimension !== WorldDimension::NETHER) {
            return false;
        }
        if ($this->type === VanillaEntityType::STRIDER) {
            return $context->medium === NaturalSpawnMedium::LAVA;
        }
        if ($this->type === VanillaEntityType::GHAST) {
            return $context->medium === NaturalSpawnMedium::AIR
                && in_array($context->biome, ['minecraft:hell', 'minecraft:soulsand_valley', 'minecraft:basalt_deltas'], true);
        }
        if ($context->medium !== NaturalSpawnMedium::GROUND) {
            return false;
        }

        return match ($this->type) {
            VanillaEntityType::BLAZE => $context->netherStructure === NetherStructureType::FORTRESS,
            VanillaEntityType::PIGLIN_BRUTE => false,
            VanillaEntityType::HOGLIN => $context->biome === 'minecraft:crimson_forest',
            VanillaEntityType::PIGLIN => in_array($context->biome, ['minecraft:crimson_forest', 'minecraft:hell'], true),
            VanillaEntityType::ZOMBIFIED_PIGLIN => $context->biome === 'minecraft:hell',
            VanillaEntityType::MAGMA_CUBE => in_array($context->biome, ['minecraft:hell', 'minecraft:basalt_deltas'], true),
            default => false,
        };
    }
}
