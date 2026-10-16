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

namespace Bedriox\Api\Event\Scoreboard;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use InvalidArgumentException;

/** Cancellable, adjustable intent emitted before an objective score changes. */
final class ScoreboardScoreChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly ScoreboardObjective $objective,
        public readonly Player|Entity|string $holder,
        public readonly ?int $previousScore,
        private ?int $score,
    ) {
        self::validateScore($score);
    }

    public function score(): ?int
    {
        return $this->score;
    }

    public function setScore(?int $score): void
    {
        $this->assertMutable();
        self::validateScore($score);
        $this->score = $score;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->score];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0])
            || ($state[1] !== null && !is_int($state[1]))) {
            throw new InvalidArgumentException('Invalid scoreboard-score event state.');
        }
        parent::replaceState($state[0]);
        $this->score = $state[1];
    }

    private static function validateScore(?int $score): void
    {
        if ($score !== null && ($score < ScoreboardObjective::MINIMUM_SCORE
            || $score > ScoreboardObjective::MAXIMUM_SCORE)) {
            throw new InvalidArgumentException('Scores must fit the signed 32-bit Bedrock wire range.');
        }
    }
}
