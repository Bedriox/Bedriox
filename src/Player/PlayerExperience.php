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

namespace Bedriox\Server\Player;

use Bedriox\Api\Player\ExperienceSnapshot;
use Closure;

/** Mutable experience state owned exclusively by the authoritative player aggregate. */
final class PlayerExperience
{
    /** @param Closure(): void $changed */
    public function __construct(private int $totalPoints, private readonly Closure $changed)
    {
        new ExperienceSnapshot($totalPoints);
    }

    public function totalPoints(): int
    {
        return $this->totalPoints;
    }
    public function snapshot(): ExperienceSnapshot
    {
        return new ExperienceSnapshot($this->totalPoints);
    }

    public function setTotalPoints(int $points): int
    {
        new ExperienceSnapshot($points);
        $previous = $this->totalPoints;
        if ($previous !== $points) {
            $this->totalPoints = $points;
            ($this->changed)();
        }
        return $previous;
    }
}
