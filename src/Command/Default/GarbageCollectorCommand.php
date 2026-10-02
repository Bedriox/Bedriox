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
use Bedriox\Server\Command\CommandFeedback;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\World\ChunkUnloadResult;
use Closure;

/** Operator command facade for bounded runtime-owned memory and chunk cleanup. */
final readonly class GarbageCollectorCommand implements BuiltinCommand
{
    /**
     * @param Closure(): GarbageCollectionStatus $status
     * @param Closure(): GarbageCollectionReport $collect
     * @param Closure(): ChunkUnloadResult $unloadChunks
     */
    public function __construct(
        private Closure $status,
        private Closure $collect,
        private Closure $unloadChunks,
    ) {}

    public function definition(): CommandDefinition
    {
        return new CommandDefinition(
            'gc',
            'Shows or runs bounded memory and chunk cleanup.',
            permission: 'bedriox.command.gc',
        );
    }

    public function defineArguments(): CommandArguments
    {
        return CommandArguments::create()
            ->addArgument(CommandParameter::choice('operation', ['status', 'run', 'chunks'])->optional(default: 'status'));
    }

    public function execute(CommandContext $context): CommandResult
    {
        return match ($context->values()->choice('operation')) {
            'status' => $this->showStatus($context->sender()),
            'run' => $this->runCollection($context->sender()),
            'chunks' => $this->unloadChunks($context->sender()),
            default => CommandResult::failure('Unknown garbage collection operation.'),
        };
    }

    private function showStatus(CommandSender $sender): CommandResult
    {
        $status = ($this->status)();
        self::send($sender, TextFormat::GOLD, '--------- Bedriox Garbage Collection ---------');
        self::send($sender, TextFormat::YELLOW, sprintf(
            'Memory: %s used, %s allocated, %s peak, %s limit (%.1f%%)',
            self::bytes($status->memory->usedBytes),
            self::bytes($status->memory->allocatedBytes),
            self::bytes($status->memory->peakAllocatedBytes),
            $status->memory->limitBytes === 0 ? 'unlimited' : self::bytes($status->memory->limitBytes),
            $status->memory->utilizationPercent(),
        ));
        self::send($sender, TextFormat::AQUA, sprintf(
            'Collector: %s pressure, %d runs, %d-root threshold',
            strtolower($status->pressure->name),
            $status->collectorRuns,
            $status->collectorThreshold,
        ));
        if ($status->lastCollection === null) {
            self::send($sender, TextFormat::GRAY, 'Last collection: none');
        } else {
            self::send($sender, TextFormat::GRAY, sprintf(
                'Last collection: %d cycles, %s allocator memory, %.2f ms',
                $status->lastCollection->cyclesCollected,
                self::bytes($status->lastCollection->allocatorBytesReleased),
                $status->lastCollection->durationNanoseconds / 1_000_000,
            ));
        }
        self::send($sender, TextFormat::GREEN, sprintf(
            'Chunks: %d loaded, %d retained, %d dirty, %d queued for unload, %d unloaded total',
            $status->loadedChunks,
            $status->retainedChunks,
            $status->dirtyChunks,
            $status->queuedChunkUnloads,
            $status->chunksUnloaded,
        ));
        self::send($sender, TextFormat::GOLD, '--------- End Garbage Collection ---------');

        return CommandResult::success();
    }

    private function runCollection(CommandSender $sender): CommandResult
    {
        $result = ($this->collect)();
        self::send($sender, TextFormat::GOLD, '--------- Garbage Collection Result ---------');
        self::send($sender, TextFormat::GREEN, sprintf('Cycles collected: %d', $result->cyclesCollected));
        self::send($sender, TextFormat::GREEN, sprintf(
            'Allocator memory released: %s',
            self::bytes($result->allocatorBytesReleased),
        ));
        self::send($sender, TextFormat::AQUA, sprintf(
            'Roots: %d before, %d after; threshold: %d to %d',
            $result->rootsBefore,
            $result->rootsAfter,
            $result->thresholdBefore,
            $result->thresholdAfter,
        ));
        self::send($sender, TextFormat::GRAY, sprintf(
            'Collection time: %.2f ms; allocator cache cleanup: %s',
            $result->durationNanoseconds / 1_000_000,
            $result->allocatorCachesReleased ? 'completed' : 'not requested',
        ));
        self::send($sender, TextFormat::GOLD, '--------- End Garbage Collection ---------');

        return CommandResult::success();
    }

    private function unloadChunks(CommandSender $sender): CommandResult
    {
        $result = ($this->unloadChunks)();
        self::send($sender, TextFormat::GOLD, '--------- Chunk Cleanup Result ---------');
        self::send($sender, TextFormat::GREEN, sprintf(
            'Chunks: %d examined, %d unloaded, %d saves queued',
            $result->examined,
            $result->evicted,
            $result->saveSubmissions,
        ));
        self::send($sender, TextFormat::AQUA, sprintf('Unload queue remaining: %d', $result->remainingQueued));
        if ($result->persistenceSaturated) {
            self::send($sender, TextFormat::YELLOW, 'Persistence is saturated; dirty chunks remain queued safely.');
        }
        self::send($sender, TextFormat::GOLD, '--------- End Chunk Cleanup ---------');

        return CommandResult::success();
    }

    private static function send(CommandSender $sender, string $color, string $message): void
    {
        $sender->sendMessage(CommandFeedback::line($sender, $color, $message));
    }

    private static function bytes(int $bytes): string
    {
        if ($bytes < 1_024) {
            return $bytes . ' B';
        }
        if ($bytes < 1_048_576) {
            return sprintf('%.1f KiB', $bytes / 1_024);
        }

        return sprintf('%.1f MiB', $bytes / 1_048_576);
    }
}
