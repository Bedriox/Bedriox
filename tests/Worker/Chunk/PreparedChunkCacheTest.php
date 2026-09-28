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

namespace Bedriox\Server\Tests\Worker\Chunk;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Worker\Chunk\PreparedChunkAvailability;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Task\PrepareChunkTask;
use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use Closure;
use PHPUnit\Framework\TestCase;

final class PreparedChunkCacheTest extends TestCase
{
    public function testMatchingDemandSharesOneWorkerTaskAndReusesItsCompressedResult(): void
    {
        [$cache, $workers, $chunk] = $this->fixture();

        $first = $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT);
        $second = $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT);
        self::assertSame(PreparedChunkAvailability::PENDING, $first->availability);
        self::assertGreaterThan(0, $first->submittedBytes);
        self::assertSame(PreparedChunkAvailability::PENDING, $second->availability);
        self::assertSame(1, $workers->submissions);

        $workers->complete(1, (new PrepareChunkTask())->execute($workers->payloads[1]));
        $ready = $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT);
        self::assertSame(PreparedChunkAvailability::READY, $ready->availability);
        self::assertNotNull($ready->chunk);
        self::assertTrue($cache->isCurrent($ready->chunk, $chunk, ProtocolVersion::CURRENT));
        self::assertSame(1, $cache->snapshot()->entries);
        self::assertSame(0, $cache->snapshot()->pending);
    }

    public function testAReplacementChunkRevisionInvalidatesCompletedWork(): void
    {
        [$cache, $workers, $chunk, $palette] = $this->fixture();
        $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT);
        $workers->complete(1, (new PrepareChunkTask())->execute($workers->payloads[1]));
        self::assertSame(
            PreparedChunkAvailability::READY,
            $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT)->availability,
        );

        $changed = $chunk->withBlockState(0, 63, 0, $palette->air);
        self::assertSame(
            PreparedChunkAvailability::PENDING,
            $cache->lookupOrRequest($changed, ProtocolVersion::CURRENT)->availability,
        );
        self::assertSame(2, $workers->submissions);
        self::assertSame(1, $cache->snapshot()->invalidations);
        self::assertSame(0, $cache->snapshot()->entries);
    }

    public function testLateCompletionForInvalidatedPendingWorkIsDiscarded(): void
    {
        [$cache, $workers, $chunk, $palette] = $this->fixture();
        self::assertSame(
            PreparedChunkAvailability::PENDING,
            $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT)->availability,
        );

        $changed = $chunk->withBlockState(0, 63, 0, $palette->air);
        self::assertSame(
            PreparedChunkAvailability::PENDING,
            $cache->lookupOrRequest($changed, ProtocolVersion::CURRENT)->availability,
        );
        self::assertSame(2, $workers->submissions);

        $workers->complete(1, (new PrepareChunkTask())->execute($workers->payloads[1]));
        self::assertSame(0, $cache->snapshot()->entries);
        self::assertSame(1, $cache->snapshot()->pending);

        $workers->complete(2, (new PrepareChunkTask())->execute($workers->payloads[2]));
        $ready = $cache->lookupOrRequest($changed, ProtocolVersion::CURRENT);
        self::assertSame(PreparedChunkAvailability::READY, $ready->availability);
        self::assertNotNull($ready->chunk);
        self::assertTrue($cache->isCurrent($ready->chunk, $changed, ProtocolVersion::CURRENT));
        self::assertSame(1, $cache->snapshot()->entries);
        self::assertSame(0, $cache->snapshot()->pending);
    }

    public function testRepeatedWorkerFailureSelectsBoundedSynchronousFallback(): void
    {
        [$cache, $workers, $chunk] = $this->fixture();
        for ($attempt = 1; $attempt <= 3; ++$attempt) {
            self::assertSame(
                PreparedChunkAvailability::PENDING,
                $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT)->availability,
            );
            $workers->fail($attempt);
        }

        self::assertSame(
            PreparedChunkAvailability::SYNCHRONOUS_FALLBACK,
            $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT)->availability,
        );
        self::assertSame(3, $cache->snapshot()->failures);

        $prepared = $cache->retainSynchronous(
            $chunk,
            ProtocolVersion::CURRENT,
            (new PrepareChunkTask())->execute($workers->payloads[3]),
        );
        self::assertTrue($cache->isCurrent($prepared, $chunk, ProtocolVersion::CURRENT));
        self::assertSame(
            PreparedChunkAvailability::READY,
            $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT)->availability,
        );
        self::assertSame(3, $workers->submissions);
    }

    public function testPressureTrimReleasesCompletedEntriesAndCanCancelPendingWork(): void
    {
        [$cache, $workers, $chunk, $palette] = $this->fixture();
        $cache->lookupOrRequest($chunk, ProtocolVersion::CURRENT);
        $workers->complete(1, (new PrepareChunkTask())->execute($workers->payloads[1]));
        self::assertGreaterThan(0, $cache->trim());
        self::assertSame(0, $cache->snapshot()->entries);

        $changed = $chunk->withBlockState(0, 63, 0, $palette->air);
        self::assertSame(
            PreparedChunkAvailability::PENDING,
            $cache->lookupOrRequest($changed, ProtocolVersion::CURRENT)->availability,
        );
        self::assertGreaterThan(0, $cache->snapshot()->pendingBytes);
        self::assertGreaterThan(0, $cache->trim(true));
        self::assertSame(0, $cache->snapshot()->pending);
        self::assertSame(0, $cache->snapshot()->pendingBytes);
    }

    /** @return array{PreparedChunkCache, FakePreparationWorkerDispatcher, \Bedriox\Server\World\Chunk, FixedFlatBlockPalette} */
    private function fixture(): array
    {
        $states = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $workers = new FakePreparationWorkerDispatcher();
        $cache = new PreparedChunkCache(
            $workers,
            5,
            $states,
            str_repeat('a', 32),
            maximumEntries: 8,
            maximumBytes: 4_194_304,
            maximumPending: 8,
            maximumPendingBytes: 4_194_304,
        );
        $chunk = (new FlatWorldGenerator($palette))->generate(new ChunkPosition(2, -1));

        return [$cache, $workers, $chunk, $palette];
    }
}

final class FakePreparationWorkerDispatcher implements WorkerDispatcher
{
    public int $submissions = 0;
    /** @var array<int, string> */
    public array $payloads = [];
    /** @var array<int, Closure(WorkerResult): void> */
    private array $completions = [];
    /** @var array<int, WorkerReceipt> */
    private array $receipts = [];

    public function submit(int $taskTypeId, string $payload, Closure $completion, ?int $deadlineNanoseconds = null): WorkerSubmission
    {
        $taskId = ++$this->submissions;
        $receipt = new WorkerReceipt(str_repeat('p', 16), $taskId, $taskTypeId, 'chunk-preparation', hrtime(true) + 1_000_000_000);
        $this->payloads[$taskId] = $payload;
        $this->completions[$taskId] = $completion;
        $this->receipts[$taskId] = $receipt;

        return WorkerSubmission::accepted($receipt);
    }

    public function complete(int $taskId, string $payload): void
    {
        ($this->completions[$taskId])(new WorkerResult(
            $this->receipts[$taskId],
            WorkerResultStatus::SUCCESS,
            $payload,
        ));
    }

    public function fail(int $taskId): void
    {
        ($this->completions[$taskId])(new WorkerResult(
            $this->receipts[$taskId],
            WorkerResultStatus::FAILED,
            failureCode: 'test-failure',
        ));
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return true;
    }

    public function poll(int $maximumCompletions = 256): void {}

    public function snapshot(): WorkerPoolSnapshot
    {
        return new WorkerPoolSnapshot('', 1, 0, 0, 0, 0, $this->submissions, 0, 0, 0, 0, 0, 0, true);
    }

    public function shutdown(): void {}
}
