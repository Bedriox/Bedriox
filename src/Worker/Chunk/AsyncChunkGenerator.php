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

namespace Bedriox\Server\Worker\Chunk;

use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generator\GeneratorOptions;
use Bedriox\Server\World\Generator\WorkerGeneratorSource;
use Bedriox\Server\World\WorldGeneratorFactory;
use Closure;
use Throwable;

/** Main-process coordinator for deduplicated worker generation requests. */
final class AsyncChunkGenerator
{
    /** @var array<string, WorkerReceipt> */
    private array $pending = [];
    /** @var array<string, true> */
    private array $failed = [];

    public function __construct(
        private readonly WorkerDispatcher $workers,
        private readonly int $taskTypeId,
        private readonly string $generator,
        private readonly int $generatorVersion,
        private readonly int $seed,
        private readonly BlockStateRegistry $states,
        private readonly int $maximumPending = 1024,
        private readonly GeneratorOptions $options = new GeneratorOptions(),
        private readonly string $dimension = 'minecraft:overworld',
        private readonly ?WorkerGeneratorSource $workerSource = null,
        private readonly bool $allowSynchronousFallback = true,
    ) {
        if ($maximumPending < 1 || $maximumPending > 65_536) {
            throw new \InvalidArgumentException('Async chunk request limit is invalid.');
        }
        WorldGeneratorFactory::canonicalIdentifier($generator);
        if (preg_match('/^[a-z0-9][a-z0-9_.-]{0,31}:[a-z0-9][a-z0-9_.-]{0,63}$/D', $dimension) !== 1) {
            throw new \InvalidArgumentException('Async chunk dimension must be a bounded namespaced identifier.');
        }
    }

    public function isPending(ChunkPosition $position): bool
    {
        return isset($this->pending[$position->key()]);
    }

    /** @param Closure(Chunk): void $completion */
    public function request(ChunkPosition $position, Closure $completion): bool
    {
        $key = $position->key();
        if (isset($this->pending[$key])) {
            return true;
        }
        if (isset($this->failed[$key])) {
            if ($this->allowSynchronousFallback) {
                unset($this->failed[$key]);
            }

            return false;
        }
        if (count($this->pending) >= $this->maximumPending) {
            return false;
        }
        $payload = (new ChunkGenerationRequestCodec())->encode(new ChunkGenerationRequest(
            WorldGeneratorFactory::canonicalIdentifier($this->generator),
            $this->generatorVersion,
            $this->seed,
            $this->dimension,
            $position,
            $this->options,
            $this->workerSource,
        ));
        $submission = $this->workers->submit(
            $this->taskTypeId,
            $payload,
            function (WorkerResult $result) use ($position, $key, $completion): void {
                $receipt = $this->pending[$key] ?? null;
                if ($receipt === null || $receipt->taskId !== $result->receipt->taskId) {
                    return;
                }
                unset($this->pending[$key]);
                if ($result->status !== WorkerResultStatus::SUCCESS) {
                    $this->failed[$key] = true;

                    return;
                }
                try {
                    $chunk = (new ChunkTransferCodec())->decode($result->payload, $this->states);
                    if ($chunk->position->x !== $position->x || $chunk->position->z !== $position->z) {
                        throw new ChunkTransferException('Generated chunk position does not match its request.');
                    }
                    $completion($chunk);
                } catch (Throwable) {
                    $this->failed[$key] = true;
                }
            },
        );
        if ($submission->receipt === null) {
            return false;
        }
        $this->pending[$key] = $submission->receipt;

        return true;
    }

    public function pendingCount(): int
    {
        return count($this->pending);
    }

    public function allowsSynchronousFallback(): bool
    {
        return $this->allowSynchronousFallback;
    }

    public function cancelAll(): void
    {
        foreach ($this->pending as $receipt) {
            $this->workers->cancel($receipt);
        }
        $this->pending = [];
        $this->failed = [];
    }
}
