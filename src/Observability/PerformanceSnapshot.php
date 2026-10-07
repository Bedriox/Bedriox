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

final readonly class PerformanceSnapshot
{
    /** @param array<string, float> $averageSubsystemMilliseconds */
    public function __construct(
        public int $uptimeSeconds,
        public float $currentTps,
        public float $averageTps,
        public float $minimumTps,
        public float $currentMspt,
        public float $averageMspt,
        public float $p95Mspt,
        public float $p99Mspt,
        public float $tickUsagePercent,
        public int $tickSamples,
        public float $averagePollMilliseconds,
        public float $p95PollMilliseconds,
        public int $memoryBytes,
        public int $peakMemoryBytes,
        public int $onlinePlayers,
        public int $maximumPlayers,
        public int $loadedChunks,
        public int $dirtyChunks,
        public int $generatingChunks = 0,
        public int $scheduledPluginTasks = 0,
        public int $deferredPluginTasks = 0,
        public ?WorkerPoolSnapshot $coreWorkers = null,
        public ?WorkerPoolSnapshot $pluginWorkers = null,
        public ?BackgroundLogWriterSnapshot $logWriter = null,
        public array $averageSubsystemMilliseconds = [],
        public float $averageUnclassifiedMilliseconds = 0.0,
        public ?float $networkReceiveBytesPerSecond = null,
        public ?float $networkSendBytesPerSecond = null,
        public int $timerImbalances = 0,
        public int $configuredMemoryLimitBytes = 0,
        public int $worldCount = 0,
        public int $entityCount = 0,
        public int $pendingAsyncPluginTasks = 0,
        public int $maximumAsyncCompletionsPerTick = 0,
        public ?ChunkRepositorySnapshot $chunkCache = null,
        public ?ChunkStreamingSnapshot $chunkStreaming = null,
        public ?PersistenceQueueSnapshot $worldPersistence = null,
        public ?PersistenceQueueSnapshot $playerPersistence = null,
        public ?PreparedChunkCacheSnapshot $preparedChunkCache = null,
        public ?MemoryManagementDecision $memoryManagement = null,
        public ?GarbageCollectionReport $garbageCollection = null,
        public int $garbageCollectorRuns = 0,
        public int $garbageCollectorThreshold = 0,
        public ?ChunkUnloadResult $chunkUnload = null,
        public int $totalChunksUnloaded = 0,
        public int $preparedBytesTrimmed = 0,
        public ?AiSchedulerMetrics $entityAi = null,
        public ?EntityRuntimeMetrics $entityRuntime = null,
        public ?EntityNavigationMetrics $entityNavigation = null,
    ) {}
}
