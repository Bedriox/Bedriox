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

namespace Bedriox\Server\Plugin\Scoreboard;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\SidebarView;

/** @internal Mutable state owned exclusively by ScoreboardRegistry. */
final class SidebarViewState
{
    /** @var array<string, string|null> Null values hide shared lines. */
    public array $lineOverrides = [];
    public ?string $titleOverride = null;
    public ?SidebarView $handle = null;
    public bool $showing = false;
    public bool $removed = false;
    public int $revision = 0;

    public function __construct(public Player $player) {}
}
