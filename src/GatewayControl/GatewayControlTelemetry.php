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

namespace Bedriox\Server\GatewayControl;

use InvalidArgumentException;

/** Bounded, point-in-time backend telemetry carried by authenticated health responses. */
final readonly class GatewayControlTelemetry
{
    private const float MAXIMUM_TPS = 1_000.0;
    private const float MAXIMUM_MSPT = 60_000.0;
    private const int MAXIMUM_QUEUE_DEPTH = 1_000_000_000;

    public function __construct(
        public ?float $ticksPerSecond = null,
        public ?float $millisecondsPerTick = null,
        public ?int $memoryUsedBytes = null,
        public ?int $memoryLimitBytes = null,
        public ?string $memoryPressure = null,
        public ?int $chunkQueueDepth = null,
        public ?int $pendingLogins = null,
        public ?float $workerSaturation = null,
        public bool $ready = true,
        public ?int $sampledAtMilliseconds = null,
    ) {
        if (($ticksPerSecond !== null && (!is_finite($ticksPerSecond) || $ticksPerSecond < 0.0 || $ticksPerSecond > self::MAXIMUM_TPS))
            || ($millisecondsPerTick !== null && (!is_finite($millisecondsPerTick) || $millisecondsPerTick < 0.0 || $millisecondsPerTick > self::MAXIMUM_MSPT))
            || ($memoryUsedBytes !== null && $memoryUsedBytes < 0)
            || ($memoryLimitBytes !== null && $memoryLimitBytes < 0)
            || ($memoryPressure !== null && !in_array($memoryPressure, ['normal', 'elevated', 'high', 'critical'], true))
            || ($chunkQueueDepth !== null && ($chunkQueueDepth < 0 || $chunkQueueDepth > self::MAXIMUM_QUEUE_DEPTH))
            || ($pendingLogins !== null && ($pendingLogins < 0 || $pendingLogins > self::MAXIMUM_QUEUE_DEPTH))
            || ($workerSaturation !== null && (!is_finite($workerSaturation) || $workerSaturation < 0.0 || $workerSaturation > 1.0))
            || ($sampledAtMilliseconds !== null && $sampledAtMilliseconds < 0)) {
            throw new InvalidArgumentException('Gateway control telemetry is outside its supported bounds.');
        }
    }

    /** @return array<string, bool|float|int|string> */
    public function payload(): array
    {
        $payload = ['ready' => $this->ready];
        foreach ([
            'tps' => $this->ticksPerSecond,
            'mspt' => $this->millisecondsPerTick,
            'memory_used_bytes' => $this->memoryUsedBytes,
            'memory_limit_bytes' => $this->memoryLimitBytes,
            'memory_pressure' => $this->memoryPressure,
            'chunk_queue' => $this->chunkQueueDepth,
            'pending_logins' => $this->pendingLogins,
            'worker_saturation' => $this->workerSaturation,
            'sampled_at_ms' => $this->sampledAtMilliseconds,
        ] as $key => $value) {
            if ($value !== null) {
                $payload[$key] = $value;
            }
        }

        return $payload;
    }
}
