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

abstract class Event
{
    private bool $readOnly = false;

    /** @internal */
    final public function captureState(): mixed
    {
        return $this->state();
    }

    /** @internal */
    final public function restoreState(mixed $state): void
    {
        $this->replaceState($state);
    }

    /** @internal */
    final public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    final protected function assertMutable(): void
    {
        if ($this->readOnly) {
            throw new LogicException('MONITOR listeners may not modify events.');
        }
    }

    protected function state(): mixed
    {
        return null;
    }

    protected function replaceState(mixed $state): void
    {
        if ($state !== null) {
            throw new LogicException('This event has no mutable state.');
        }
    }
}
