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

namespace Bedriox\Api\Event;

use LogicException;

abstract class CancellableEvent extends Event
{
    private bool $cancelled = false;

    final public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    final public function cancel(): void
    {
        $this->setCancelled(true);
    }

    final public function setCancelled(bool $cancelled): void
    {
        $this->assertMutable();
        if ($cancelled && !$this->cancellationAllowed()) {
            throw new LogicException('This event occurrence cannot be cancelled.');
        }
        $this->cancelled = $cancelled;
    }

    protected function cancellationAllowed(): bool
    {
        return true;
    }

    protected function state(): mixed
    {
        return $this->cancelled;
    }

    protected function replaceState(mixed $state): void
    {
        $this->cancelled = (bool) $state;
    }
}
