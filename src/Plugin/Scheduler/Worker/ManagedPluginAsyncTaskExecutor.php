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
    /** @var array<int, WorkerReceipt> plugin task ID => worker receipt */
    private array $receipts = [];
    /** @var array<int, AsyncTaskRequest> worker task ID => plugin request */
    private array $requests = [];

    public function __construct(
        private readonly ManagedWorkerPool $pool,
        private readonly int $taskTypeId,
        private readonly PluginAsyncTaskCodec $codec = new PluginAsyncTaskCodec(),
    ) {}

    public function submit(AsyncTaskRequest $request): void
    {
        $submission = $this->pool->submit(
            $this->taskTypeId,
            $this->codec->encodeRequest($request),
            $request->deadlineNanoseconds,
        );
        if (!$submission->isAccepted() || !$submission->receipt instanceof WorkerReceipt) {
            $reason = $submission->rejection;
            throw new \RuntimeException('Plugin worker rejected the asynchronous task: '
                . ($reason instanceof \Bedriox\Server\Worker\WorkerRejectionReason ? $reason->value : 'unknown'));
        }
        $this->receipts[$request->taskId] = $submission->receipt;
        $this->requests[$submission->receipt->taskId] = $request;
    }

    public function cancel(int $taskId): void
    {
        $receipt = $this->receipts[$taskId] ?? null;
        if ($receipt !== null) {
            $this->pool->cancel($receipt);
            unset($this->receipts[$taskId], $this->requests[$receipt->taskId]);
        }
    }

    public function poll(int $maximum): array
    {
        $this->pool->poll();
        $outcomes = [];
        foreach ($this->pool->takeResults($maximum) as $result) {
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

        return $outcomes;
    }

    public function shutdown(): void
    {
        $this->receipts = [];
        $this->requests = [];
        $this->pool->shutdown();
    }
}
