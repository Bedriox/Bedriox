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

namespace Bedriox\Server\Entity;

use InvalidArgumentException;

final class AquaticState
{
    private int $airSupplyTicks;
    private int $dryTicks = 0;

    public function __construct(
        private readonly bool $breathesUnderwater,
        private readonly bool $waterDependent,
        private readonly int $maximumAirSupplyTicks = 300,
        private readonly bool $canNavigateOnLand = false,
    ) {
        if ($maximumAirSupplyTicks < 1 || $maximumAirSupplyTicks > 72_000) {
            throw new InvalidArgumentException('Aquatic air supply is outside its supported bounds.');
        }
        $this->airSupplyTicks = $maximumAirSupplyTicks;
    }

    public function breathesUnderwater(): bool
    {
        return $this->breathesUnderwater;
    }
    public function waterDependent(): bool
    {
        return $this->waterDependent;
    }
    public function airSupplyTicks(): int
    {
        return $this->airSupplyTicks;
    }
    public function maximumAirSupplyTicks(): int
    {
        return $this->maximumAirSupplyTicks;
    }
    public function dryTicks(): int
    {
        return $this->dryTicks;
    }
    public function canNavigateOnLand(): bool
    {
        return $this->canNavigateOnLand;
    }

    public function advance(bool $submerged): void
    {
        if ($submerged) {
            $this->dryTicks = 0;
            $this->airSupplyTicks = $this->breathesUnderwater
                ? $this->maximumAirSupplyTicks
                : max(0, $this->airSupplyTicks - 1);
            return;
        }
        $this->airSupplyTicks = min($this->maximumAirSupplyTicks, $this->airSupplyTicks + 4);
        $this->dryTicks = min(72_000, $this->dryTicks + 1);
    }
}
