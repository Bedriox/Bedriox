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

use InvalidArgumentException;

/** Bounded biome-family rule for expanded overworld land animals. */
final readonly class LandAnimalNaturalSpawnRule implements NaturalSpawnRule
{
    /**
     * @param list<string> $includedBiomeFragments
     * @param list<string> $excludedBiomeFragments
     */
    public function __construct(
        private array $includedBiomeFragments = [],
        private array $excludedBiomeFragments = ['ocean', 'river'],
        private int $minimumLight = 9,
    ) {
        if (count($includedBiomeFragments) > 16
            || count($excludedBiomeFragments) > 16
            || $minimumLight < 0 || $minimumLight > 15) {
            throw new InvalidArgumentException('Land-animal natural-spawn rule is outside its supported bounds.');
        }
        foreach ([...$includedBiomeFragments, ...$excludedBiomeFragments] as $fragment) {
            if ($fragment === '' || strlen($fragment) > 64
                || preg_match('/^[a-z0-9_.-]+$/D', $fragment) !== 1) {
                throw new InvalidArgumentException('Land-animal biome fragment is invalid.');
            }
        }
    }

    public function allows(NaturalSpawnContext $context): bool
    {
        if ($context->medium !== NaturalSpawnMedium::GROUND || $context->lightLevel < $this->minimumLight) {
            return false;
        }
        foreach ($this->excludedBiomeFragments as $fragment) {
            if (str_contains($context->biome, $fragment)) {
                return false;
            }
        }
        if ($this->includedBiomeFragments === []) {
            return true;
        }
        foreach ($this->includedBiomeFragments as $fragment) {
            if (str_contains($context->biome, $fragment)) {
                return true;
            }
        }

        return false;
    }
}
