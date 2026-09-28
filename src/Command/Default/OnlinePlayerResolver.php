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

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Player\Player;
use Closure;

/** Resolves current immutable player snapshots for default commands. */
final readonly class OnlinePlayerResolver
{
    /** @param Closure(): list<Player> $players */
    public function __construct(private Closure $players) {}

    /** @return list<Player> */
    public function all(): array
    {
        return ($this->players)();
    }

    public function find(string $name): ?Player
    {
        foreach ($this->all() as $player) {
            if (strcasecmp($player->name, $name) === 0) {
                return $player;
            }
        }

        return null;
    }
}
