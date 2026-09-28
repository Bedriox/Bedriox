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

namespace Bedriox\Server\Worker\Network;

use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;

final readonly class ManagedCompressionWorkerDispatcher implements CompressionWorkerDispatcher
{
    public function __construct(private ManagedWorkerDispatcher $dispatcher) {}

    public function workerCount(): int
    {
        return $this->dispatcher->snapshot()->workerCount;
    }

    /** @param Closure(WorkerResult): void $completion */
    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        return $this->dispatcher->submit($taskTypeId, $payload, $completion, $deadlineNanoseconds);
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return $this->dispatcher->cancel($receipt);
    }
}
