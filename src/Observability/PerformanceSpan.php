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

namespace Bedriox\Server\Observability;

/** One idempotently closable entry on the monitor's bounded exclusive timer stack. */
final class PerformanceSpan
{
    private bool $closed = false;

    public function __construct(
        private readonly PerformanceMonitor $monitor,
        private readonly string $subsystem,
        private readonly int $generation,
    ) {}

    public function end(?int $nowNanoseconds = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->monitor->endSubsystem($this->subsystem, $this->generation, $nowNanoseconds);
    }

    public function __destruct()
    {
        $this->end();
    }
}
