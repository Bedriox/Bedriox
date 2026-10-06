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

namespace Bedriox\Server\World;

use Closure;
use InvalidArgumentException;
use OverflowException;

/** Monotonic grace-period queue for chunks which no longer have an active owner. */
final class ChunkUnloadManager
{
    /** @var array<string, array{position: ChunkPosition, eligibleAt: int}> */
    private array $queued = [];

    private readonly Closure $clock;

    /** @param null|Closure(): int $clock Monotonic nanosecond clock. */
    public function __construct(
        private readonly int $graceNanoseconds = 30_000_000_000,
        private readonly int $maximumQueued = 100_000,
        ?Closure $clock = null,
    ) {
        if ($graceNanoseconds < 0 || $graceNanoseconds > 6_000_000_000_000) {
            throw new InvalidArgumentException('Chunk unload grace period must be between zero and 6000 seconds.');
        }
        if ($maximumQueued < 1 || $maximumQueued > 100_000) {
            throw new InvalidArgumentException('Chunk unload queue capacity must be between 1 and 100000.');
        }
        $this->clock = $clock ?? static fn(): int => hrtime(true);
    }

    /** Queues one coordinate once. A later retain must cancel it explicitly. */
    public function queue(ChunkPosition $position): bool
    {
        $key = $position->key();
        if (isset($this->queued[$key])) {
            return false;
        }
        if (count($this->queued) >= $this->maximumQueued) {
            throw new OverflowException('Chunk unload queue is full.');
        }
        $now = $this->now();
        $eligibleAt = $now > PHP_INT_MAX - $this->graceNanoseconds
            ? PHP_INT_MAX
            : $now + $this->graceNanoseconds;
        $this->queued[$key] = ['position' => $position, 'eligibleAt' => $eligibleAt];

        return true;
    }

    /**
     * Makes an unowned chunk eligible for the next bounded maintenance pass.
     *
     * Dimension changes and disconnected sessions have no locality benefit from
     * retaining their abandoned view for the normal reuse grace period.
     */
    public function queueImmediately(ChunkPosition $position): bool
    {
        $key = $position->key();
        if (isset($this->queued[$key])) {
            $this->queued[$key]['eligibleAt'] = $this->now();

            return false;
        }
        if (count($this->queued) >= $this->maximumQueued) {
            throw new OverflowException('Chunk unload queue is full.');
        }
        $this->queued[$key] = ['position' => $position, 'eligibleAt' => $this->now()];

        return true;
    }

    public function cancel(ChunkPosition $position): bool
    {
        $key = $position->key();
        if (!isset($this->queued[$key])) {
            return false;
        }
        unset($this->queued[$key]);

        return true;
    }

    public function contains(ChunkPosition $position): bool
    {
        return isset($this->queued[$position->key()]);
    }

    /** Moves deferred work behind older candidates without extending its grace deadline. */
    public function defer(ChunkPosition $position): bool
    {
        $key = $position->key();
        $entry = $this->queued[$key] ?? null;
        if ($entry === null) {
            return false;
        }
        unset($this->queued[$key]);
        $this->queued[$key] = $entry;

        return true;
    }

    public function count(): int
    {
        return count($this->queued);
    }

    /**
     * Returns due coordinates without removing them. Persistence or retention may require a later retry.
     *
     * @return list<ChunkPosition>
     */
    public function due(int $maximumChunks, int $timeBudgetMicroseconds): array
    {
        if ($maximumChunks < 1 || $maximumChunks > $this->maximumQueued) {
            throw new InvalidArgumentException('Chunk unload processing count is outside the queue bounds.');
        }
        if ($timeBudgetMicroseconds < 1 || $timeBudgetMicroseconds > 1_000_000) {
            throw new InvalidArgumentException('Chunk unload processing time budget must be between 1 and 1000000 microseconds.');
        }

        $now = $this->now();
        $budgetNanoseconds = $timeBudgetMicroseconds * 1_000;
        $deadline = $now > PHP_INT_MAX - $budgetNanoseconds ? PHP_INT_MAX : $now + $budgetNanoseconds;
        $due = [];
        foreach ($this->queued as $entry) {
            if ($entry['eligibleAt'] <= $now) {
                $due[] = $entry['position'];
                if (count($due) >= $maximumChunks) {
                    break;
                }
            }
            if ($this->now() >= $deadline) {
                break;
            }
        }

        return $due;
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if ($now < 0) {
            throw new \UnexpectedValueException('Chunk unload clock returned a negative monotonic time.');
        }

        return $now;
    }
}
