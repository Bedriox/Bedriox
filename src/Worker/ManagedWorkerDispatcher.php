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

namespace Bedriox\Server\Worker;

use Closure;
use Throwable;

/**
 * Main-process completion boundary for core worker tasks.
 *
 * Worker results are never committed in a child process. Consumers register a
 * bounded callback which is invoked only when the authoritative loop polls
 * this dispatcher.
 */
final class ManagedWorkerDispatcher implements WorkerDispatcher
{
    /** @var array<int, Closure(WorkerResult): void> worker task ID => completion */
    private array $completions = [];

    /** @param null|Closure(Throwable): void $failureHandler */
    public function __construct(
        private readonly ManagedWorkerPool $pool,
        private readonly ?Closure $failureHandler = null,
    ) {}

    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        $submission = $this->pool->submit($taskTypeId, $payload, $deadlineNanoseconds);
        if ($submission->receipt !== null) {
            $this->completions[$submission->receipt->taskId] = $completion;
        }

        return $submission;
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        unset($this->completions[$receipt->taskId]);

        return $this->pool->cancel($receipt);
    }

    public function poll(int $maximumCompletions = 256): void
    {
        $this->pool->poll();
        foreach ($this->pool->takeResults($maximumCompletions) as $result) {
            $this->dispatch($result);
        }
    }

    /** Drains at least one ready completion, then yields at the elapsed-time boundary. */
    public function pollWithinBudget(int $maximumCompletions, int $maximumNanoseconds): void
    {
        if ($maximumCompletions < 1 || $maximumCompletions > 256
            || $maximumNanoseconds < 1 || $maximumNanoseconds > 50_000_000) {
            throw new \InvalidArgumentException('Worker completion poll budget is invalid.');
        }
        $this->pool->poll();
        $startedAt = hrtime(true);
        for ($completed = 0; $completed < $maximumCompletions; ++$completed) {
            $results = $this->pool->takeResults(1);
            if ($results === []) {
                break;
            }
            $this->dispatch($results[0]);
            if (hrtime(true) - $startedAt >= $maximumNanoseconds) {
                break;
            }
        }
    }

    private function dispatch(WorkerResult $result): void
    {
        $completion = $this->completions[$result->receipt->taskId] ?? null;
        unset($this->completions[$result->receipt->taskId]);
        if ($completion === null) {
            return;
        }
        try {
            $completion($result);
        } catch (Throwable $failure) {
            try {
                ($this->failureHandler)?->__invoke($failure);
            } catch (Throwable) {
                // A diagnostic callback cannot escape the worker boundary.
            }
        }
    }

    public function snapshot(): WorkerPoolSnapshot
    {
        return $this->pool->snapshot();
    }

    public function shutdown(): void
    {
        $this->completions = [];
        $this->pool->shutdown();
    }
}
