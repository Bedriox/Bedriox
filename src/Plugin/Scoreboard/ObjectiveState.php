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
use Bedriox\Api\Scoreboard\ScoreboardDisplaySlot;
use Bedriox\Api\Scoreboard\ScoreboardObjective;
use Bedriox\Api\Scoreboard\ScoreboardSortOrder;

/** @internal Mutable state owned exclusively by ScoreboardRegistry. */
final class ObjectiveState
{
    /** @var array<string, array{holder: ObjectiveScoreHolder, score: int}> */
    public array $scores = [];
    /** @var array<string, array<string, array{player: Player, sortOrder: ScoreboardSortOrder}>> */
    public array $displays = [];
    public ?ScoreboardObjective $handle = null;
    public int $revision = 1;
    public bool $removed = false;

    public function __construct(
        public readonly string $owner,
        public readonly string $id,
        public string $displayName,
    ) {}

    public function isShowingTo(Player $player, ScoreboardDisplaySlot $slot): bool
    {
        return isset($this->displays[strtolower($player->uuid)][$slot->value]);
    }
}
