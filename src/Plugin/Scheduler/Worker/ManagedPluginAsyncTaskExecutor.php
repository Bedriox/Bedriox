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

namespace Bedriox\Server\Plugin\Scheduler\Worker;

use Bedriox\Api\Scheduler\AsyncTaskFailure;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskOutcome;
use Bedriox\Server\Plugin\Scheduler\AsyncTaskRequest;
use Bedriox\Server\Plugin\Scheduler\PluginAsyncTaskExecutor;
use Bedriox\Server\Worker\ManagedWorkerPool;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResultStatus;
use Throwable;

final class ManagedPluginAsyncTaskExecutor implements PluginAsyncTaskExecutor
{
    private const int MAXIMUM_QUEUED_TASKS = 1_024;
    private const int MAXIMUM_QUEUED_TASKS_PER_PLUGIN = 256;

    /** @var array<int, WorkerReceipt> plugin task ID => worker receipt */
    private array $receipts = [];
    /** @var array<int, AsyncTaskRequest> worker task ID => plugin request */
    private array $requests = [];
    /** @var array<string, list<array{AsyncTaskRequest, int}>> normalized owner => queued request and enqueue time */
    private array $queued = [];
    /** @var array<int, string> plugin task ID => normalized owner */
    private array $queuedOwners = [];
    /** @var list<string> */
    private array $ownerOrder = [];
    /** @var list<AsyncTaskOutcome> */
    private array $ready = [];
    private int $nextOwnerIndex = 0;
    private int $rejectedTasks = 0;
    private int $expiredTasks = 0;

    public function __construct(
        private readonly ManagedWorkerPool $pool,
        private readonly int $taskTypeId,
        private readonly PluginAsyncTaskCodec $codec = new PluginAsyncTaskCodec(),
    ) {}

    public function submit(AsyncTaskRequest $request): void
    {
        $owner = strtolower($request->owner);
        if (count($this->queuedOwners) >= self::MAXIMUM_QUEUED_TASKS
            || count($this->queued[$owner] ?? []) >= self::MAXIMUM_QUEUED_TASKS_PER_PLUGIN) {
            ++$this->rejectedTasks;
            throw new \RuntimeException('Plugin asynchronous task queue is full.');
        }
        if (!isset($this->queued[$owner])) {
            $this->queued[$owner] = [];
            $this->ownerOrder[] = $owner;
        }
        $this->queued[$owner][] = [$request, self::now()];
        $this->queuedOwners[$request->taskId] = $owner;
    }

    public function cancel(int $taskId): void
    {
        $queuedOwner = $this->queuedOwners[$taskId] ?? null;
        if ($queuedOwner !== null) {
            foreach ($this->queued[$queuedOwner] as $index => [$request]) {
                if ($request->taskId === $taskId) {
                    array_splice($this->queued[$queuedOwner], $index, 1);
                    unset($this->queuedOwners[$taskId]);
                    $this->removeOwnerWhenEmpty($queuedOwner);
                    return;
                }
            }
        }
        $receipt = $this->receipts[$taskId] ?? null;
        if ($receipt !== null) {
            $this->pool->cancel($receipt);
            unset($this->receipts[$taskId], $this->requests[$receipt->taskId]);
        }
    }

    public function poll(int $maximum): array
    {
        $this->pool->poll();
        $outcomes = array_splice($this->ready, 0, $maximum);
        $remaining = $maximum - count($outcomes);
        foreach ($remaining > 0 ? $this->pool->takeResults($remaining) : [] as $result) {
            $request = $this->requests[$result->receipt->taskId] ?? null;
            if ($request === null) {
                continue;
            }
            unset($this->requests[$result->receipt->taskId], $this->receipts[$request->taskId]);
            if ($result->status !== WorkerResultStatus::SUCCESS) {
                $outcomes[] = AsyncTaskOutcome::failed(
                    $request->taskId,
                    $request->owner,
                    $request->ownerGeneration,
                    new AsyncTaskFailure('worker_' . $result->status->value, $result->failureCode ?? 'task did not complete'),
                );
                continue;
            }
            try {
                $decoded = $this->codec->decodeResult($result->payload);
                if (strcasecmp($decoded['owner'], $request->owner) !== 0
                    || $decoded['generation'] !== $request->ownerGeneration) {
                    throw new \RuntimeException('Plugin worker result owner does not match its request.');
                }
                $outcomes[] = isset($decoded['result'])
                    ? AsyncTaskOutcome::completed($request->taskId, $request->owner, $request->ownerGeneration, $decoded['result'])
                    : AsyncTaskOutcome::failed(
                        $request->taskId,
                        $request->owner,
                        $request->ownerGeneration,
                        new AsyncTaskFailure('task_exception', $decoded['failure'] ?? 'unknown failure'),
                    );
            } catch (Throwable $failure) {
                $outcomes[] = AsyncTaskOutcome::failed(
                    $request->taskId,
                    $request->owner,
                    $request->ownerGeneration,
                    new AsyncTaskFailure('invalid_worker_result', $failure::class),
                );
            }
        }

        $this->expireQueuedTasks();
        $this->dispatchQueuedTasks();

        return $outcomes;
    }

    public function snapshot(): PluginAsyncQueueSnapshot
    {
        $oldest = null;
        $queuedByPlugin = [];
        foreach ($this->queued as $owner => $entries) {
            $queuedByPlugin[$entries[0][0]->owner ?? $owner] = count($entries);
            foreach ($entries as [, $queuedAt]) {
                $oldest = $oldest === null ? $queuedAt : min($oldest, $queuedAt);
            }
        }

        return new PluginAsyncQueueSnapshot(
            count($this->queuedOwners),
            count($this->receipts),
            count($this->ownerOrder),
            $oldest === null ? 0 : intdiv(max(0, self::now() - $oldest), 1_000_000),
            $this->rejectedTasks,
            $this->expiredTasks,
            $queuedByPlugin,
        );
    }

    public function shutdown(): void
    {
        $this->receipts = [];
        $this->requests = [];
        $this->queued = [];
        $this->queuedOwners = [];
        $this->ownerOrder = [];
        $this->ready = [];
        $this->pool->shutdown();
    }

    private function expireQueuedTasks(): void
    {
        $now = self::now();
        foreach (array_keys($this->queued) as $owner) {
            $remaining = [];
            foreach ($this->queued[$owner] as $entry) {
                [$request] = $entry;
                if ($request->deadlineNanoseconds > $now) {
                    $remaining[] = $entry;
                    continue;
                }
                unset($this->queuedOwners[$request->taskId]);
                ++$this->expiredTasks;
                $this->ready[] = AsyncTaskOutcome::failed(
                    $request->taskId,
                    $request->owner,
                    $request->ownerGeneration,
                    new AsyncTaskFailure('worker_timeout', 'task expired before worker dispatch'),
                );
            }
            $this->queued[$owner] = $remaining;
            $this->removeOwnerWhenEmpty($owner);
        }
    }

    private function dispatchQueuedTasks(): void
    {
        $capacity = max(0, $this->pool->snapshot()->workerCount - count($this->receipts));
        while ($capacity > 0 && $this->ownerOrder !== []) {
            $this->nextOwnerIndex %= count($this->ownerOrder);
            $owner = $this->ownerOrder[$this->nextOwnerIndex];
            $entry = array_shift($this->queued[$owner]);
            if ($entry === null) {
                $this->removeOwnerWhenEmpty($owner);
                continue;
            }
            [$request] = $entry;
            unset($this->queuedOwners[$request->taskId]);
            $submission = $this->pool->submit(
                $this->taskTypeId,
                $this->codec->encodeRequest($request),
                $request->deadlineNanoseconds,
            );
            if (!$submission->isAccepted() || !$submission->receipt instanceof WorkerReceipt) {
                ++$this->rejectedTasks;
                $reason = $submission->rejection;
                $this->ready[] = AsyncTaskOutcome::failed(
                    $request->taskId,
                    $request->owner,
                    $request->ownerGeneration,
                    new AsyncTaskFailure(
                        'worker_rejected',
                        $reason instanceof \Bedriox\Server\Worker\WorkerRejectionReason ? $reason->value : 'unknown',
                    ),
                );
            } else {
                $this->receipts[$request->taskId] = $submission->receipt;
                $this->requests[$submission->receipt->taskId] = $request;
                --$capacity;
            }
            if ($this->queued[$owner] === []) {
                $this->removeOwnerWhenEmpty($owner);
            } else {
                ++$this->nextOwnerIndex;
            }
        }
    }

    private function removeOwnerWhenEmpty(string $owner): void
    {
        if (($this->queued[$owner] ?? []) !== []) {
            return;
        }
        unset($this->queued[$owner]);
        $index = array_search($owner, $this->ownerOrder, true);
        if ($index === false) {
            return;
        }
        array_splice($this->ownerOrder, $index, 1);
        if ($index < $this->nextOwnerIndex) {
            --$this->nextOwnerIndex;
        }
        $this->nextOwnerIndex = max(0, $this->nextOwnerIndex);
    }

    private static function now(): int
    {
        $value = hrtime(true);
        if (!is_int($value)) {
            throw new \RuntimeException('The platform does not provide an integer monotonic clock.');
        }

        return $value;
    }
}
