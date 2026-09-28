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

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Player\Player;

/**
 * Published after a container session closes.
 *
 * Closure is deliberately non-cancellable because retaining a server-side window after the client
 * dismissed it would desynchronize every later inventory action.
 */
final class InventoryCloseEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ContainerView $container,
        public readonly InventoryCloseReason $reason,
        public readonly ?Container $handle = null,
    ) {}
}
