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

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use Bedriox\Api\Scoreboard\ScoreboardSortOrder;
use Bedriox\Api\Scoreboard\Sidebar;

/** Cancellable intent emitted before an online player's scoreboard display changes. */
final class PlayerScoreboardDisplayEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ScoreboardDisplaySlot $slot,
        public readonly Sidebar|ScoreboardObjective|null $previous,
        public readonly Sidebar|ScoreboardObjective|null $display,
        public readonly ScoreboardSortOrder $sortOrder,
    ) {}
}
