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

namespace Bedriox\Server\Transport;

use Bedriox\RakNet\EndpointAdmissionRejectionBatch;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Closure;

/** Emits a bounded sample and one aggregate summary for hostile connection attempts. */
final class EndpointAdmissionRejectionReporter
{
    private const int WINDOW_NANOSECONDS = 10_000_000_000;
    private const int MAXIMUM_SAMPLES_PER_WINDOW = 5;

    private readonly Closure $clock;
    private int $windowStartedAt;
    private int $emitted = 0;
    private int $suppressed = 0;

    /** @param null|Closure(): int $clock */
    public function __construct(
        private readonly RuntimeDiagnostics $diagnostics,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->windowStartedAt = ($this->clock)();
    }

    public function report(EndpointAdmissionRejectionBatch $batch): void
    {
        $now = ($this->clock)();
        if ($now - $this->windowStartedAt >= self::WINDOW_NANOSECONDS) {
            if ($this->suppressed > 0) {
                $this->diagnostics->securityRejection(
                    'proxy_endpoint',
                    'rate_limited',
                    count: $this->suppressed,
                );
            }
            $this->windowStartedAt = $now;
            $this->emitted = 0;
            $this->suppressed = 0;
        }

        foreach ($batch->events as $event) {
            if ($this->emitted >= self::MAXIMUM_SAMPLES_PER_WINDOW) {
                ++$this->suppressed;
                continue;
            }
            ++$this->emitted;
            $this->diagnostics->securityRejection(
                'proxy_endpoint',
                'untrusted_source',
                $event->remoteAddress,
                $event->remotePort,
            );
        }
        $this->suppressed = min(PHP_INT_MAX, $this->suppressed + $batch->droppedEventCount);
    }
}
