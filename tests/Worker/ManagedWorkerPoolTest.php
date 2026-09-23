<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Worker;

use Bedriox\Server\Worker\CoreWorkerTaskCatalog;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\WorkerLimits;
use Bedriox\Server\Worker\WorkerRejectionReason;
use Bedriox\Server\Worker\WorkerResultStatus;
use PHPUnit\Framework\TestCase;

final class ManagedWorkerPoolTest extends TestCase
{
    public function testZeroWorkerModeIsAnExplicitNonblockingFallbackBoundary(): void
    {
        $pool = ManagedWorkerPool::start('test', 0);
        $submission = $pool->submit(CoreWorkerTaskCatalog::SELF_TEST, 'payload');

        self::assertFalse($submission->isAccepted());
        self::assertSame(WorkerRejectionReason::DISABLED, $submission->rejection);
        self::assertFalse($pool->snapshot()->available);
        $pool->shutdown();
    }

    public function testLongLivedBrokerExecutesSeveralTasksAndShutsDown(): void
    {
        $pool = ManagedWorkerPool::start('test-suite', 2);
        try {
            $expected = [];
            for ($index = 0; $index < 8; ++$index) {
                $payload = 'payload-' . $index;
                $submission = $pool->submit(CoreWorkerTaskCatalog::SELF_TEST, $payload);
                self::assertTrue($submission->isAccepted());
                self::assertNotNull($submission->receipt);
                $expected[$submission->receipt->taskId] = hash('sha256', $payload, true);
            }

            $actual = [];
            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $started = hrtime(true);
                $pool->poll();
                self::assertLessThan(100_000_000, hrtime(true) - $started);
                foreach ($pool->takeResults() as $result) {
                    self::assertSame(WorkerResultStatus::SUCCESS, $result->status, $pool->diagnostic());
                    $actual[$result->receipt->taskId] = $result->payload;
                }
                if (count($actual) === count($expected)) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            ksort($expected);
            ksort($actual);
            self::assertSame($expected, $actual, $pool->diagnostic());
            self::assertSame(0, $pool->snapshot()->pendingTasks);
            self::assertSame(8, $pool->snapshot()->completed);
        } finally {
            $pool->shutdown();
        }
        self::assertFalse($pool->snapshot()->available);
    }

    public function testResultCollectedAfterItsReceiptDeadlineBecomesATimeout(): void
    {
        $pool = ManagedWorkerPool::start('deadline-test', 1);
        try {
            $submission = $pool->submit(
                CoreWorkerTaskCatalog::SELF_TEST,
                'late-result',
                hrtime(true) + 1_000_000_000,
            );
            self::assertTrue($submission->isAccepted());
            $pool->poll();
            usleep(1_100_000);

            $results = $this->awaitResults($pool, 1);
            self::assertCount(1, $results);
            self::assertSame(WorkerResultStatus::TIMED_OUT, $results[0]->status);
            self::assertSame('deadline', $results[0]->failureCode);
            self::assertSame('', $results[0]->payload);
            self::assertSame(0, $pool->snapshot()->pendingTasks);
            self::assertSame(1, $pool->snapshot()->timedOut);
            self::assertSame(0, $pool->snapshot()->completed);
        } finally {
            $pool->shutdown();
        }
    }

    public function testReadyResultBoundBackpressuresBufferedCompletionsWithoutLosingReceipts(): void
    {
        $pool = ManagedWorkerPool::start(
            'ready-bound-test',
            2,
            limits: new WorkerLimits(maximumQueuedTasks: 8, maximumReadyResults: 4),
        );
        try {
            for ($index = 0; $index < 8; ++$index) {
                self::assertTrue($pool->submit(CoreWorkerTaskCatalog::SELF_TEST, 'bounded-' . $index)->isAccepted());
            }
            self::assertSame(
                WorkerRejectionReason::TASK_LIMIT,
                $pool->submit(CoreWorkerTaskCatalog::SELF_TEST, 'overflow')->rejection,
            );

            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $pool->poll();
                if ($pool->snapshot()->readyResults === 4) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertSame(4, $pool->snapshot()->readyResults);
            self::assertSame(4, $pool->snapshot()->pendingTasks);
            self::assertCount(4, $pool->takeResults(4));

            $remaining = $this->awaitResults($pool, 4);
            self::assertCount(4, $remaining);
            self::assertSame(0, $pool->snapshot()->pendingTasks);
            self::assertSame(0, $pool->snapshot()->readyResults);
            self::assertSame(8, $pool->snapshot()->completed);
        } finally {
            $pool->shutdown();
        }
    }

    public function testReadyByteBoundKeepsTheNextCompletionPendingUntilCapacityIsReleased(): void
    {
        $pool = ManagedWorkerPool::start(
            'ready-byte-test',
            1,
            limits: new WorkerLimits(
                maximumQueuedTasks: 4,
                maximumResultBytes: 32,
                maximumReadyResults: 4,
                maximumReadyBytes: 32,
            ),
        );
        try {
            self::assertTrue($pool->submit(CoreWorkerTaskCatalog::SELF_TEST, 'first')->isAccepted());
            self::assertTrue($pool->submit(CoreWorkerTaskCatalog::SELF_TEST, 'second')->isAccepted());

            $deadline = hrtime(true) + 5_000_000_000;
            do {
                $pool->poll();
                if ($pool->snapshot()->readyResults === 1) {
                    break;
                }
                usleep(1_000);
            } while (hrtime(true) < $deadline);

            self::assertSame(1, $pool->snapshot()->readyResults);
            self::assertSame(32, $pool->snapshot()->readyBytes);
            self::assertSame(1, $pool->snapshot()->pendingTasks);
            self::assertCount(1, $pool->takeResults(1));

            $remaining = $this->awaitResults($pool, 1);
            self::assertCount(1, $remaining);
            self::assertSame(0, $pool->snapshot()->pendingTasks);
            self::assertSame(0, $pool->snapshot()->readyBytes);
        } finally {
            $pool->shutdown();
        }
    }

    /** @return list<\Bedriox\Server\Worker\WorkerResult> */
    private function awaitResults(ManagedWorkerPool $pool, int $expected): array
    {
        $results = [];
        $deadline = hrtime(true) + 5_000_000_000;
        do {
            $pool->poll();
            $results = [...$results, ...$pool->takeResults()];
            if (count($results) >= $expected) {
                break;
            }
            usleep(1_000);
        } while (hrtime(true) < $deadline);

        return $results;
    }
}
