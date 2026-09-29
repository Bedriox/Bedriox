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

namespace Bedriox\Server\World\Environment;

use Bedriox\Server\World\BlockPosition;
use Closure;
use InvalidArgumentException;
use OverflowException;
use SplPriorityQueue;
use UnexpectedValueException;

/** Deterministic, deduplicated queue for environmental work in one world. */
final class EnvironmentTickScheduler
{
    /** @var SplPriorityQueue<array{due: int, sequence: int}, ScheduledEnvironmentTick> */
    private SplPriorityQueue $queue;

    /** @var array<string, ScheduledEnvironmentTick> */
    private array $scheduled = [];

    private int $sequence = 0;
    private readonly Closure $clock;

    /** @param null|Closure(): int $clock Monotonic nanosecond clock. */
    public function __construct(
        private readonly int $maximumQueued = 100_000,
        ?Closure $clock = null,
    ) {
        if ($maximumQueued < 1 || $maximumQueued > 1_000_000) {
            throw new InvalidArgumentException('Environmental tick queue capacity must be between 1 and 1000000.');
        }
        $this->clock = $clock ?? static fn(): int => hrtime(true);
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    public function schedule(
        BlockPosition $position,
        EnvironmentTickType $type,
        int $currentTick,
        int $delayTicks,
    ): bool {
        if ($currentTick < 0 || $delayTicks < 0 || $delayTicks > 72_000) {
            throw new InvalidArgumentException('Environmental tick timing is outside the supported bounds.');
        }

        $dueTick = $currentTick > PHP_INT_MAX - $delayTicks ? PHP_INT_MAX : $currentTick + $delayTicks;
        $tick = new ScheduledEnvironmentTick($position, $type, $dueTick);
        $key = $tick->key();
        $existing = $this->scheduled[$key] ?? null;
        if ($existing !== null && $existing->dueTick <= $dueTick) {
            return false;
        }
        if ($existing === null && count($this->scheduled) >= $this->maximumQueued) {
            throw new OverflowException('Environmental tick queue is full.');
        }

        $sequence = $this->sequence++;
        $this->scheduled[$key] = $tick;
        $this->queue->insert($tick, ['due' => -$dueTick, 'sequence' => -$sequence]);

        return true;
    }

    public function cancel(BlockPosition $position, EnvironmentTickType $type): bool
    {
        $key = $type->value . ':' . $position->x . ':' . $position->y . ':' . $position->z;
        if (!isset($this->scheduled[$key])) {
            return false;
        }
        unset($this->scheduled[$key]);

        return true;
    }

    public function count(): int
    {
        return count($this->scheduled);
    }

    public function drain(int $currentTick, int $maximumTicks, int $timeBudgetMicroseconds): EnvironmentTickDrain
    {
        if ($currentTick < 0 || $maximumTicks < 1 || $maximumTicks > $this->maximumQueued) {
            throw new InvalidArgumentException('Environmental tick drain count is outside the supported bounds.');
        }
        if ($timeBudgetMicroseconds < 1 || $timeBudgetMicroseconds > 1_000_000) {
            throw new InvalidArgumentException('Environmental tick time budget must be between 1 and 1000000 microseconds.');
        }

        $startedAt = $this->now();
        $budgetNanoseconds = $timeBudgetMicroseconds * 1_000;
        $deadline = $startedAt > PHP_INT_MAX - $budgetNanoseconds ? PHP_INT_MAX : $startedAt + $budgetNanoseconds;
        $ticks = [];
        while (!$this->queue->isEmpty() && count($ticks) < $maximumTicks) {
            $candidate = $this->queue->top();
            if (!$candidate instanceof ScheduledEnvironmentTick) {
                throw new UnexpectedValueException('Environmental tick queue returned an invalid entry.');
            }
            if ($candidate->dueTick > $currentTick) {
                break;
            }
            $candidate = $this->queue->extract();
            if (!$candidate instanceof ScheduledEnvironmentTick) {
                throw new UnexpectedValueException('Environmental tick queue returned an invalid entry.');
            }
            if (($this->scheduled[$candidate->key()] ?? null) !== $candidate) {
                continue;
            }

            unset($this->scheduled[$candidate->key()]);
            $ticks[] = $candidate;
            if ($this->now() >= $deadline) {
                break;
            }
        }

        $finishedAt = $this->now();
        $hasDueWork = false;
        while (!$this->queue->isEmpty()) {
            $candidate = $this->queue->top();
            if (!$candidate instanceof ScheduledEnvironmentTick) {
                throw new UnexpectedValueException('Environmental tick queue returned an invalid entry.');
            }
            if (($this->scheduled[$candidate->key()] ?? null) !== $candidate) {
                $this->queue->extract();
                continue;
            }
            $hasDueWork = $candidate->dueTick <= $currentTick;
            break;
        }

        return new EnvironmentTickDrain(
            $ticks,
            max(0, $finishedAt - $startedAt),
            count($this->scheduled),
            $hasDueWork,
        );
    }

    private function now(): int
    {
        $now = ($this->clock)();
        if ($now < 0) {
            throw new UnexpectedValueException('Environmental tick clock returned a negative monotonic time.');
        }

        return $now;
    }
}
