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

use Bedriox\Api\Player\Player;
use Closure;

/** A lightweight player-specific projection of one shared sidebar. */
final readonly class SidebarView
{
    /**
     * @param Closure(): Player $player
     * @param Closure(): array{title: string, lines: array<string, string>, showing: bool, removed: bool} $state
     * @param Closure(string, mixed): void $mutate
     * @internal The server owns view construction.
     */
    public function __construct(
        private Sidebar $sidebar,
        private Closure $player,
        private Closure $state,
        private Closure $mutate,
    ) {}

    public function getSidebar(): Sidebar
    {
        return $this->sidebar;
    }

    public function getPlayer(): Player
    {
        return ($this->player)();
    }

    public function getTitle(): string
    {
        return ($this->state)()['title'];
    }

    public function setTitle(string $title): void
    {
        Sidebar::validateText($title, 'Sidebar view title');
        ($this->mutate)('title', $title);
    }

    public function resetTitle(): void
    {
        ($this->mutate)('reset-title', null);
    }

    /** @return array<string, string> Effective lines in display order. */
    public function getLines(): array
    {
        return ($this->state)()['lines'];
    }

    public function getLine(string $key): ?string
    {
        Sidebar::validateLineKey($key);

        return $this->getLines()[$key] ?? null;
    }

    /** Overrides an existing shared line or appends a player-only line. */
    public function setLine(string $key, string $text): void
    {
        Sidebar::validateLineKey($key);
        Sidebar::validateText($text, 'Sidebar view line');
        ($this->mutate)('set-line', [$key, $text]);
    }

    /** Hides a line in this view without changing the shared sidebar. */
    public function removeLine(string $key): void
    {
        Sidebar::validateLineKey($key);
        ($this->mutate)('remove-line', $key);
    }

    /** Removes this player's override and restores the shared line, if one exists. */
    public function resetLine(string $key): void
    {
        Sidebar::validateLineKey($key);
        ($this->mutate)('reset-line', $key);
    }

    public function resetOverrides(): void
    {
        ($this->mutate)('reset-overrides', null);
    }

    public function show(): void
    {
        ($this->mutate)('showing', true);
    }

    public function hide(): void
    {
        ($this->mutate)('showing', false);
    }

    public function isShowing(): bool
    {
        return ($this->state)()['showing'];
    }

    public function isRemoved(): bool
    {
        return ($this->state)()['removed'];
    }
}
