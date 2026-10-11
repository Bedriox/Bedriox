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

namespace Bedriox\Api\Visibility;

use Bedriox\Api\Player\Player;

/** Controls which authenticated players are eligible to observe a presentation. */
interface Visibility
{
    public function getMode(): VisibilityMode;

    /** Switches policy mode; switching mode starts its UUID selection empty. */
    public function setMode(VisibilityMode $mode): void;

    /** Makes one player eligible under the current mode. */
    public function showTo(Player $player): void;

    /** Makes one player ineligible under the current mode. */
    public function hideFrom(Player $player): void;

    /** Replaces the policy with a selected audience containing only this player. */
    public function showOnlyTo(Player $player): void;

    /** Restores automatic spatial and lifecycle visibility. */
    public function showToAll(): void;

    /** Replaces the policy with a globally hidden audience. */
    public function hideFromAll(): void;

    /** Returns policy eligibility; spatial and lifecycle admission still apply. */
    public function isVisibleTo(Player $player): bool;

    /** @return list<string> canonical authenticated UUIDs used by SELECTED mode */
    public function getSelectedPlayerUuids(): array;

    /** @return list<string> canonical authenticated UUIDs used by EXCLUDED mode */
    public function getExcludedPlayerUuids(): array;
}
