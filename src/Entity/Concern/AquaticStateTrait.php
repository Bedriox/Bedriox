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

namespace Bedriox\Server\Entity\Concern;

use Bedriox\Server\Entity\AquaticState;

trait AquaticStateTrait
{
    private AquaticState $aquaticState;

    final public function canBreatheUnderwater(): bool
    {
        return $this->aquaticState->breathesUnderwater();
    }
    final public function requiresWater(): bool
    {
        return $this->aquaticState->waterDependent();
    }
    final public function getAirSupplyTicks(): int
    {
        return $this->aquaticState->airSupplyTicks();
    }
    final public function getMaximumAirSupplyTicks(): int
    {
        return $this->aquaticState->maximumAirSupplyTicks();
    }
    final public function getDryTicks(): int
    {
        return $this->aquaticState->dryTicks();
    }
    final public function canNavigateOnLand(): bool
    {
        return $this->aquaticState->canNavigateOnLand();
    }
    final public function advanceAquaticState(bool $submerged): void
    {
        $this->aquaticState->advance($submerged);
    }

    final protected function initializeAquaticState(
        bool $breathesUnderwater,
        bool $waterDependent,
        int $maximumAirSupplyTicks = 300,
        bool $canNavigateOnLand = false,
    ): void {
        $this->aquaticState = new AquaticState(
            $breathesUnderwater,
            $waterDependent,
            $maximumAirSupplyTicks,
            $canNavigateOnLand,
        );
    }
}
