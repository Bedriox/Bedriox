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

namespace Bedriox\Api\Player;

use Bedriox\Server\Player\ExperienceMath;
use InvalidArgumentException;

/** Immutable view derived from one authoritative total-points value. */
final readonly class ExperienceSnapshot
{
    public int $level;
    public float $progress;

    public function __construct(public int $totalPoints)
    {
        if ($totalPoints < 0 || $totalPoints > ExperienceMath::MAXIMUM_TOTAL_POINTS) {
            throw new InvalidArgumentException('Experience points are outside the supported range.');
        }
        $this->level = ExperienceMath::levelFromTotalPoints($totalPoints);
        $this->progress = ExperienceMath::progressFromTotalPoints($totalPoints, $this->level);
    }
}
