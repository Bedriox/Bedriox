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

use Bedriox\Api\Event\ListenerSubscription;

final class EventSubscription implements ListenerSubscription
{
    private bool $registered = true;

    public function __construct(
        private readonly EventDispatcher $dispatcher,
        private readonly int $id,
    ) {}

    public function unregister(): void
    {
        if (!$this->registered) {
            return;
        }
        $this->registered = false;
        $this->dispatcher->unregister($this->id);
    }

    public function isRegistered(): bool
    {
        return $this->registered && $this->dispatcher->has($this->id);
    }
}
