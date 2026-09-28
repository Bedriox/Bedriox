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

namespace Bedriox\Server\Worker;

/** Reserves latency-sensitive capacity when bulk world work is saturated. */
final readonly class WorkerLaneCapacity
{
    private int $maximumWorldWorkers;

    public function __construct(private int $workerCount)
    {
        if ($workerCount < 1 || $workerCount > 32) {
            throw new \InvalidArgumentException('Worker capacity requires between 1 and 32 workers.');
        }
        $reserved = $workerCount === 1 ? 0 : min(2, max(1, intdiv($workerCount, 4)));
        $this->maximumWorldWorkers = $workerCount - $reserved;
    }

    public function canDispatch(WorkerLane $lane, int $runningInLane): bool
    {
        if ($runningInLane < 0 || $runningInLane > $this->workerCount) {
            throw new \InvalidArgumentException('Running worker count is outside the pool capacity.');
        }

        return $runningInLane < ($lane === WorkerLane::WORLD
            ? $this->maximumWorldWorkers
            : $this->workerCount);
    }

    public function maximumWorldWorkers(): int
    {
        return $this->maximumWorldWorkers;
    }
}
