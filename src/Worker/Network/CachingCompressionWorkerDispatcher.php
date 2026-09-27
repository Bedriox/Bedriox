<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;
use Throwable;

/**
 * Coalesces identical compression work and retains bounded successful results.
 *
 * Compression happens before session encryption, so an encoded Bedrock batch is
 * safe to share between sessions while the encrypted envelope remains unique.
 */
final class CachingCompressionWorkerDispatcher implements CompressionWorkerDispatcher
{
    /** @var array<string, array{receipt: WorkerReceipt, waiters: array<int, array{WorkerReceipt, Closure}>}> */
    private array $inFlight = [];

    /** @var array<int, string> logical task ID => content key */
    private array $logicalTasks = [];

    /** @var array<string, array{payload: string, last_used: int}> */
    private array $cache = [];

    private readonly string $epoch;
    private int $nextTaskId = 1;
    private int $cacheBytes = 0;
    private int $usageSequence = 0;

    public function __construct(
        private readonly CompressionWorkerDispatcher $workers,
        private readonly int $maximumEntries = 64,
        private readonly int $maximumBytes = 33_554_432,
        private readonly int $minimumCacheableInputBytes = 4_096,
    ) {
        if ($maximumEntries < 1 || $maximumEntries > 4_096
            || $maximumBytes < 1 || $maximumBytes > 134_217_728
            || $minimumCacheableInputBytes < 1 || $minimumCacheableInputBytes > 16_777_216) {
            throw new \InvalidArgumentException('Compression cache limits are invalid.');
        }
        $this->epoch = random_bytes(16);
    }

    public function workerCount(): int
    {
        return $this->workers->workerCount();
    }

    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        $deadlineNanoseconds ??= self::nowNanoseconds() + 30_000_000_000;
        $receipt = $this->logicalReceipt($taskTypeId, $deadlineNanoseconds);
        $key = self::contentKey($taskTypeId, $payload);
        $cached = $this->cache[$key] ?? null;
        if ($cached !== null) {
            $this->cache[$key]['last_used'] = ++$this->usageSequence;
            $completion(new WorkerResult(
                $receipt,
                WorkerResultStatus::SUCCESS,
                $cached['payload'],
                completedAtNanoseconds: self::nowNanoseconds(),
            ));

            return WorkerSubmission::accepted($receipt);
        }

        if (isset($this->inFlight[$key])) {
            $this->inFlight[$key]['waiters'][$receipt->taskId] = [$receipt, $completion];
            $this->logicalTasks[$receipt->taskId] = $key;

            return WorkerSubmission::accepted($receipt);
        }

        $submission = $this->workers->submit(
            $taskTypeId,
            $payload,
            function (WorkerResult $result) use ($key, $payload): void {
                $this->complete($key, $payload, $result);
            },
            $deadlineNanoseconds,
        );
        if ($submission->receipt === null) {
            return $submission;
        }
        $this->inFlight[$key] = [
            'receipt' => $submission->receipt,
            'waiters' => [$receipt->taskId => [$receipt, $completion]],
        ];
        $this->logicalTasks[$receipt->taskId] = $key;

        return WorkerSubmission::accepted($receipt);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        if (!hash_equals($this->epoch, $receipt->epoch)) {
            return false;
        }
        $key = $this->logicalTasks[$receipt->taskId] ?? null;
        if ($key === null || !isset($this->inFlight[$key]['waiters'][$receipt->taskId])) {
            return false;
        }
        unset($this->logicalTasks[$receipt->taskId], $this->inFlight[$key]['waiters'][$receipt->taskId]);
        if ($this->inFlight[$key]['waiters'] === []) {
            $physical = $this->inFlight[$key]['receipt'];
            unset($this->inFlight[$key]);
            $this->workers->cancel($physical);
        }

        return true;
    }

    private function logicalReceipt(int $taskTypeId, int $deadlineNanoseconds): WorkerReceipt
    {
        if ($this->nextTaskId === PHP_INT_MAX) {
            $this->nextTaskId = 1;
        }

        return new WorkerReceipt(
            $this->epoch,
            $this->nextTaskId++,
            $taskTypeId,
            'network-compression-cache',
            $deadlineNanoseconds,
        );
    }

    private static function nowNanoseconds(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new \RuntimeException('A 64-bit monotonic clock is required.');
        }

        return $now;
    }

    private function complete(string $key, string $input, WorkerResult $result): void
    {
        $entry = $this->inFlight[$key] ?? null;
        if ($entry === null || $entry['receipt']->taskId !== $result->receipt->taskId) {
            return;
        }
        unset($this->inFlight[$key]);
        if ($result->status === WorkerResultStatus::SUCCESS
            && strlen($input) >= $this->minimumCacheableInputBytes
            && strlen($result->payload) <= $this->maximumBytes) {
            $this->retain($key, $result->payload);
        }
        foreach ($entry['waiters'] as [$receipt, $completion]) {
            unset($this->logicalTasks[$receipt->taskId]);
            try {
                $completion(new WorkerResult(
                    $receipt,
                    $result->status,
                    $result->payload,
                    $result->failureCode,
                    $result->completedAtNanoseconds,
                ));
            } catch (Throwable) {
                // One abandoned session cannot prevent completion delivery to peers.
            }
        }
    }

    private function retain(string $key, string $payload): void
    {
        $existing = $this->cache[$key] ?? null;
        if ($existing !== null) {
            $this->cacheBytes -= strlen($existing['payload']);
        }
        $this->cache[$key] = ['payload' => $payload, 'last_used' => ++$this->usageSequence];
        $this->cacheBytes += strlen($payload);
        while (count($this->cache) > $this->maximumEntries || $this->cacheBytes > $this->maximumBytes) {
            $oldestKey = null;
            $oldestUsage = PHP_INT_MAX;
            foreach ($this->cache as $candidateKey => $candidate) {
                if ($candidate['last_used'] < $oldestUsage) {
                    $oldestKey = $candidateKey;
                    $oldestUsage = $candidate['last_used'];
                }
            }
            if ($oldestKey === null) {
                break;
            }
            $this->cacheBytes -= strlen($this->cache[$oldestKey]['payload']);
            unset($this->cache[$oldestKey]);
        }
    }

    private static function contentKey(int $taskTypeId, string $payload): string
    {
        return $taskTypeId . ':' . strlen($payload) . ':' . hash('sha256', $payload);
    }
}
