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

namespace Bedriox\Server\Worker\Internal;

use InvalidArgumentException;

/** Pure capacity and idle-retirement policy for demand-driven compute processes. */
final readonly class WorkerResidencyPolicy
{
    public const int DEFAULT_IDLE_NANOSECONDS = 300_000_000_000;

    public function __construct(private int $idleNanoseconds = self::DEFAULT_IDLE_NANOSECONDS)
    {
        if ($idleNanoseconds < 1_000_000_000 || $idleNanoseconds > 3_600_000_000_000) {
            throw new InvalidArgumentException('Worker idle retirement must be between one second and one hour.');
        }
    }

    public function desiredWorkers(int $maximumWorkers, int $runningWorkers, int $busyWorkers, int $queuedTasks): int
    {
        if ($maximumWorkers < 1 || $maximumWorkers > 32
            || $runningWorkers < 0 || $runningWorkers > $maximumWorkers
            || $busyWorkers < 0 || $busyWorkers > $runningWorkers
            || $queuedTasks < 0) {
            throw new InvalidArgumentException('Worker residency inputs are invalid.');
        }

        return min($maximumWorkers, max($runningWorkers, $busyWorkers + $queuedTasks));
    }

    public function idleExpired(int $lastActiveNanoseconds, int $nowNanoseconds): bool
    {
        if ($lastActiveNanoseconds < 0 || $nowNanoseconds < $lastActiveNanoseconds) {
            throw new InvalidArgumentException('Worker activity timestamps are invalid.');
        }

        return $nowNanoseconds - $lastActiveNanoseconds >= $this->idleNanoseconds;
    }
}
