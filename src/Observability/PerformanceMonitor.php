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

use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;
use Bedriox\Server\Entity\EntityRuntimeMetrics;
use Bedriox\Server\Entity\Navigation\EntityNavigationMetrics;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\MemoryManagementDecision;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Runtime\ChunkStreamingSnapshot;
use Bedriox\Server\Worker\Chunk\PreparedChunkCacheSnapshot;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\World\ChunkRepositorySnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use InvalidArgumentException;

/** Bounded in-process timing history used by operator diagnostics and release gates. */
final class PerformanceMonitor
{
    private const MAXIMUM_TIMER_DEPTH = 16;
    private const RATE_WINDOW_SECONDS = 10;

    /** @var list<int> */
    private array $tickDurations = [];
    /** @var list<int> */
    private array $tickCompletions = [];
    /** @var list<array<string, int>> */
    private array $subsystemDurations = [];
    /** @var list<int> */
    private array $unclassifiedDurations = [];
    /** @var list<int> */
    private array $pollDurations = [];
    /** @var list<array{subsystem: string, started: int, child: int}> */
    private array $timerStack = [];
    /** @var array<string, int> */
    private array $activeSubsystemDurations = [];
    /** @var array<int, array{received: int, sent: int}> monotonic whole second => totals */
    private array $networkBuckets = [];

    private readonly int $startedAtNanoseconds;
    private ?int $tickStartedAtNanoseconds = null;
    private int $timerImbalances = 0;
    private int $timerGeneration = 0;
    private ?CompletedTickMetrics $completed = null;
    private int $lastNetworkPruneSecond = -1;

    public function __construct(
        private readonly int $targetTicksPerSecond = 20,
        private readonly int $maximumSamples = 1_200,
        ?int $startedAtNanoseconds = null,
    ) {
        if ($targetTicksPerSecond < 1 || $targetTicksPerSecond > 1_000
            || $maximumSamples < 20 || $maximumSamples > 72_000) {
            throw new InvalidArgumentException('Performance monitor limits are invalid.');
        }
        $this->startedAtNanoseconds = $startedAtNanoseconds ?? self::now();
        $this->completed = $this->calculateCompletedMetrics($this->startedAtNanoseconds);
    }

    /** Starts one aggregate runtime observation which may complete one or more catch-up ticks. */
    public function beginTick(?int $startedAtNanoseconds = null): void
    {
        if ($this->tickStartedAtNanoseconds !== null || $this->timerStack !== []) {
            $this->recordTimerImbalance();
        }
        $this->timerStack = [];
        $this->activeSubsystemDurations = [];
        $this->timerGeneration = self::saturatingAdd($this->timerGeneration, 1);
        $this->tickStartedAtNanoseconds = $startedAtNanoseconds ?? self::now();
    }

    public function startSubsystem(string $subsystem, ?int $nowNanoseconds = null): PerformanceSpan
    {
        if (!in_array($subsystem, PerformanceSubsystem::ALL, true)) {
            throw new InvalidArgumentException('Performance subsystem is not registered.');
        }
        if ($this->tickStartedAtNanoseconds === null || count($this->timerStack) >= self::MAXIMUM_TIMER_DEPTH) {
            $this->recordTimerImbalance();

            return new PerformanceSpan($this, $subsystem, $this->timerGeneration);
        }
        $this->timerStack[] = [
            'subsystem' => $subsystem,
            'started' => $nowNanoseconds ?? self::now(),
            'child' => 0,
        ];

        return new PerformanceSpan($this, $subsystem, $this->timerGeneration);
    }

    /** @internal Called by PerformanceSpan. */
    public function endSubsystem(string $subsystem, int $generation, ?int $nowNanoseconds = null): void
    {
        if ($generation !== $this->timerGeneration) {
            return;
        }
        $index = count($this->timerStack) - 1;
        if ($index < 0 || $this->timerStack[$index]['subsystem'] !== $subsystem) {
            $this->recordTimerImbalance();

            return;
        }
        $ended = $nowNanoseconds ?? self::now();
        $frame = array_pop($this->timerStack);
        if ($frame === null || $ended < $frame['started']) {
            $this->recordTimerImbalance();

            return;
        }
        $elapsed = $ended - $frame['started'];
        $exclusive = max(0, $elapsed - $frame['child']);
        $this->activeSubsystemDurations[$subsystem] = self::saturatingAdd(
            $this->activeSubsystemDurations[$subsystem] ?? 0,
            $exclusive,
        );
        $parent = count($this->timerStack) - 1;
        if ($parent >= 0) {
            $this->timerStack[$parent]['child'] = self::saturatingAdd($this->timerStack[$parent]['child'], $elapsed);
        }
    }

    /** Completes one or more ticks from the current aggregate runtime observation. */
    public function completeTicks(int $tickCount = 1, ?int $completedAtNanoseconds = null): void
    {
        if ($tickCount < 1) {
            throw new InvalidArgumentException('Completed tick count must be positive.');
        }
        $completedAt = $completedAtNanoseconds ?? self::now();
        if ($this->tickStartedAtNanoseconds === null || $this->timerStack !== []
            || $completedAt < $this->tickStartedAtNanoseconds) {
            $this->recordTimerImbalance();

            return;
        }
        $totalDuration = $completedAt - $this->tickStartedAtNanoseconds;
        $duration = intdiv($totalDuration, $tickCount);
        $classified = array_sum($this->activeSubsystemDurations);
        $unclassified = max(0, intdiv($totalDuration - $classified, $tickCount));
        $perTickSubsystems = [];
        foreach ($this->activeSubsystemDurations as $subsystem => $nanoseconds) {
            $perTickSubsystems[$subsystem] = intdiv($nanoseconds, $tickCount);
        }
        for ($index = 0; $index < $tickCount; ++$index) {
            $this->append($this->tickDurations, $duration);
            $this->append($this->tickCompletions, $completedAt);
            $this->append($this->subsystemDurations, $perTickSubsystems);
            $this->append($this->unclassifiedDurations, $unclassified);
        }
        $this->tickStartedAtNanoseconds = null;
        $this->activeSubsystemDurations = [];
        $this->completed = null;
    }

    /** Discards a runtime observation which did not complete a simulation tick. */
    public function cancelTick(): void
    {
        if ($this->timerStack !== []) {
            $this->recordTimerImbalance();
        }
        $this->tickStartedAtNanoseconds = null;
        $this->timerStack = [];
        $this->activeSubsystemDurations = [];
    }

    /** Compatibility observation for callers which already measured a complete tick. */
    public function recordTick(int $durationNanoseconds, ?int $completedAtNanoseconds = null): void
    {
        if ($durationNanoseconds < 0) {
            throw new InvalidArgumentException('Tick duration cannot be negative.');
        }
        $completedAt = $completedAtNanoseconds ?? self::now();
        $this->append($this->tickDurations, $durationNanoseconds);
        $this->append($this->tickCompletions, $completedAt);
        $this->append($this->subsystemDurations, []);
        $this->append($this->unclassifiedDurations, $durationNanoseconds);
        $this->completed = null;
    }

    public function recordPoll(int $durationNanoseconds): void
    {
        if ($durationNanoseconds < 0) {
            throw new InvalidArgumentException('Runtime poll duration cannot be negative.');
        }
        $this->append($this->pollDurations, $durationNanoseconds);
    }

    public function recordNetworkReceived(int $bytes, ?int $nowNanoseconds = null): void
    {
        $this->recordNetworkBytes($bytes, true, $nowNanoseconds ?? self::now());
    }

    public function recordNetworkSent(int $bytes, ?int $nowNanoseconds = null): void
    {
        $this->recordNetworkBytes($bytes, false, $nowNanoseconds ?? self::now());
    }

    public function timerImbalances(): int
    {
        return $this->timerImbalances;
    }

    public function snapshot(
        int $onlinePlayers = 0,
        int $maximumPlayers = 0,
        int $loadedChunks = 0,
        int $dirtyChunks = 0,
        ?int $nowNanoseconds = null,
        int $generatingChunks = 0,
        int $scheduledPluginTasks = 0,
        int $deferredPluginTasks = 0,
        ?WorkerPoolSnapshot $coreWorkers = null,
        ?WorkerPoolSnapshot $pluginWorkers = null,
        ?BackgroundLogWriterSnapshot $logWriter = null,
        int $configuredMemoryLimitBytes = 0,
        int $worldCount = 0,
        int $entityCount = 0,
        int $pendingAsyncPluginTasks = 0,
        int $maximumAsyncCompletionsPerTick = 0,
        ?ChunkRepositorySnapshot $chunkCache = null,
        ?ChunkStreamingSnapshot $chunkStreaming = null,
        ?PersistenceQueueSnapshot $worldPersistence = null,
        ?PersistenceQueueSnapshot $playerPersistence = null,
        ?PreparedChunkCacheSnapshot $preparedChunkCache = null,
        ?MemoryManagementDecision $memoryManagement = null,
        ?GarbageCollectionReport $garbageCollection = null,
        int $garbageCollectorRuns = 0,
        int $garbageCollectorThreshold = 0,
        ?ChunkUnloadResult $chunkUnload = null,
        int $totalChunksUnloaded = 0,
        int $preparedBytesTrimmed = 0,
        ?AiSchedulerMetrics $entityAi = null,
        ?EntityRuntimeMetrics $entityRuntime = null,
        ?EntityNavigationMetrics $entityNavigation = null,
    ): PerformanceSnapshot {
        if ($onlinePlayers < 0 || $maximumPlayers < 0 || $onlinePlayers > $maximumPlayers
            || $loadedChunks < 0 || $dirtyChunks < 0 || $generatingChunks < 0
            || $scheduledPluginTasks < 0 || $deferredPluginTasks < 0 || $configuredMemoryLimitBytes < 0
            || $worldCount < 0 || $entityCount < 0 || $pendingAsyncPluginTasks < 0
            || $maximumAsyncCompletionsPerTick < 0 || $garbageCollectorRuns < 0
            || $garbageCollectorThreshold < 0 || $totalChunksUnloaded < 0 || $preparedBytesTrimmed < 0) {
            throw new InvalidArgumentException('Performance gauges cannot be negative.');
        }
        $uptimeNanoseconds = max(0, ($nowNanoseconds ?? self::now()) - $this->startedAtNanoseconds);
        $metrics = $this->completed ??= $this->calculateCompletedMetrics($nowNanoseconds ?? self::now());

        return new PerformanceSnapshot(
            (int) floor($uptimeNanoseconds / 1_000_000_000),
            $metrics->currentTps,
            $metrics->averageTps,
            $metrics->minimumTps,
            $metrics->currentMspt,
            $metrics->averageMspt,
            $metrics->p95Mspt,
            $metrics->p99Mspt,
            $metrics->tickUsagePercent,
            $metrics->samples,
            self::milliseconds(self::average($this->pollDurations)),
            self::milliseconds(self::percentile($this->pollDurations, 0.95)),
            memory_get_usage(true),
            memory_get_peak_usage(true),
            $onlinePlayers,
            $maximumPlayers,
            $loadedChunks,
            $dirtyChunks,
            $generatingChunks,
            $scheduledPluginTasks,
            $deferredPluginTasks,
            $coreWorkers,
            $pluginWorkers,
            $logWriter,
            $metrics->averageSubsystemMilliseconds,
            $metrics->averageUnclassifiedMilliseconds,
            $metrics->networkReceiveBytesPerSecond,
            $metrics->networkSendBytesPerSecond,
            $this->timerImbalances,
            $configuredMemoryLimitBytes,
            $worldCount,
            $entityCount,
            $pendingAsyncPluginTasks,
            $maximumAsyncCompletionsPerTick,
            $chunkCache,
            $chunkStreaming,
            $worldPersistence,
            $playerPersistence,
            $preparedChunkCache,
            $memoryManagement,
            $garbageCollection,
            $garbageCollectorRuns,
            $garbageCollectorThreshold,
            $chunkUnload,
            $totalChunksUnloaded,
            $preparedBytesTrimmed,
            $entityAi,
            $entityRuntime,
            $entityNavigation,
        );
    }

    private function calculateCompletedMetrics(int $completedAtNanoseconds): CompletedTickMetrics
    {
        $currentCompletions = array_slice($this->tickCompletions, -20);
        $currentDurations = array_slice($this->tickDurations, -20);
        $currentMspt = self::milliseconds(self::average($currentDurations));
        $subsystemAverages = [];
        foreach (PerformanceSubsystem::ALL as $subsystem) {
            $values = array_map(
                static fn(array $durations): int => $durations[$subsystem] ?? 0,
                $this->subsystemDurations,
            );
            $subsystemAverages[$subsystem] = self::milliseconds(self::average($values));
        }
        [$receiveRate, $sendRate] = $this->networkRates($completedAtNanoseconds);

        return new CompletedTickMetrics(
            $this->ticksPerSecond($currentCompletions),
            $this->ticksPerSecond($this->tickCompletions),
            $this->minimumTicksPerSecond($this->tickCompletions),
            $currentMspt,
            self::milliseconds(self::average($this->tickDurations)),
            self::milliseconds(self::percentile($this->tickDurations, 0.95)),
            self::milliseconds(self::percentile($this->tickDurations, 0.99)),
            $currentMspt / (1_000 / $this->targetTicksPerSecond) * 100,
            count($this->tickDurations),
            $subsystemAverages,
            self::milliseconds(self::average($this->unclassifiedDurations)),
            $receiveRate,
            $sendRate,
        );
    }

    private function recordNetworkBytes(int $bytes, bool $received, int $nowNanoseconds): void
    {
        if ($bytes < 0) {
            throw new InvalidArgumentException('Network byte count cannot be negative.');
        }
        $second = intdiv($nowNanoseconds, 1_000_000_000);
        $bucket = $this->networkBuckets[$second] ?? ['received' => 0, 'sent' => 0];
        $field = $received ? 'received' : 'sent';
        $bucket[$field] = self::saturatingAdd($bucket[$field], $bytes);
        $this->networkBuckets[$second] = $bucket;
        if ($second !== $this->lastNetworkPruneSecond) {
            foreach (array_keys($this->networkBuckets) as $bucketSecond) {
                if ($bucketSecond < $second - self::RATE_WINDOW_SECONDS) {
                    unset($this->networkBuckets[$bucketSecond]);
                }
            }
            $this->lastNetworkPruneSecond = $second;
        }
    }

    /** @return array{?float, ?float} */
    private function networkRates(int $nowNanoseconds): array
    {
        if ($this->networkBuckets === []) {
            return [null, null];
        }
        $second = intdiv($nowNanoseconds, 1_000_000_000);
        $received = 0;
        $sent = 0;
        foreach ($this->networkBuckets as $bucketSecond => $bucket) {
            if ($bucketSecond > $second - self::RATE_WINDOW_SECONDS && $bucketSecond <= $second) {
                $received = self::saturatingAdd($received, $bucket['received']);
                $sent = self::saturatingAdd($sent, $bucket['sent']);
            }
        }

        return [$received / self::RATE_WINDOW_SECONDS, $sent / self::RATE_WINDOW_SECONDS];
    }

    private function recordTimerImbalance(): void
    {
        $this->timerImbalances = self::saturatingAdd($this->timerImbalances, 1);
        $this->timerGeneration = self::saturatingAdd($this->timerGeneration, 1);
        $this->tickStartedAtNanoseconds = null;
        $this->timerStack = [];
        $this->activeSubsystemDurations = [];
    }

    /**
     * @template TValue
     * @param list<TValue> $values
     * @param TValue $value
     */
    private function append(array &$values, mixed $value): void
    {
        $values[] = $value;
        if (count($values) > $this->maximumSamples) {
            array_shift($values);
        }
    }

    /** @param list<int> $values */
    private static function average(array $values): int
    {
        return $values === [] ? 0 : (int) round(array_sum($values) / count($values));
    }

    /** @param list<int> $values */
    private static function percentile(array $values, float $percentile): int
    {
        if ($values === []) {
            return 0;
        }
        sort($values, SORT_NUMERIC);
        $index = (int) ceil(count($values) * $percentile) - 1;

        return $values[max(0, min($index, count($values) - 1))];
    }

    private static function milliseconds(int $nanoseconds): float
    {
        return $nanoseconds / 1_000_000;
    }

    /** @param list<int> $completions */
    private function ticksPerSecond(array $completions): float
    {
        $count = count($completions);
        if ($count === 0) {
            return 0.0;
        }
        if ($count === 1) {
            return (float) $this->targetTicksPerSecond;
        }
        $elapsed = $completions[$count - 1] - $completions[0];
        if ($elapsed <= 0) {
            return (float) $this->targetTicksPerSecond;
        }

        return min((float) $this->targetTicksPerSecond, (($count - 1) * 1_000_000_000) / $elapsed);
    }

    /** @param list<int> $completions */
    private function minimumTicksPerSecond(array $completions): float
    {
        if (count($completions) < 2) {
            return $completions === [] ? 0.0 : (float) $this->targetTicksPerSecond;
        }
        $slowestInterval = 0;
        for ($index = 1, $count = count($completions); $index < $count; ++$index) {
            $slowestInterval = max($slowestInterval, $completions[$index] - $completions[$index - 1]);
        }
        if ($slowestInterval <= 0) {
            return (float) $this->targetTicksPerSecond;
        }

        return min((float) $this->targetTicksPerSecond, 1_000_000_000 / $slowestInterval);
    }

    private static function saturatingAdd(int $left, int $right): int
    {
        return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
    }

    private static function now(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new \RuntimeException('Monotonic clock is unavailable.');
        }

        return $now;
    }
}
