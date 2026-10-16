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

/** @internal Immutable player-specific sidebar state for packet projection. */
final readonly class SidebarViewProjection
{
    /** @param array<string, string> $lines */
    public function __construct(
        public string $owner,
        public string $id,
        public Player $player,
        public string $title,
        public array $lines,
        public bool $showing,
        public int $revision,
        public bool $removed,
    ) {}
}
