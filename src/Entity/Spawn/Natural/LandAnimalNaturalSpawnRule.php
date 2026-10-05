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
use InvalidArgumentException;

/** Bounded biome-family rule for expanded overworld land animals. */
final readonly class LandAnimalNaturalSpawnRule implements NaturalSpawnRule
{
    /**
     * @param list<string> $includedBiomeFamilies
     * @param list<string> $excludedBiomeFamilies
     */
    public function __construct(
        private array $includedBiomeFamilies = [],
        private array $excludedBiomeFamilies = ['ocean', 'river'],
        private int $minimumLight = 9,
    ) {
        if (count($includedBiomeFamilies) > 16
            || count($excludedBiomeFamilies) > 16
            || $minimumLight < 0 || $minimumLight > 15) {
            throw new InvalidArgumentException('Land-animal natural-spawn rule is outside its supported bounds.');
        }
        foreach ([...$includedBiomeFamilies, ...$excludedBiomeFamilies] as $family) {
            if ($family === '' || strlen($family) > 64
                || preg_match('/^[a-z0-9_.-]+$/D', $family) !== 1) {
                throw new InvalidArgumentException('Land-animal biome family is invalid.');
            }
        }
    }

    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->dimension !== WorldDimension::OVERWORLD
            || $context->medium !== NaturalSpawnMedium::GROUND
            || $context->lightLevel < $this->minimumLight) {
            return false;
        }
        foreach ($this->excludedBiomeFamilies as $family) {
            if (NaturalSpawnBiomes::matchesFamily($context->biome, $family)) {
                return false;
            }
        }
        if ($this->includedBiomeFamilies === []) {
            return true;
        }
        foreach ($this->includedBiomeFamilies as $family) {
            if (NaturalSpawnBiomes::matchesFamily($context->biome, $family)) {
                return true;
            }
        }

        return false;
    }
}
