<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\Network\BatchCompressionRequest;
use Bedriox\Server\Worker\Network\BatchCompressionRequestCodec;
use Bedriox\Server\Worker\Network\BatchCompressionTask;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\Worker\Network\ManagedCompressionWorkerDispatcher;
use Bedriox\Server\Worker\Network\OrderedOutboundCompressionQueue;
use Bedriox\Server\Worker\Network\OutboundCompressedBatch;
use Bedriox\Server\Worker\Network\OutboundCompressionAdmission;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerRejectionReason;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;
use PHPUnit\Framework\TestCase;

final class OrderedOutboundCompressionQueueTest extends TestCase
{
    public function testOutOfOrderCompletionsReleaseStrictlyFromTheHead(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = self::queue($workers);
        self::assertSame(1, $queue->enqueue('first-batch')->sequence);
        self::assertSame(2, $queue->enqueue('second-batch')->sequence);

        $workers->completeSuccessfully(2);
        self::assertSame([], $queue->takeReady());
        $workers->completeSuccessfully(1);

        $ready = $queue->takeReady();
        self::assertSame([1, 2], array_column($ready, 'sequence'));
        self::assertSame([
            self::synchronous('first-batch'),
            self::synchronous('second-batch'),
        ], array_column($ready, 'payload'));
        self::assertSame(0, $queue->outstandingCount());
        self::assertSame(0, $queue->outstandingBytes());
    }

    public function testWorkerRejectionAndFailureAreRetriedWithoutSynchronousCompression(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = self::queue($workers);
        $workers->rejectNext = true;
        $rejected = $queue->enqueue('rejected');
        self::assertFalse($rejected->synchronousFallback);

        $failed = $queue->enqueue('failed');
        self::assertFalse($failed->synchronousFallback);
        $workers->complete(2, WorkerResultStatus::BROKER_LOST, 'not-used');
        self::assertSame([], $queue->takeReady());
        $workers->completeSuccessfully(3);
        $first = $queue->takeReady();
        self::assertCount(1, $first);
        $firstResult = array_shift($first);
        self::assertInstanceOf(OutboundCompressedBatch::class, $firstResult);
        self::assertSame(self::synchronous('rejected'), $firstResult->payload);
        $workers->completeSuccessfully(4);

        $second = $queue->takeReady();
        self::assertCount(1, $second);
        $secondResult = array_shift($second);
        self::assertInstanceOf(OutboundCompressedBatch::class, $secondResult);
        self::assertSame(self::synchronous('failed'), $secondResult->payload);
        self::assertFalse($firstResult->synchronousFallback);
        self::assertFalse($secondResult->synchronousFallback);
    }

    public function testMalformedAndOversizedWorkerResultsAreRetried(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = self::queue($workers);
        $queue->enqueue('malformed');
        $queue->enqueue('oversized');
        $workers->complete(1, WorkerResultStatus::SUCCESS, 'wrong marker');
        $workers->complete(2, WorkerResultStatus::SUCCESS, "\xfe" . str_repeat('x', 1_025));
        self::assertSame([], $queue->takeReady());
        $workers->completeSuccessfully(3);
        $workers->completeSuccessfully(4);
        $results = $queue->takeReady();

        self::assertSame([false, false], array_column($results, 'synchronousFallback'));
        self::assertSame(
            [self::synchronous('malformed'), self::synchronous('oversized')],
            array_column($results, 'payload'),
        );
    }

    public function testTaskAndByteSaturationApplyBackpressureWithoutConsumingSequence(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = self::queue($workers, maximumTasks: 2, maximumBytes: 100);
        self::assertSame(OutboundCompressionAdmission::ACCEPTED, $queue->enqueue('a')->admission);
        self::assertSame(OutboundCompressionAdmission::ACCEPTED, $queue->enqueue('b')->admission);
        self::assertSame(OutboundCompressionAdmission::SATURATED, $queue->enqueue('c')->admission);
        self::assertSame(2, $queue->outstandingCount());

        $workers->completeSuccessfully(1);
        $workers->completeSuccessfully(2);
        self::assertCount(2, $queue->takeReady());
        self::assertSame(3, $queue->enqueue('after-release')->sequence);

        $byteBounded = self::queue(new FakeCompressionWorkerDispatcher(), maximumTasks: 8, maximumBytes: 55);
        self::assertTrue($byteBounded->enqueue('a')->isAccepted());
        self::assertSame(OutboundCompressionAdmission::SATURATED, $byteBounded->enqueue('b')->admission);
    }

    public function testGenerationReplacementAndCloseCancelAndIgnoreLateResults(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = self::queue($workers);
        $queue->enqueue('stale-generation');
        $queue->advanceSessionGeneration(2);
        self::assertSame([1], $workers->cancelledTaskIds);
        $workers->completeSuccessfully(1);
        self::assertSame([], $queue->takeReady());

        self::assertSame(2, $queue->enqueue('current-generation')->sequence);
        $workers->completeSuccessfully(2);
        $current = $queue->takeReady();
        self::assertCount(1, $current);
        $currentResult = array_shift($current);
        self::assertInstanceOf(OutboundCompressedBatch::class, $currentResult);
        self::assertSame(2, $currentResult->sessionGeneration);

        $queue->enqueue('closed');
        $queue->close();
        self::assertSame([1, 3], $workers->cancelledTaskIds);
        $workers->completeSuccessfully(3);
        self::assertSame([], $queue->takeReady());
        self::assertSame(OutboundCompressionAdmission::CLOSED, $queue->enqueue('after-close')->admission);
    }

    public function testManagedDispatcherAdapterFallsBackWhenWorkersAreDisabled(): void
    {
        $dispatcher = new ManagedWorkerDispatcher(ManagedWorkerPool::start('compression-test', 0));
        try {
            $queue = new OrderedOutboundCompressionQueue(
                new ManagedCompressionWorkerDispatcher($dispatcher),
                2,
                1,
                CompressionMode::NegotiatedZlib,
                1,
                new BatchLimits(1_024, 2_048, 128, 32, 1_024),
                maximumResultBytes: 1_025,
            );
            $submission = $queue->enqueue('disabled-pool');
            $ready = $queue->takeReady();
            $result = array_shift($ready);

            self::assertTrue($submission->synchronousFallback);
            self::assertInstanceOf(OutboundCompressedBatch::class, $result);
            self::assertSame(self::synchronous('disabled-pool'), $result->payload);
        } finally {
            $dispatcher->shutdown();
        }
    }

    public function testBelowOffloadThresholdUsesSynchronousCompression(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $queue = new OrderedOutboundCompressionQueue(
            $workers,
            2,
            1,
            CompressionMode::NegotiatedZlib,
            1,
            new BatchLimits(1_024, 2_048, 128, 32, 1_024),
            maximumResultBytes: 1_025,
            minimumOffloadBytes: 64,
        );

        $submission = $queue->enqueue('small');
        $ready = $queue->takeReady();

        self::assertTrue($submission->synchronousFallback);
        self::assertSame(0, $workers->submissionCount);
        self::assertCount(1, $ready);
        self::assertSame(self::synchronous('small'), $ready[0]->payload);
    }

    public function testRepeatedWorkerRejectionProducesBoundedTerminalFailure(): void
    {
        $workers = new FakeCompressionWorkerDispatcher();
        $workers->rejectAll = true;
        $queue = self::queue($workers);
        $submission = $queue->enqueue('never-admitted');

        self::assertTrue($submission->isAccepted());
        self::assertSame([], $queue->takeReady());
        $ready = $queue->takeReady();

        self::assertCount(1, $ready);
        $result = array_shift($ready);
        self::assertInstanceOf(OutboundCompressedBatch::class, $result);
        self::assertSame('worker-attempt-limit', $result->failureCode);
        self::assertNull($result->payload);
        self::assertSame(3, $workers->submissionCount);
        self::assertSame(0, $queue->outstandingCount());
    }

    private static function queue(
        FakeCompressionWorkerDispatcher $workers,
        int $maximumTasks = 8,
        int $maximumBytes = 4_096,
    ): OrderedOutboundCompressionQueue {
        return new OrderedOutboundCompressionQueue(
            $workers,
            2,
            1,
            CompressionMode::NegotiatedZlib,
            1,
            new BatchLimits(1_024, 2_048, 128, 32, 1_024),
            $maximumTasks,
            $maximumBytes,
            1_025,
        );
    }

    private static function synchronous(string $batch): string
    {
        $request = new BatchCompressionRequest(
            $batch,
            CompressionMode::NegotiatedZlib,
            1,
            new BatchLimits(1_024, 2_048, 128, 32, 1_024),
        );

        return (new BatchCompressionTask())->execute((new BatchCompressionRequestCodec())->encode($request));
    }
}

final class FakeCompressionWorkerDispatcher implements CompressionWorkerDispatcher
{
    public bool $rejectNext = false;
    public bool $rejectAll = false;
    public int $submissionCount = 0;
    /** @var list<int> */
    public array $cancelledTaskIds = [];
    /** @var array<int, array{WorkerReceipt, string, Closure(WorkerResult): void}> */
    private array $tasks = [];
    private int $nextTaskId = 1;

    public function workerCount(): int
    {
        return 1;
    }

    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        ++$this->submissionCount;
        if ($this->rejectAll || $this->rejectNext) {
            $this->rejectNext = false;
            ++$this->nextTaskId;

            return WorkerSubmission::rejected(WorkerRejectionReason::TASK_LIMIT);
        }
        $receipt = new WorkerReceipt('test', $this->nextTaskId++, $taskTypeId, 'network', $deadlineNanoseconds ?? PHP_INT_MAX);
        $this->tasks[$receipt->taskId] = [$receipt, $payload, $completion];

        return WorkerSubmission::accepted($receipt);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        $this->cancelledTaskIds[] = $receipt->taskId;

        return isset($this->tasks[$receipt->taskId]);
    }

    public function completeSuccessfully(int $taskId): void
    {
        $task = $this->tasks[$taskId];
        $result = (new BatchCompressionTask())->execute($task[1]);
        ($task[2])(new WorkerResult($task[0], WorkerResultStatus::SUCCESS, $result));
    }

    public function complete(int $taskId, WorkerResultStatus $status, string $payload): void
    {
        $task = $this->tasks[$taskId];
        ($task[2])(new WorkerResult($task[0], $status, $payload));
    }
}
