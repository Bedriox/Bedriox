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

namespace Bedriox\Api\BossBar;

use Bedriox\Api\Player\Player;
use Closure;
use InvalidArgumentException;

/** A mutable plugin-owned boss bar. */
final readonly class BossBar
{
    public const int MAXIMUM_TITLE_BYTES = 1_024;

    /**
     * @param Closure(): array{title: string, progress: float, color: BossBarColor, style: BossBarStyle, viewers: list<Player>, visible: bool, removed: bool} $state
     * @param Closure(string, mixed): void $mutate
     * @param Closure(Player): void $addViewer
     * @param Closure(Player): void $removeViewer
     * @param Closure(): void $remove
     * @internal The server owns boss-bar construction.
     */
    public function __construct(
        private string $id,
        private Closure $state,
        private Closure $mutate,
        private Closure $addViewer,
        private Closure $removeViewer,
        private Closure $remove,
    ) {}

    public function getId(): string
    {
        return $this->id;
    }

    public function getTitle(): string
    {
        return ($this->state)()['title'];
    }

    public function setTitle(string $title): void
    {
        self::validateTitle($title);
        ($this->mutate)('title', $title);
    }

    public function getProgress(): float
    {
        return ($this->state)()['progress'];
    }

    public function setProgress(float $progress): void
    {
        if (!is_finite($progress) || $progress < 0.0 || $progress > 1.0) {
            throw new InvalidArgumentException('Boss-bar progress must be finite and between 0.0 and 1.0.');
        }
        ($this->mutate)('progress', $progress);
    }

    public function getColor(): BossBarColor
    {
        return ($this->state)()['color'];
    }

    public function setColor(BossBarColor $color): void
    {
        ($this->mutate)('color', $color);
    }

    public function getStyle(): BossBarStyle
    {
        return ($this->state)()['style'];
    }

    public function setStyle(BossBarStyle $style): void
    {
        ($this->mutate)('style', $style);
    }

    /** @return list<Player> */
    public function getViewers(): array
    {
        return ($this->state)()['viewers'];
    }

    public function addViewer(Player $player): void
    {
        ($this->addViewer)($player);
    }

    public function removeViewer(Player $player): void
    {
        ($this->removeViewer)($player);
    }

    public function removeAllViewers(): void
    {
        foreach ($this->getViewers() as $viewer) {
            ($this->removeViewer)($viewer);
        }
    }

    public function isVisible(): bool
    {
        return ($this->state)()['visible'];
    }

    public function setVisible(bool $visible): void
    {
        ($this->mutate)('visible', $visible);
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }

    public function remove(): void
    {
        ($this->remove)();
    }

    public static function validateTitle(string $title): void
    {
        if (strlen($title) > self::MAXIMUM_TITLE_BYTES || preg_match('//u', $title) !== 1) {
            throw new InvalidArgumentException('Boss-bar title must be bounded UTF-8.');
        }
    }
}
