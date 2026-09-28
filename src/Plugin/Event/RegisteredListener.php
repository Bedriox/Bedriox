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

namespace Bedriox\Server\Plugin\Event;

use Bedriox\Api\Event\EventPriority;

final readonly class RegisteredListener
{
    /** @param callable $callback */
    public function __construct(
        public int $id,
        public int $sequence,
        public string $plugin,
        public string $eventClass,
        public mixed $callback,
        public EventPriority $priority,
        public bool $receiveCancelled,
        public string $description,
    ) {}
}
