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

namespace Bedriox\Server\Plugin\BossBar;

use Bedriox\Api\BossBar\BossBar;
use Bedriox\Api\BossBar\BossBarColor;
use Bedriox\Api\BossBar\BossBarStyle;
use Bedriox\Api\Player\Player;

/** @internal Mutable state owned exclusively by BossBarRegistry. */
final class BossBarState
{
    /** @var array<string, Player> */
    public array $viewers = [];
    public bool $visible = true;
    public bool $removed = false;
    public ?BossBar $handle = null;

    public function __construct(
        public readonly string $owner,
        public readonly string $id,
        public readonly int $actorId,
        public string $title,
        public float $progress,
        public BossBarColor $color,
        public BossBarStyle $style,
    ) {}
}
