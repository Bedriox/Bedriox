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

namespace Bedriox\Server\Command\Default;

use Bedriox\Api\Command\CommandArguments;
use Bedriox\Api\Command\CommandContext;
use Bedriox\Api\Command\CommandDefinition;
use Bedriox\Api\Command\CommandParameter;
use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\TextFormat;
use Bedriox\Server\BuildInfo;
use Bedriox\Server\Command\CommandFeedback;
use Bedriox\Server\Observability\BackgroundLogWriterSnapshot;
use Bedriox\Server\Observability\PerformanceSnapshot;
use Bedriox\Server\Observability\PerformanceSubsystem;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Closure;

final readonly class StatusCommand implements BuiltinCommand
{
    private const string HEADER = '--------- Bedriox Status ---------';
    private const string ADVANCED_HEADER = '--------- Bedriox Status: Advanced ---------';
    private const string FOOTER = '--------- End Status ---------';

    /** @param Closure(): PerformanceSnapshot $snapshot */
    public function __construct(private Closure $snapshot) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'status',
            'Shows server performance and workload information.',
            permission: 'bedriox.command.status',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::choice('detail', ['advanced', 'advance'])->optional());
    }

    public function execute(CommandContext $context): CommandResult
    {
        if (!$context->values()->has('detail')) {
            $this->sendBasic($context->sender(), ($this->snapshot)());

            return CommandResult::success();
        }
        $this->sendAdvanced($context->sender(), ($this->snapshot)());

        return CommandResult::success();
    }

    private function sendBasic(CommandSender $sender, PerformanceSnapshot $status): void
    {
        self::send($sender, TextFormat::GOLD, self::HEADER);
        self::send($sender, TextFormat::GREEN, 'Version: Bedriox ' . BuildInfo::current()->serverVersion);
        self::send($sender, TextFormat::GREEN, 'Uptime: ' . self::duration($status->uptimeSeconds));
        self::send($sender, TextFormat::GREEN, sprintf('Players: %d/%d online', $status->onlinePlayers, $status->maximumPlayers));
        self::send($sender, TextFormat::GREEN, sprintf('TPS: %.2f current, %.2f average', $status->currentTps, $status->averageTps));
        self::send($sender, TextFormat::AQUA, sprintf('MSPT: %.2f current, %.2f average', $status->currentMspt, $status->averageMspt));
        self::send($sender, TextFormat::YELLOW, sprintf(
            'Memory: %s current, %s limit, %s peak',
            self::bytes($status->memoryBytes),
            $status->configuredMemoryLimitBytes === 0 ? 'unlimited' : self::bytes($status->configuredMemoryLimitBytes),
            self::bytes($status->peakMemoryBytes),
        ));
        $network = [];
        if ($status->networkReceiveBytesPerSecond !== null) {
            $network[] = self::rate($status->networkReceiveBytesPerSecond) . ' receive';
        }
        if ($status->networkSendBytesPerSecond !== null) {
            $network[] = self::rate($status->networkSendBytesPerSecond) . ' send';
        }
        if ($network !== []) {
            self::send($sender, TextFormat::AQUA, 'Network: ' . implode(', ', $network));
        }
        if ($status->worldCount > 0) {
            self::send($sender, TextFormat::GREEN, sprintf(
                'Worlds: %d, %d loaded chunks, %d entities',
                $status->worldCount,
                $status->loadedChunks,
                $status->entityCount,
            ));
        }
        self::send($sender, TextFormat::GOLD, self::FOOTER);
    }

    private function sendAdvanced(CommandSender $sender, PerformanceSnapshot $status): void
    {
        self::send($sender, TextFormat::GOLD, self::ADVANCED_HEADER);

        self::section($sender, 'Server');
        self::send($sender, TextFormat::GREEN, 'Version: Bedriox ' . BuildInfo::current()->serverVersion);
        self::send($sender, TextFormat::GREEN, 'Uptime: ' . self::duration($status->uptimeSeconds));
        self::send($sender, TextFormat::GREEN, sprintf('Players: %d/%d online', $status->onlinePlayers, $status->maximumPlayers));

        self::section($sender, 'Tick Performance');
        self::send($sender, TextFormat::GREEN, sprintf('TPS current: %.2f', $status->currentTps));
        self::send($sender, TextFormat::GREEN, sprintf(
            'TPS history: %.2f average, %.2f minimum',
            $status->averageTps,
            $status->minimumTps,
        ));
        self::send($sender, TextFormat::AQUA, sprintf('MSPT current: %.2f', $status->currentMspt));
        self::send($sender, TextFormat::AQUA, sprintf(
            'MSPT history: %.2f average, %.2f p95, %.2f p99',
            $status->averageMspt,
            $status->p95Mspt,
            $status->p99Mspt,
        ));
        self::send($sender, TextFormat::AQUA, sprintf('Tick usage: %.1f%%', $status->tickUsagePercent));
        self::send($sender, TextFormat::GRAY, sprintf('Tick samples: %d/1200', $status->tickSamples));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Runtime polling: %.2f ms average, %.2f ms p95',
            $status->averagePollMilliseconds,
            $status->p95PollMilliseconds,
        ));

        self::section($sender, 'Memory');
        self::send($sender, TextFormat::YELLOW, sprintf(
            'Server memory: %s current, %s limit, %s peak',
            self::bytes($status->memoryBytes),
            $status->configuredMemoryLimitBytes === 0 ? 'unlimited' : self::bytes($status->configuredMemoryLimitBytes),
            self::bytes($status->peakMemoryBytes),
        ));
        self::send($sender, TextFormat::GRAY, 'Worker memory is reported per pool below.');
        if ($status->memoryManagement !== null) {
            self::send($sender, TextFormat::YELLOW, sprintf(
                'Memory pressure: %s (%.1f%% allocated)',
                strtolower($status->memoryManagement->pressure->name),
                $status->memoryManagement->snapshot->utilizationPercent(),
            ));
        } else {
            self::send($sender, TextFormat::GRAY, 'Memory pressure management: disabled or awaiting first tick');
        }
        self::send($sender, TextFormat::GRAY, sprintf(
            'Cyclic GC: %d runs, %d-root adaptive threshold',
            $status->garbageCollectorRuns,
            $status->garbageCollectorThreshold,
        ));
        if ($status->garbageCollection !== null) {
            self::send($sender, TextFormat::GRAY, sprintf(
                'Last GC: %s, %d cycles, %s allocator caches, %.2f ms',
                $status->garbageCollection->collected ? 'collected' : 'skipped',
                $status->garbageCollection->cyclesCollected,
                self::bytes($status->garbageCollection->allocatorBytesReleased),
                $status->garbageCollection->durationNanoseconds / 1_000_000,
            ));
        }
        self::send($sender, TextFormat::GRAY, 'Prepared-cache memory trimmed: ' . self::bytes($status->preparedBytesTrimmed));

        self::section($sender, 'World');
        self::send($sender, TextFormat::GREEN, sprintf(
            'Chunks: %d loaded, %d dirty, %d generating',
            $status->loadedChunks,
            $status->dirtyChunks,
            $status->generatingChunks,
        ));
        self::send($sender, TextFormat::GREEN, sprintf(
            'Worlds: %d, players: %d, entities: %d',
            $status->worldCount,
            $status->onlinePlayers,
            $status->entityCount,
        ));
        if ($status->chunkCache !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Chunk cache: %d/%d loaded, %d retained (%d references)',
                $status->chunkCache->loaded,
                $status->chunkCache->capacity,
                $status->chunkCache->retainedChunks,
                $status->chunkCache->retentionReferences,
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Chunk cache lookups: %d hits, %d misses, %d evictions, %s hit ratio',
                $status->chunkCache->hits,
                $status->chunkCache->misses,
                $status->chunkCache->evictions,
                $status->chunkCache->hitRatio() === null
                    ? 'unavailable'
                    : sprintf('%.1f%%', $status->chunkCache->hitRatio() * 100),
            ));
        }
        if ($status->chunkUnload !== null) {
            self::send($sender, TextFormat::GRAY, sprintf(
                'Chunk unloads: %d queued, %d examined, %d evicted last pass, %d evicted total',
                $status->chunkUnload->remainingQueued,
                $status->chunkUnload->examined,
                $status->chunkUnload->evicted,
                $status->totalChunksUnloaded,
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Unload persistence: %d save submissions, %s',
                $status->chunkUnload->saveSubmissions,
                $status->chunkUnload->persistenceSaturated ? 'saturated' : 'available',
            ));
        }
        if ($status->chunkStreaming !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Chunk streaming: %d visible pending, %d prefetch pending, %d generation queued, %d delivery queued',
                $status->chunkStreaming->visiblePending,
                $status->chunkStreaming->prefetchPending,
                $status->chunkStreaming->generationQueued,
                $status->chunkStreaming->deliveryQueued,
            ));
        }
        if ($status->entityAi !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Entity AI: %d/%d ticked, %d active, %d reduced, %d sleeping',
                $status->entityAi->ticked,
                $status->entityAi->considered,
                $status->entityAi->active,
                $status->entityAi->reduced,
                $status->entityAi->sleeping,
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'AI work: %d sensors, %d goals evaluated, %d goals ticked, %.2f ms%s',
                $status->entityAi->sensorsRun,
                $status->entityAi->goalsEvaluated,
                $status->entityAi->goalsTicked,
                $status->entityAi->elapsedNanoseconds / 1_000_000,
                $status->entityAi->budgetExhausted ? ', budget exhausted' : '',
            ));
        }
        if ($status->entityRuntime !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Entity physics: %d/%d ticked, %d cadence skipped, %d budget deferred',
                $status->entityRuntime->physicsTicked,
                $status->entityRuntime->physicsEligible,
                $status->entityRuntime->cadenceSkipped,
                $status->entityRuntime->budgetDeferred,
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Entity motion: %d moved, %d velocity changes, %.2f ms%s',
                $status->entityRuntime->moved,
                $status->entityRuntime->motionChanged,
                $status->entityRuntime->elapsedNanoseconds / 1_000_000,
                $status->entityRuntime->budgetExhausted
                    ? sprintf(', budget exhausted (%d safety-critical)', $status->entityRuntime->continuousBeyondBudget)
                    : '',
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Entity contacts: %d resolved from %d pairs (%d candidates), %.2f ms%s',
                $status->entityRuntime->contactsResolved,
                $status->entityRuntime->contactPairs,
                $status->entityRuntime->contactCandidates,
                $status->entityRuntime->contactElapsedNanoseconds / 1_000_000,
                $status->entityRuntime->contactBudgetExhausted ? ', budget exhausted' : '',
            ));
        }
        if ($status->entityNavigation !== null) {
            self::send($sender, TextFormat::GRAY, sprintf(
                'Entity navigation: %d submitted, %d completed, %d rejected, %d pending, %d cached',
                $status->entityNavigation->submitted,
                $status->entityNavigation->completed,
                $status->entityNavigation->rejected,
                $status->entityNavigation->pending,
                $status->entityNavigation->cached,
            ));
        }
        if ($status->preparedChunkCache !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Prepared chunks: %d entries (%s), %d pending (%s)',
                $status->preparedChunkCache->entries,
                self::bytes($status->preparedChunkCache->bytes),
                $status->preparedChunkCache->pending,
                self::bytes($status->preparedChunkCache->pendingBytes),
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Prepared chunk lookups: hits %d, misses %d, evictions %d, invalidations %d, failures %d, hit ratio %s',
                $status->preparedChunkCache->hits,
                $status->preparedChunkCache->misses,
                $status->preparedChunkCache->evictions,
                $status->preparedChunkCache->invalidations,
                $status->preparedChunkCache->failures,
                $status->preparedChunkCache->hitRatio() === null
                    ? 'unavailable'
                    : sprintf('%.1f%%', $status->preparedChunkCache->hitRatio() * 100),
            ));
        }

        self::section($sender, 'Workers');
        self::workerDetails($sender, 'Core', $status->coreWorkers);
        $pluginUnavailableState = $status->coreWorkers?->workerCount === 0 ? 'disabled' : 'unavailable';
        self::workerDetails($sender, 'Plugin', $status->pluginWorkers, $pluginUnavailableState);

        self::section($sender, 'Plugin Scheduler');
        self::send($sender, TextFormat::YELLOW, sprintf('Synchronous tasks: %d scheduled', $status->scheduledPluginTasks));
        self::send($sender, TextFormat::YELLOW, sprintf('Deferred last tick: %d', $status->deferredPluginTasks));
        self::send($sender, TextFormat::YELLOW, sprintf(
            'Asynchronous tasks: %d pending, %d completions per tick maximum',
            $status->pendingAsyncPluginTasks,
            $status->maximumAsyncCompletionsPerTick,
        ));

        self::section($sender, 'Network');
        self::send($sender, TextFormat::AQUA, 'Receive payload rate: ' . self::optionalRate($status->networkReceiveBytesPerSecond));
        self::send($sender, TextFormat::AQUA, 'Send payload rate: ' . self::optionalRate($status->networkSendBytesPerSecond));
        self::send($sender, TextFormat::GRAY, $status->chunkStreaming === null
            ? 'Session queues: unavailable'
            : sprintf('Session queues: %d outgoing payloads', $status->chunkStreaming->outgoingQueued));
        if ($status->transportSecurity !== null) {
            self::send($sender, TextFormat::AQUA, sprintf(
                'Admission: %d datagrams (%s), %d dropped, %d malformed',
                $status->transportSecurity->receivedDatagrams,
                self::bytes($status->transportSecurity->receivedBytes),
                $status->transportSecurity->droppedDatagrams,
                $status->transportSecurity->malformedDatagrams,
            ));
            self::send($sender, TextFormat::GRAY, sprintf(
                'Protection: %d active blocks, %d blocks issued, %d rate-limit hits',
                $status->transportSecurity->activeBlocks,
                $status->transportSecurity->temporaryBlocks,
                $status->transportSecurity->rateLimitedEndpoints,
            ));
        } else {
            self::send($sender, TextFormat::GRAY, 'Admission protection metrics: awaiting transport snapshot');
        }

        self::section($sender, 'Average Tick Cost');
        foreach (
            [
                PerformanceSubsystem::TRANSPORT => 'Transport',
                PerformanceSubsystem::SESSIONS => 'Sessions',
                PerformanceSubsystem::PLUGINS => 'Plugins',
                PerformanceSubsystem::WORLD => 'World',
                PerformanceSubsystem::CHUNKS => 'Chunks',
                PerformanceSubsystem::PERSISTENCE => 'Persistence',
                PerformanceSubsystem::WORKERS => 'Workers',
                PerformanceSubsystem::NETWORK_OUTBOUND => 'Network outbound',
            ] as $subsystem => $label
        ) {
            self::send($sender, TextFormat::GRAY, sprintf(
                '%s: %s',
                $label,
                array_key_exists($subsystem, $status->averageSubsystemMilliseconds)
                    ? sprintf('%.2f ms', $status->averageSubsystemMilliseconds[$subsystem])
                    : 'unavailable',
            ));
        }
        self::send($sender, TextFormat::GRAY, sprintf('Unclassified: %.2f ms', $status->averageUnclassifiedMilliseconds));
        self::send($sender, TextFormat::GRAY, sprintf('Timer imbalances: %d', $status->timerImbalances));

        self::section($sender, 'Persistence and Logging');
        self::persistenceDetails($sender, 'World', $status->worldPersistence);
        self::persistenceDetails($sender, 'Player', $status->playerPersistence);
        self::logWriterDetails($sender, $status->logWriter);

        self::send($sender, TextFormat::GOLD, self::FOOTER);
    }

    private static function workerDetails(
        CommandSender $sender,
        string $label,
        ?WorkerPoolSnapshot $workers,
        string $unavailableState = 'unavailable',
    ): void {
        if ($workers === null) {
            self::send($sender, TextFormat::GRAY, $label . ' workers: ' . $unavailableState);

            return;
        }
        $state = match (true) {
            $workers->workerCount === 0 => 'disabled',
            $workers->available => 'online',
            default => 'offline',
        };
        self::send($sender, TextFormat::AQUA, sprintf(
            '%s workers: %s, %d/%d busy',
            $label,
            $state,
            $workers->busyWorkers,
            $workers->workerCount,
        ));
        self::send($sender, TextFormat::AQUA, sprintf(
            '%s queue: %d pending (%s), %d ready (%s)',
            $label,
            $workers->pendingTasks,
            self::bytes($workers->pendingBytes),
            $workers->readyResults,
            self::bytes($workers->readyBytes),
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            '%s totals: %d submitted, %d completed, %d rejected, %d cancelled',
            $label,
            $workers->submitted,
            $workers->completed,
            $workers->rejected,
            $workers->cancelled,
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            '%s failures: %d timed out, %d failed, %d restarts',
            $label,
            $workers->timedOut,
            $workers->failed,
            $workers->restarts,
        ));
        self::send($sender, TextFormat::GRAY, ($workers->brokerMemoryBytes + $workers->workerMemoryBytes) === 0
            ? $label . ' worker memory: awaiting first report'
            : sprintf(
                '%s worker memory: %s compute, %s broker',
                $label,
                self::bytes($workers->workerMemoryBytes),
                self::bytes($workers->brokerMemoryBytes),
            ));
    }

    private static function logWriterDetails(CommandSender $sender, ?BackgroundLogWriterSnapshot $log): void
    {
        if ($log === null) {
            self::send($sender, TextFormat::GRAY, 'Background logging: unavailable');

            return;
        }
        self::send($sender, TextFormat::GRAY, 'Background logging: ' . ($log->available ? 'online' : 'offline'));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Logging queue: %d entries (%s), oldest sequence %s',
            $log->queue->queued,
            self::bytes($log->queue->queuedBytes),
            $log->queue->oldestSequence === null ? 'none' : (string) $log->queue->oldestSequence,
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Logging writer: %s, %d acknowledged',
            $log->inFlight ? 'write in flight' : 'idle',
            $log->acknowledged,
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Logging drops: %d routine, %d high-severity',
            $log->queue->droppedRoutine,
            $log->queue->droppedHighSeverity,
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Logging failures: %d service, %d write',
            $log->serviceFailures,
            $log->queue->writeFailures,
        ));
    }

    private static function persistenceDetails(
        CommandSender $sender,
        string $label,
        ?PersistenceQueueSnapshot $queue,
    ): void {
        if ($queue === null) {
            self::send($sender, TextFormat::GRAY, $label . ' persistence: unavailable');

            return;
        }
        self::send($sender, TextFormat::GRAY, sprintf(
            '%s persistence: %d queued, %d in flight, %d completions (%s)',
            $label,
            $queue->queued,
            $queue->inFlight,
            $queue->completions,
            self::bytes($queue->requestBytes),
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            '%s persistence totals: %d coalesced, %d saturated, %d failed',
            $label,
            $queue->coalesced,
            $queue->saturated,
            $queue->failed,
        ));
    }

    private static function section(CommandSender $sender, string $title): void
    {
        self::send($sender, TextFormat::GOLD, '--------- ' . $title . ' ---------');
    }

    private static function send(CommandSender $sender, string $color, string $message): void
    {
        $sender->sendMessage(CommandFeedback::line($sender, $color, $message));
    }

    private static function duration(int $seconds): string
    {
        $days = intdiv($seconds, 86_400);
        $hours = intdiv($seconds % 86_400, 3_600);
        $minutes = intdiv($seconds % 3_600, 60);
        $remaining = $seconds % 60;

        return sprintf('%dd %02dh %02dm %02ds', $days, $hours, $minutes, $remaining);
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KiB', $bytes / 1_024);
        }

        return sprintf('%.1f MiB', $bytes / 1_048_576);
    }

    private static function optionalRate(?float $bytesPerSecond): string
    {
        return $bytesPerSecond === null ? 'unavailable' : self::rate($bytesPerSecond);
    }

    private static function rate(float $bytesPerSecond): string
    {
        return self::bytes((int) round($bytesPerSecond)) . '/s';
    }
}
