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

/** Species-specific water admission; structure-owned aquatic mobs are intentionally excluded. */
final readonly class AquaticSpeciesNaturalSpawnRule implements NaturalSpawnRule
{
    public function __construct(private VanillaEntityType $type) {}

    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->type->identifier() !== $this->type->value
            || $context->dimension !== WorldDimension::OVERWORLD
            || $context->medium !== NaturalSpawnMedium::WATER) {
            return false;
        }

        return match ($this->type) {
            VanillaEntityType::COD => NaturalSpawnBiomes::isOcean($context->biome)
                && !NaturalSpawnBiomes::isWarmOcean($context->biome)
                && !NaturalSpawnBiomes::isFrozenOcean($context->biome),
            VanillaEntityType::SALMON => NaturalSpawnBiomes::isRiver($context->biome)
                || (NaturalSpawnBiomes::isOcean($context->biome)
                    && !NaturalSpawnBiomes::isWarmOcean($context->biome)),
            VanillaEntityType::TROPICAL_FISH, VanillaEntityType::PUFFERFISH =>
                NaturalSpawnBiomes::isWarmOcean($context->biome),
            VanillaEntityType::SQUID => NaturalSpawnBiomes::isOcean($context->biome)
                || NaturalSpawnBiomes::isRiver($context->biome),
            VanillaEntityType::GLOW_SQUID => $context->position->y < 30.0 && $context->lightLevel === 0,
            VanillaEntityType::DOLPHIN => NaturalSpawnBiomes::isOcean($context->biome)
                && !NaturalSpawnBiomes::isFrozenOcean($context->biome),
            VanillaEntityType::AXOLOTL => $context->biome === 'minecraft:lush_caves'
                && $context->position->y < 63.0,
            VanillaEntityType::DROWNED => $context->lightLevel <= 7
                && (NaturalSpawnBiomes::isOcean($context->biome) || NaturalSpawnBiomes::isRiver($context->biome)),
            default => false,
        };
    }
}
