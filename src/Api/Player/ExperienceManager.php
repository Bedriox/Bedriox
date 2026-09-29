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

use Closure;
use LogicException;

/** Public bounded mutation surface for a player's authoritative experience. */
final readonly class ExperienceManager
{
    /** @param Closure(int, ExperienceChangeCause): void $setTotalPoints */
    public function __construct(
        private ExperienceSnapshot $snapshot,
        private Closure $setTotalPoints,
    ) {}

    public static function unavailable(ExperienceSnapshot $snapshot): self
    {
        return new self($snapshot, static function (): never {
            throw new LogicException('This experience snapshot is not attached to an authoritative runtime.');
        });
    }

    public function getSnapshot(): ExperienceSnapshot
    {
        return $this->snapshot;
    }
    public function getTotalPoints(): int
    {
        return $this->snapshot->totalPoints;
    }
    public function getLevel(): int
    {
        return $this->snapshot->level;
    }
    public function getProgress(): float
    {
        return $this->snapshot->progress;
    }

    public function setTotalPoints(int $points, ExperienceChangeCause $cause = ExperienceChangeCause::PLUGIN): void
    {
        new ExperienceSnapshot($points);
        ($this->setTotalPoints)($points, $cause);
    }

    public function addPoints(int $points, ExperienceChangeCause $cause = ExperienceChangeCause::PLUGIN): void
    {
        if ($points < 0) {
            throw new \InvalidArgumentException('Added experience points must not be negative.');
        }
        $this->setTotalPoints(min(\Bedriox\Server\Player\ExperienceMath::MAXIMUM_TOTAL_POINTS, $this->snapshot->totalPoints + $points), $cause);
    }

    public function removePoints(int $points, ExperienceChangeCause $cause = ExperienceChangeCause::PLUGIN): void
    {
        if ($points < 0) {
            throw new \InvalidArgumentException('Removed experience points must not be negative.');
        }
        $this->setTotalPoints(max(0, $this->snapshot->totalPoints - $points), $cause);
    }
}
