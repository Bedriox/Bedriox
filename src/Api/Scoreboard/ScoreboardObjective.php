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

namespace Bedriox\Api\Scoreboard;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

/** A plugin-owned numeric scoreboard objective. */
final readonly class ScoreboardObjective
{
    public const int MAXIMUM_DISPLAY_NAME_BYTES = 1_024;
    public const int MAXIMUM_FAKE_NAME_BYTES = 1_024;
    public const int MINIMUM_SCORE = -2_147_483_648;
    public const int MAXIMUM_SCORE = 2_147_483_647;

    /**
     * @param Closure(): array{displayName: string, removed: bool} $state
     * @param Closure(string): void $setDisplayName
     * @param Closure(Player|Entity|string): ?int $getScore
     * @param Closure(Player|Entity|string, ?int): void $setScore
     * @param Closure(Player, ScoreboardDisplaySlot, ScoreboardSortOrder): void $showTo
     * @param Closure(Player, ScoreboardDisplaySlot): void $hideFrom
     * @param Closure(Player, ScoreboardDisplaySlot): bool $isShowingTo
     * @param Closure(): void $remove
     * @internal The server owns objective construction.
     */
    public function __construct(
        private string $id,
        private Closure $state,
        private Closure $setDisplayName,
        private Closure $getScore,
        private Closure $setScore,
        private Closure $showTo,
        private Closure $hideFrom,
        private Closure $isShowingTo,
        private Closure $remove,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getDisplayName(): string
    {
        return ($this->state)()['displayName'];
    }

    public function setDisplayName(string $displayName): void
    {
        self::validateDisplayName($displayName);
        ($this->setDisplayName)($displayName);
    }

    public function getScore(Player|Entity|string $holder): ?int
    {
        self::validateHolder($holder);

        return ($this->getScore)($holder);
    }

    public function setScore(Player|Entity|string $holder, int $score): void
    {
        self::validateHolder($holder);
        self::validateScore($score);
        ($this->setScore)($holder, $score);
    }

    public function addScore(Player|Entity|string $holder, int $amount): int
    {
        $score = self::checkedAdd($this->getScore($holder) ?? 0, $amount);
        $this->setScore($holder, $score);

        return $score;
    }

    public function subtractScore(Player|Entity|string $holder, int $amount): int
    {
        if ($amount === PHP_INT_MIN) {
            throw new InvalidArgumentException('Score arithmetic exceeds the supported integer range.');
        }

        return $this->addScore($holder, -$amount);
    }

    public function resetScore(Player|Entity|string $holder): bool
    {
        if ($this->getScore($holder) === null) {
            return false;
        }
        ($this->setScore)($holder, null);

        return true;
    }

    public function showTo(
        Player $player,
        ScoreboardDisplaySlot $slot = ScoreboardDisplaySlot::SIDEBAR,
        ScoreboardSortOrder $sortOrder = ScoreboardSortOrder::DESCENDING,
    ): void {
        ($this->showTo)($player, $slot, $sortOrder);
    }

    public function hideFrom(Player $player, ScoreboardDisplaySlot $slot = ScoreboardDisplaySlot::SIDEBAR): void
    {
        ($this->hideFrom)($player, $slot);
    }

    public function isShowingTo(Player $player, ScoreboardDisplaySlot $slot = ScoreboardDisplaySlot::SIDEBAR): bool
    {
        return ($this->isShowingTo)($player, $slot);
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }

    public function remove(): void
    {
        ($this->remove)();
    }

    public static function validateDisplayName(string $displayName): void
    {
        if (strlen($displayName) > self::MAXIMUM_DISPLAY_NAME_BYTES || preg_match('//u', $displayName) !== 1) {
            throw new InvalidArgumentException('Scoreboard display names must be bounded UTF-8.');
        }
    }

    private static function validateHolder(Player|Entity|string $holder): void
    {
        if (is_string($holder) && ($holder === '' || strlen($holder) > self::MAXIMUM_FAKE_NAME_BYTES
            || preg_match('//u', $holder) !== 1)) {
            throw new InvalidArgumentException('Named scoreboard holders must be non-empty bounded UTF-8.');
        }
    }

    private static function checkedAdd(int $left, int $right): int
    {
        if ($right > self::MAXIMUM_SCORE - $left || $right < self::MINIMUM_SCORE - $left) {
            throw new InvalidArgumentException('Scores must fit the signed 32-bit Bedrock wire range.');
        }
        $result = $left + $right;
        self::validateScore($result);

        return $result;
    }

    private static function validateScore(int $score): void
    {
        if ($score < self::MINIMUM_SCORE || $score > self::MAXIMUM_SCORE) {
            throw new InvalidArgumentException('Scores must fit the signed 32-bit Bedrock wire range.');
        }
    }
}
