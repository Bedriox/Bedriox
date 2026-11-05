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

namespace Bedriox\Server\Observability\Profiling;

use Bedriox\Server\BuildInfo;
use Bedriox\Server\Observability\PerformanceSnapshot;
use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Closure;
use RuntimeException;

/** Optional bounded profiler layered on the always-on performance monitor. */
final class PerformanceProfiler
{
    public const int MAXIMUM_DURATION_SECONDS = 1_800;
    private const int MAXIMUM_RECORDS = 2_048;
    private const int MAXIMUM_SLOW_TICKS = 128;
    private const int SLOW_TICK_NANOSECONDS = 50_000_000;

    private ?int $startedAt = null;
    private int $durationSeconds = 0;
    private int $lastSampleSecond = -1;
    /** @var list<array{second: int, tps: float, mspt: float, memory_bytes: int, players: int, entities: int, loaded_chunks: int, worker_queue: int, network_receive_bps: int, network_send_bps: int, subsystems_ms: array<string, float>}> */
    private array $samples = [];
    /** @var array<string, array{category: string, name: string, owner: string, count: int, active_ticks: int, violations: int, total_ns: int, self_ns: int, maximum_ns: int, histogram: list<int>}> */
    private array $records = [];
    /** @var list<array{second: int, duration_ms: float, _contributors: list<array{record_id: int, duration_ms: float, _key: string}>}> */
    private array $slowTicks = [];
    /** @var list<array{record_id: int, duration_ms: float, _key: string}> */
    private array $currentTickContributors = [];
    private ?string $lastReport = null;
    private bool $uploadPending = false;
    /** @var array<string, mixed> */
    private array $diagnostics = [];

    /** @param array<string, int|string> $safeSettings */
    public function __construct(
        private readonly string $reportDirectory,
        private readonly WorkerDispatcher $workers,
        private readonly bool $uploadsEnabled,
        private readonly array $safeSettings,
        private readonly string $uploadEndpoint = 'https://timings.bedriox.com/api/v1/reports',
        private readonly int $maximumDurationSeconds = self::MAXIMUM_DURATION_SECONDS,
        private readonly bool $includePluginIdentities = true,
        private readonly int $localRetentionDays = 14,
    ) {}

    public function start(int $durationSeconds): void
    {
        if ($durationSeconds < 1 || $durationSeconds > $this->maximumDurationSeconds) {
            throw new \InvalidArgumentException(sprintf(
                'Profile duration must be between 1 and %d seconds.',
                $this->maximumDurationSeconds,
            ));
        }
        $this->startedAt = self::now();
        $this->durationSeconds = $durationSeconds;
        $this->lastSampleSecond = -1;
        $this->samples = [];
        $this->records = [];
        $this->slowTicks = [];
        $this->currentTickContributors = [];
        $this->diagnostics = [];
        $this->lastReport = null;
    }

    public function active(): bool
    {
        return $this->startedAt !== null;
    }

    public function elapsedSeconds(): int
    {
        return $this->startedAt === null ? 0 : intdiv(max(0, self::now() - $this->startedAt), 1_000_000_000);
    }

    public function configuredDurationSeconds(): int
    {
        return $this->durationSeconds;
    }

    public function reset(): void
    {
        $this->startedAt = null;
        $this->durationSeconds = 0;
        $this->lastSampleSecond = -1;
        $this->samples = [];
        $this->records = [];
        $this->slowTicks = [];
        $this->currentTickContributors = [];
        $this->diagnostics = [];
        $this->lastReport = null;
    }

    public function record(
        string $category,
        string $name,
        string $owner,
        int $totalNanoseconds,
        int $selfNanoseconds,
        int $budgetNanoseconds = 0,
    ): void {
        if (!$this->active() || $totalNanoseconds < 0 || $selfNanoseconds < 0) {
            return;
        }
        $category = self::boundedIdentifier($category, 48);
        $name = self::boundedIdentifier($name, 160);
        $owner = $this->includePluginIdentities ? self::boundedText($owner, 160) : ($owner === '' ? '' : 'plugin');
        $key = $category . "\0" . $name . "\0" . $owner;
        if (!isset($this->records[$key])) {
            if (count($this->records) >= self::MAXIMUM_RECORDS) {
                $key = "other\0other\0";
            }
            $this->records[$key] ??= [
                'category' => $key === "other\0other\0" ? 'other' : $category,
                'name' => $key === "other\0other\0" ? 'Other bounded workloads' : $name,
                'owner' => $key === "other\0other\0" ? '' : $owner,
                'count' => 0,
                'active_ticks' => 0,
                'violations' => 0,
                'total_ns' => 0,
                'self_ns' => 0,
                'maximum_ns' => 0,
                'histogram' => array_fill(0, 12, 0),
            ];
        }
        $record = &$this->records[$key];
        ++$record['count'];
        ++$record['active_ticks'];
        $record['total_ns'] = self::add($record['total_ns'], $totalNanoseconds);
        $record['self_ns'] = self::add($record['self_ns'], min($selfNanoseconds, $totalNanoseconds));
        $record['maximum_ns'] = max($record['maximum_ns'], $totalNanoseconds);
        if ($budgetNanoseconds > 0 && $totalNanoseconds > $budgetNanoseconds) {
            ++$record['violations'];
        }
        ++$record['histogram'][self::histogramBucket($totalNanoseconds)];
        $this->currentTickContributors[] = [
            'record_id' => 0,
            'duration_ms' => $totalNanoseconds / 1_000_000,
            '_key' => $key,
        ];
        unset($record);
    }

    public function completeTick(int $durationNanoseconds): void
    {
        if (!$this->active()) {
            return;
        }
        $this->record('tick', 'Server tick', '', $durationNanoseconds, $durationNanoseconds, self::SLOW_TICK_NANOSECONDS);
        if ($durationNanoseconds >= self::SLOW_TICK_NANOSECONDS && count($this->slowTicks) < self::MAXIMUM_SLOW_TICKS) {
            $contributors = $this->currentTickContributors;
            usort($contributors, static fn(array $left, array $right): int => $right['duration_ms'] <=> $left['duration_ms']);
            $this->slowTicks[] = [
                'second' => $this->elapsedSeconds(),
                'duration_ms' => $durationNanoseconds / 1_000_000,
                '_contributors' => array_slice($contributors, 0, 8),
            ];
        }
        $this->currentTickContributors = [];
    }

    /** @param array<string, int> $worldStagesMicroseconds */
    public function sample(PerformanceSnapshot $snapshot, array $worldStagesMicroseconds): void
    {
        if (!$this->active()) {
            return;
        }
        $second = $this->elapsedSeconds();
        if ($second === $this->lastSampleSecond) {
            return;
        }
        $this->lastSampleSecond = $second;
        $subsystems = [];
        foreach ($snapshot->averageSubsystemMilliseconds as $name => $milliseconds) {
            $subsystems[self::boundedIdentifier($name, 64)] = max(0.0, $milliseconds);
        }
        foreach ($worldStagesMicroseconds as $name => $microseconds) {
            if ($microseconds >= 0 && str_ends_with($name, '_us')) {
                $subsystems['world.' . self::boundedIdentifier($name, 64)] = $microseconds / 1_000;
            }
        }
        $coreWorkers = $snapshot->coreWorkers;
        $pluginWorkers = $snapshot->pluginWorkers;
        $this->diagnostics = self::diagnostics($snapshot);
        $this->samples[] = [
            'second' => $second,
            'tps' => $snapshot->currentTps,
            'mspt' => $snapshot->currentMspt,
            'memory_bytes' => $snapshot->memoryBytes,
            'players' => $snapshot->onlinePlayers,
            'entities' => $snapshot->entityCount,
            'loaded_chunks' => $snapshot->loadedChunks,
            'worker_queue' => ($coreWorkers === null ? 0 : $coreWorkers->pendingTasks)
                + ($pluginWorkers === null ? 0 : $pluginWorkers->pendingTasks),
            'network_receive_bps' => (int) round($snapshot->networkReceiveBytesPerSecond ?? 0),
            'network_send_bps' => (int) round($snapshot->networkSendBytesPerSecond ?? 0),
            'subsystems_ms' => $subsystems,
        ];
        if ($second >= $this->durationSeconds) {
            $this->stop();
        }
    }

    public function stop(): string
    {
        if ($this->startedAt === null) {
            if ($this->lastReport === null) {
                throw new RuntimeException('No timing profile is active or available.');
            }

            return $this->lastReport;
        }
        $elapsed = max(1, min(self::MAXIMUM_DURATION_SECONDS, $this->elapsedSeconds()));
        if ($this->samples === []) {
            throw new RuntimeException('The profile has not captured its first one-second sample yet.');
        }
        $this->startedAt = null;
        $report = $this->buildReport($elapsed);
        $directory = $this->reportDirectory;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('The timings report directory could not be created.');
        }
        $this->removeExpiredReports($directory);
        $path = $directory . DIRECTORY_SEPARATOR . gmdate('Y-m-d_H-i-s') . '.json.gz';
        $temporary = $path . '.tmp';
        $encoded = json_encode($report, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $compressed = gzencode($encoded, 6);
        if ($compressed === false || file_put_contents($temporary, $compressed, LOCK_EX) !== strlen($compressed)
            || !rename($temporary, $path)) {
            @unlink($temporary);
            throw new RuntimeException('The timings report could not be written.');
        }
        $this->lastReport = $path;

        return $path;
    }

    public function lastReport(): ?string
    {
        return $this->lastReport;
    }

    /** @param Closure(?string, ?string, ?string): void $completed URL, local link path, error */
    public function paste(Closure $completed): bool
    {
        if (!$this->uploadsEnabled || $this->uploadPending) {
            return false;
        }
        $path = $this->active() ? $this->stop() : $this->lastReport;
        if ($path === null || !is_file($path)) {
            return false;
        }
        $body = file_get_contents($path);
        if (!is_string($body)) {
            return false;
        }
        $payload = (new TimingsUploadTaskCodec())->encodeRequest($this->uploadEndpoint, $body);
        $submission = $this->workers->submit(
            CoreWorkerTaskCatalog::UPLOAD_TIMINGS_REPORT,
            $payload,
            function (WorkerResult $result) use ($completed, $path): void {
                $this->uploadPending = false;
                if ($result->status !== WorkerResultStatus::SUCCESS) {
                    $completed(null, null, 'The timing report upload failed.');
                    return;
                }
                try {
                    $publication = (new TimingsUploadTaskCodec())->decodeResponse($result->payload);
                } catch (\Throwable) {
                    $completed(null, null, 'The timing service returned an invalid response.');
                    return;
                }
                try {
                    $linkPath = $this->savePublishedReport(
                        $path,
                        $publication['id'],
                        $publication['view_url'],
                    );
                    $completed($publication['view_url'], $linkPath, null);
                } catch (\Throwable) {
                    $completed(
                        $publication['view_url'],
                        null,
                        'The timing report was published, but its local link file could not be saved.',
                    );
                }
            },
            self::now() + 15_000_000_000,
        );
        if (!$submission->isAccepted()) {
            return false;
        }
        $this->uploadPending = true;

        return true;
    }

    private function savePublishedReport(string $source, string $id, string $url): string
    {
        if (preg_match('/^[A-Za-z0-9_-]{22}$/D', $id) !== 1 || !is_file($source)) {
            throw new RuntimeException('The published timing report identity is invalid.');
        }
        $directory = $this->reportDirectory . DIRECTORY_SEPARATOR . $id;
        if (!is_dir($directory) && !mkdir($directory, 0o700, true) && !is_dir($directory)) {
            throw new RuntimeException('The published timing report directory could not be created.');
        }
        $reportPath = $directory . DIRECTORY_SEPARATOR . 'report.json.gz';
        if (!rename($source, $reportPath)) {
            throw new RuntimeException('The published timing report could not be organized locally.');
        }
        $this->lastReport = $reportPath;
        $linkPath = $directory . DIRECTORY_SEPARATOR . 'link.txt';
        $temporary = $linkPath . '.tmp';
        $contents = $url . PHP_EOL;
        if (file_put_contents($temporary, $contents, LOCK_EX) !== strlen($contents)
            || !rename($temporary, $linkPath)) {
            @unlink($temporary);
            throw new RuntimeException('The published timing report link could not be written.');
        }
        return $linkPath;
    }

    /** @return array<string, mixed> */
    private function buildReport(int $duration): array
    {
        $samples = $this->samples;
        if ($samples === []) {
            throw new RuntimeException('A timing report requires at least one sample.');
        }
        $records = [];
        $ids = [];
        $id = 1;
        $rootKey = null;
        foreach ($this->records as $key => $record) {
            $ids[$key] = $id;
            $record['id'] = $id++;
            if ($record['category'] === 'tick') {
                $rootKey = $key;
            }
            $records[] = $record;
        }
        if ($rootKey !== null) {
            $rootId = $ids[$rootKey];
            foreach ($records as &$record) {
                if ($record['id'] !== $rootId) {
                    $record['parent_id'] = $rootId;
                }
            }
            unset($record);
        }
        $slowTicks = [];
        foreach ($this->slowTicks as $tick) {
            $contributors = [];
            foreach ($tick['_contributors'] as $contributor) {
                $recordId = $ids[$contributor['_key']] ?? null;
                if ($recordId !== null) {
                    $contributors[] = ['record_id' => $recordId, 'duration_ms' => $contributor['duration_ms']];
                }
            }
            $slowTicks[] = ['second' => $tick['second'], 'duration_ms' => $tick['duration_ms'], 'contributors' => $contributors];
        }
        $tps = array_map(static fn(array $sample): float => $sample['tps'], $samples);
        $mspt = array_map(static fn(array $sample): float => $sample['mspt'], $samples);
        sort($mspt, SORT_NUMERIC);
        $peakMemory = max(array_map(static fn(array $sample): int => $sample['memory_bytes'], $samples));

        return [
            'schema' => 'bedriox.timings/3',
            'generated_at' => gmdate('c'),
            'duration_seconds' => $duration,
            'server' => [
                'version' => BuildInfo::current()->serverVersion,
                'php_version' => PHP_VERSION,
                'operating_system' => PHP_OS_FAMILY,
                'architecture' => PHP_INT_SIZE === 8 ? '64-bit' : '32-bit',
                'logical_cores' => max(1, (int) (getenv('NUMBER_OF_PROCESSORS') ?: 1)),
                'memory_limit_bytes' => (int) ($this->safeSettings['memory_limit_bytes'] ?? 0),
            ],
            'summary' => [
                'average_tps' => array_sum($tps) / count($tps),
                'minimum_tps' => min($tps),
                'average_mspt' => array_sum($mspt) / count($mspt),
                'p95_mspt' => self::percentile($mspt, 0.95),
                'p99_mspt' => self::percentile($mspt, 0.99),
                'peak_memory_bytes' => $peakMemory,
                'maximum_players' => max(array_map(static fn(array $sample): int => $sample['players'], $samples)),
                'maximum_entities' => max(array_map(static fn(array $sample): int => $sample['entities'], $samples)),
                'slow_tick_count' => count($this->slowTicks),
            ],
            'samples' => $samples,
            'records' => $records,
            'slow_ticks' => $slowTicks,
            'settings' => [
                'view_distance' => (int) ($this->safeSettings['view_distance'] ?? 0),
                'simulation_distance' => (int) ($this->safeSettings['simulation_distance'] ?? 0),
                'memory_limit' => (string) ($this->safeSettings['memory_limit'] ?? 'unlimited'),
                'network_profile' => (string) ($this->safeSettings['network_profile'] ?? 'unknown'),
                'core_workers' => (int) ($this->safeSettings['core_workers'] ?? 0),
                'plugin_workers' => (int) ($this->safeSettings['plugin_workers'] ?? 0),
            ],
            'diagnostics' => $this->diagnostics,
        ];
    }

    /** @return array<string, mixed> */
    private static function diagnostics(PerformanceSnapshot $snapshot): array
    {
        $entities = [];
        if ($snapshot->entityAi !== null) {
            $entities[] = [
                'type' => 'minecraft:all', 'stage' => 'ai', 'active' => $snapshot->entityAi->active,
                'ticked' => $snapshot->entityAi->ticked, 'deferred' => $snapshot->entityAi->reduced + $snapshot->entityAi->sleeping,
                'total_ns' => $snapshot->entityAi->elapsedNanoseconds, 'maximum_ns' => $snapshot->entityAi->elapsedNanoseconds,
                'budget_hits' => $snapshot->entityAi->budgetExhausted ? 1 : 0, 'slow_tick_share' => 0.0,
            ];
        }
        if ($snapshot->entityRuntime !== null) {
            $entities[] = [
                'type' => 'minecraft:all', 'stage' => 'physics', 'active' => $snapshot->entityRuntime->entities,
                'ticked' => $snapshot->entityRuntime->physicsTicked, 'deferred' => $snapshot->entityRuntime->budgetDeferred,
                'total_ns' => $snapshot->entityRuntime->elapsedNanoseconds, 'maximum_ns' => $snapshot->entityRuntime->elapsedNanoseconds,
                'budget_hits' => $snapshot->entityRuntime->budgetExhausted ? 1 : 0, 'slow_tick_share' => 0.0,
            ];
        }
        $navigation = $snapshot->entityNavigation;
        $queues = [];
        foreach ([['core-workers', $snapshot->coreWorkers], ['plugin-workers', $snapshot->pluginWorkers]] as [$name, $pool]) {
            if ($pool !== null) {
                $queues[] = ['name' => $name, 'current' => $pool->pendingTasks, 'peak' => $pool->pendingTasks,
                    'saturated_samples' => $pool->pendingTasks > $pool->workerCount ? 1 : 0, 'wait_ns' => 0, 'failed' => $pool->failed];
            }
        }

        $workerPools = [];
        foreach ([['core-workers', $snapshot->coreWorkers], ['plugin-workers', $snapshot->pluginWorkers]] as [$name, $pool]) {
            if ($pool === null) {
                continue;
            }
            $workerPools[] = [
                'name' => $name,
                'workers' => $pool->workerCount,
                'busy' => $pool->busyWorkers,
                'pending' => $pool->pendingTasks,
                'pending_bytes' => $pool->pendingBytes,
                'ready_results' => $pool->readyResults,
                'ready_bytes' => $pool->readyBytes,
                'submitted' => $pool->submitted,
                'completed' => $pool->completed,
                'rejected' => $pool->rejected,
                'cancelled' => $pool->cancelled,
                'timed_out' => $pool->timedOut,
                'failed' => $pool->failed,
                'restarts' => $pool->restarts,
                'broker_memory_bytes' => $pool->brokerMemoryBytes,
                'worker_memory_bytes' => $pool->workerMemoryBytes,
            ];
        }

        $pluginQueues = [];
        if ($snapshot->pluginAsyncQueue !== null) {
            $queuedByPlugin = $snapshot->pluginAsyncQueue->queuedTasksByPlugin;
            arsort($queuedByPlugin, SORT_NUMERIC);
            foreach (array_slice($queuedByPlugin, 0, 127, true) as $plugin => $queued) {
                $pluginQueues[] = [
                    'plugin' => self::boundedText($plugin, 160),
                    'queued' => max(0, $queued),
                    'running' => 0,
                    'completed' => 0,
                    'failed' => 0,
                    'rejected' => 0,
                    'expired' => 0,
                    'oldest_wait_ms' => $snapshot->pluginAsyncQueue->oldestWaitMilliseconds,
                    'total_wait_ns' => 0,
                    'maximum_wait_ns' => 0,
                    'total_execution_ns' => 0,
                    'maximum_execution_ns' => 0,
                ];
            }
            if ($snapshot->pluginAsyncQueue->runningTasks > 0 || $snapshot->pluginAsyncQueue->rejectedTasks > 0 || $snapshot->pluginAsyncQueue->expiredTasks > 0) {
                $pluginQueues[] = [
                    'plugin' => 'Unattributed plugin work',
                    'queued' => 0,
                    'running' => $snapshot->pluginAsyncQueue->runningTasks,
                    'completed' => 0,
                    'failed' => 0,
                    'rejected' => $snapshot->pluginAsyncQueue->rejectedTasks,
                    'expired' => $snapshot->pluginAsyncQueue->expiredTasks,
                    'oldest_wait_ms' => $snapshot->pluginAsyncQueue->oldestWaitMilliseconds,
                    'total_wait_ns' => 0,
                    'maximum_wait_ns' => 0,
                    'total_execution_ns' => 0,
                    'maximum_execution_ns' => 0,
                ];
            }
        }

        $chunks = null;
        if ($snapshot->chunkCache !== null || $snapshot->chunkStreaming !== null || $snapshot->preparedChunkCache !== null) {
            $cache = $snapshot->chunkCache;
            $streaming = $snapshot->chunkStreaming;
            $prepared = $snapshot->preparedChunkCache;
            $chunks = [
                'capacity' => $cache === null ? 0 : $cache->capacity,
                'loaded' => $cache === null ? $snapshot->loadedChunks : $cache->loaded,
                'retained' => $cache === null ? 0 : $cache->retainedChunks,
                'dirty' => $cache === null ? $snapshot->dirtyChunks : $cache->dirty,
                'generating' => $snapshot->generatingChunks,
                'cache_hits' => $cache === null ? 0 : $cache->hits,
                'cache_misses' => $cache === null ? 0 : $cache->misses,
                'evictions' => $cache === null ? 0 : $cache->evictions,
                'visible_pending' => $streaming === null ? 0 : $streaming->visiblePending,
                'prefetch_pending' => $streaming === null ? 0 : $streaming->prefetchPending,
                'generation_queued' => $streaming === null ? 0 : $streaming->generationQueued,
                'delivery_queued' => $streaming === null ? 0 : $streaming->deliveryQueued,
                'outgoing_queued' => $streaming === null ? 0 : $streaming->outgoingQueued,
                'prepared_entries' => $prepared === null ? 0 : $prepared->entries,
                'prepared_bytes' => $prepared === null ? 0 : $prepared->bytes,
                'prepared_hits' => $prepared === null ? 0 : $prepared->hits,
                'prepared_misses' => $prepared === null ? 0 : $prepared->misses,
                'prepared_evictions' => $prepared === null ? 0 : $prepared->evictions,
                'prepared_invalidations' => $prepared === null ? 0 : $prepared->invalidations,
                'unloaded' => $snapshot->totalChunksUnloaded,
            ];
        }

        $garbageCollection = $snapshot->garbageCollection;
        $memoryManagement = $snapshot->memoryManagement;
        $memory = [
            'used_bytes' => $snapshot->memoryBytes,
            'peak_bytes' => $snapshot->peakMemoryBytes,
            'limit_bytes' => $snapshot->configuredMemoryLimitBytes,
            'garbage_collections' => $snapshot->garbageCollectorRuns,
            'cycles_collected' => $garbageCollection === null ? 0 : $garbageCollection->cyclesCollected,
            'allocator_bytes_released' => $garbageCollection === null ? 0 : $garbageCollection->allocatorBytesReleased,
            'collection_ns' => $garbageCollection === null ? 0 : $garbageCollection->durationNanoseconds,
            'prepared_bytes_trimmed' => $snapshot->preparedBytesTrimmed,
            'accelerated_unloading' => $memoryManagement === null ? false : $memoryManagement->accelerateChunkUnloading,
            'prefetch_suspended' => $memoryManagement === null ? false : $memoryManagement->suspendPrefetch,
            'generation_paused' => $memoryManagement === null ? false : $memoryManagement->pauseOptionalGeneration,
        ];

        $inbound = $snapshot->inbound;
        $security = $snapshot->transportSecurity;
        $network = $inbound === null && $security === null ? null : [
            'handled' => $inbound === null ? 0 : $inbound->handled,
            'ignored' => $inbound === null ? 0 : $inbound->ignored,
            'corrected' => $inbound === null ? 0 : $inbound->corrected,
            'rejected' => $inbound === null ? 0 : $inbound->rejected,
            'semantic_violations' => $inbound === null ? 0 : $inbound->semanticViolations,
            'protocol_violations' => $inbound === null ? 0 : $inbound->protocolViolations,
            'session_fatals' => $inbound === null ? 0 : $inbound->sessionFatals,
            'active_sessions' => $security === null ? 0 : $security->activeSessions,
            'active_negotiations' => $security === null ? 0 : $security->activeNegotiations,
            'active_address_blocks' => $security === null ? 0 : $security->activeAddressBlocks,
            'flood_blocks' => $security === null ? 0 : $security->negotiationFloodBlocks,
            'session_limit_rejections' => $security === null ? 0 : $security->sessionLimitRejections,
            'blocked_peer_rejections' => $security === null ? 0 : $security->blockedPeerRejections,
        ];

        $persistence = [];
        foreach ([['world', $snapshot->worldPersistence], ['players', $snapshot->playerPersistence]] as [$name, $queue]) {
            if ($queue === null) {
                continue;
            }
            $persistence[] = [
                'name' => $name,
                'queued' => $queue->queued,
                'in_flight' => $queue->inFlight,
                'completions' => $queue->completions,
                'request_bytes' => $queue->requestBytes,
                'coalesced' => $queue->coalesced,
                'saturated' => $queue->saturated,
                'failed' => $queue->failed,
            ];
        }

        return [
            'entity_types' => $entities,
            'navigation' => $navigation === null ? (object) [] : [
                'submitted' => $navigation->submitted, 'completed' => $navigation->completed, 'rejected' => $navigation->rejected,
                'pending' => $navigation->pending, 'cache_hits' => $navigation->cached, 'paths_reused' => $navigation->pathsReused,
                'repaths_suppressed' => $navigation->repathsSuppressed, 'cells_scanned' => $navigation->cellsScanned,
                'preparation_ns' => $navigation->preparationNanoseconds, 'maximum_ns' => $navigation->maximumPreparationNanoseconds,
            ],
            'queues' => $queues,
            'worker_pools' => $workerPools,
            'plugin_queues' => $pluginQueues,
            'chunks' => $chunks,
            'memory' => $memory,
            'network' => $network,
            'persistence' => $persistence,
        ];
    }

    /** @param list<float> $values */
    private static function percentile(array $values, float $percentile): float
    {
        return $values[max(0, min(count($values) - 1, (int) ceil(count($values) * $percentile) - 1))];
    }

    private static function histogramBucket(int $nanoseconds): int
    {
        $microseconds = max(1, intdiv($nanoseconds, 1_000));
        return min(11, (int) floor(log($microseconds, 2)));
    }

    private static function boundedIdentifier(string $value, int $maximum): string
    {
        $value = preg_replace('/[^a-zA-Z0-9._:\/ -]/', '_', trim($value)) ?? '';
        return substr($value === '' ? 'unknown' : $value, 0, $maximum);
    }

    private static function boundedText(string $value, int $maximum): string
    {
        return substr(str_replace(["\r", "\n", "\0"], '', trim($value)), 0, $maximum);
    }

    private static function add(int $left, int $right): int
    {
        return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
    }

    private function removeExpiredReports(string $directory): void
    {
        $threshold = time() - $this->localRetentionDays * 86_400;
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*.json.gz') ?: [] as $path) {
            $modified = filemtime($path);
            if ($modified !== false && $modified < $threshold) {
                @unlink($path);
            }
        }
        foreach (glob($directory . DIRECTORY_SEPARATOR . '*', GLOB_ONLYDIR) ?: [] as $reportDirectory) {
            if (preg_match('/^[A-Za-z0-9_-]{22}$/D', basename($reportDirectory)) !== 1) {
                continue;
            }
            $reportPath = $reportDirectory . DIRECTORY_SEPARATOR . 'report.json.gz';
            $modified = filemtime($reportPath);
            if ($modified === false || $modified >= $threshold) {
                continue;
            }
            @unlink($reportDirectory . DIRECTORY_SEPARATOR . 'link.txt');
            @unlink($reportPath);
            @rmdir($reportDirectory);
        }
    }

    private static function now(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new RuntimeException('Monotonic clock is unavailable.');
        }
        return $now;
    }
}
