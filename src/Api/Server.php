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

namespace Bedriox\Api;

use Bedriox\Api\Player\Player;
use Bedriox\Api\Whitelist\Whitelist;
use Bedriox\Api\World\WorldManager;

interface Server
{
    public function broadcastMessage(string|TranslatableMessage $message): int;

    public function getWorldManager(): WorldManager;

    public function getWhitelist(): Whitelist;

    /** @return list<Player> */
    public function getOnlinePlayers(): array;

    public function getPlayerByUuid(string $uuid): ?Player;

    public function getPlayerByName(string $name): ?Player;
}
