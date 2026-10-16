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

use Bedriox\Api\Scoreboard\Sidebar;

/** @internal Mutable state owned exclusively by ScoreboardRegistry. */
final class SidebarState
{
    /** @var array<string, string> */
    public array $lines = [];
    /** @var array<string, SidebarViewState> */
    public array $views = [];
    public ?Sidebar $handle = null;
    public int $revision = 1;
    public bool $removed = false;

    public function __construct(
        public readonly string $owner,
        public readonly string $id,
        public string $title,
    ) {}
}
