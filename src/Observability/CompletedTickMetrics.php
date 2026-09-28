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

/** Immutable calculations derived only from the monitor's completed-tick ring. */
final readonly class CompletedTickMetrics
{
    /**
     * @param array<string, float> $averageSubsystemMilliseconds
     */
    public function __construct(
        public float $currentTps,
        public float $averageTps,
        public float $minimumTps,
        public float $currentMspt,
        public float $averageMspt,
        public float $p95Mspt,
        public float $p99Mspt,
        public float $tickUsagePercent,
        public int $samples,
        public array $averageSubsystemMilliseconds,
        public float $averageUnclassifiedMilliseconds,
        public ?float $networkReceiveBytesPerSecond,
        public ?float $networkSendBytesPerSecond,
    ) {}
}
