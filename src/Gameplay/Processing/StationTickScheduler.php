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

namespace Bedriox\Server\Gameplay\Processing;

use InvalidArgumentException;
use OverflowException;
use SplPriorityQueue;

/**
 * Bounded due-time index for active processing stations.
 *
 * Rescheduling appends an immutable generation. Stale heap entries are discarded lazily, so the
 * world tick never scans inactive block entities or removes arbitrary heap entries.
 */
final class StationTickScheduler
{
    public const int MAXIMUM_STATIONS = 65_536;
    public const int MAXIMUM_QUEUED_GENERATIONS = 262_144;

    /** @var SplPriorityQueue<array{int, int}, array{string, int, int}> */
    private SplPriorityQueue $queue;

    /** @var array<string, array{due: int, generation: int}> */
    private array $scheduled = [];

    private int $generation = 0;

    public function __construct(
        private readonly int $maximumStations = self::MAXIMUM_STATIONS,
        private readonly int $maximumQueuedGenerations = self::MAXIMUM_QUEUED_GENERATIONS,
    ) {
        if ($maximumStations < 1 || $maximumStations > self::MAXIMUM_STATIONS
            || $maximumQueuedGenerations < $maximumStations
            || $maximumQueuedGenerations > self::MAXIMUM_QUEUED_GENERATIONS) {
            throw new InvalidArgumentException('Station scheduler limits are invalid.');
        }
        $this->queue = new SplPriorityQueue();
        $this->queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
    }

    public function schedule(string $key, int $dueTick): void
    {
        self::validateKey($key);
        if ($dueTick < 0) {
            throw new InvalidArgumentException('Station due tick cannot be negative.');
        }
        if (!isset($this->scheduled[$key]) && count($this->scheduled) >= $this->maximumStations) {
            throw new OverflowException('Active station scheduler capacity is exhausted.');
        }
        if ($this->queue->count() >= $this->maximumQueuedGenerations) {
            $this->compact();
            if ($this->queue->count() >= $this->maximumQueuedGenerations) {
                throw new OverflowException('Station scheduler generation capacity is exhausted.');
            }
        }
        if ($this->generation === PHP_INT_MAX) {
            $this->compact(resetGeneration: true);
        }
        $generation = ++$this->generation;
        $this->scheduled[$key] = ['due' => $dueTick, 'generation' => $generation];
        $this->queue->insert([$key, $dueTick, $generation], [-$dueTick, -$generation]);
    }

    public function cancel(string $key): bool
    {
        self::validateKey($key);
        if (!isset($this->scheduled[$key])) {
            return false;
        }
        unset($this->scheduled[$key]);

        return true;
    }

    /** @return list<ScheduledStation> */
    public function drainDue(int $currentTick, int $maximum): array
    {
        if ($currentTick < 0 || $maximum < 0 || $maximum > $this->maximumStations) {
            throw new InvalidArgumentException('Station scheduler drain bounds are invalid.');
        }
        $result = [];
        while (count($result) < $maximum && !$this->queue->isEmpty()) {
            /** @var array{string, int, int} $head */
            $head = $this->queue->current();
            [$key, $dueTick, $generation] = $head;
            $current = $this->scheduled[$key] ?? null;
            if ($current === null || $current['due'] !== $dueTick || $current['generation'] !== $generation) {
                $this->queue->extract();
                continue;
            }
            if ($dueTick > $currentTick) {
                break;
            }
            $this->queue->extract();
            unset($this->scheduled[$key]);
            $result[] = new ScheduledStation($key, $dueTick);
        }

        return $result;
    }

    public function count(): int
    {
        return count($this->scheduled);
    }

    public function queuedGenerationCount(): int
    {
        return $this->queue->count();
    }

    public function nextDueTick(): ?int
    {
        while (!$this->queue->isEmpty()) {
            /** @var array{string, int, int} $head */
            $head = $this->queue->current();
            [$key, $dueTick, $generation] = $head;
            $current = $this->scheduled[$key] ?? null;
            if ($current !== null && $current['due'] === $dueTick && $current['generation'] === $generation) {
                return $dueTick;
            }
            $this->queue->extract();
        }

        return null;
    }

    private function compact(bool $resetGeneration = false): void
    {
        /** @var SplPriorityQueue<array{int, int}, array{string, int, int}> $queue */
        $queue = new SplPriorityQueue();
        $queue->setExtractFlags(SplPriorityQueue::EXTR_DATA);
        $generation = 0;
        foreach ($this->scheduled as $key => $entry) {
            $nextGeneration = $resetGeneration ? ++$generation : $entry['generation'];
            $this->scheduled[$key] = ['due' => $entry['due'], 'generation' => $nextGeneration];
            $queue->insert([$key, $entry['due'], $nextGeneration], [-$entry['due'], -$nextGeneration]);
        }
        $this->queue = $queue;
        if ($resetGeneration) {
            $this->generation = $generation;
        }
    }

    private static function validateKey(string $key): void
    {
        if ($key === '' || strlen($key) > 192 || preg_match('/^[A-Za-z0-9_.:\/-]+$/D', $key) !== 1) {
            throw new InvalidArgumentException('Station scheduler key is invalid.');
        }
    }
}
