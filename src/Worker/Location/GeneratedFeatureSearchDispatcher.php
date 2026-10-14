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

namespace Bedriox\Server\Worker\Location;

use Bedriox\Server\Worker\ManagedWorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Bedriox\Server\World\Generation\Structure\Location\LocatedFeature;
use Closure;
use Throwable;

/** Owns a bounded set of cancellable asynchronous locate searches. */
final class GeneratedFeatureSearchDispatcher
{
    /** @var array<string, WorkerReceipt> */
    private array $pending = [];

    public function __construct(
        private readonly ManagedWorkerDispatcher $workers,
        private readonly int $taskTypeId,
        private readonly int $maximumPending = 2,
    ) {
        if ($maximumPending < 1 || $maximumPending > 16) {
            throw new \InvalidArgumentException('Locate search pending limit must be between one and sixteen.');
        }
    }

    /** @param Closure(?LocatedFeature, ?string): void $completion */
    public function submit(string $owner, GeneratedFeatureSearchRequest $request, Closure $completion): bool
    {
        $previous = $this->pending[$owner] ?? null;
        if ($previous !== null) {
            $this->workers->cancel($previous);
            unset($this->pending[$owner]);
        } elseif (count($this->pending) >= $this->maximumPending) {
            return false;
        }

        $codec = new GeneratedFeatureSearchCodec();
        $submission = $this->workers->submit(
            $this->taskTypeId,
            $codec->encodeRequest($request),
            function (WorkerResult $result) use ($owner, $codec, $completion): void {
                $current = $this->pending[$owner] ?? null;
                if ($current === null || $current->taskId !== $result->receipt->taskId) {
                    return;
                }
                unset($this->pending[$owner]);
                if ($result->status !== WorkerResultStatus::SUCCESS) {
                    $completion(null, 'The locate search could not be completed.');
                    return;
                }
                try {
                    $completion($codec->decodeResult($result->payload), null);
                } catch (Throwable) {
                    $completion(null, 'The locate search returned an invalid result.');
                }
            },
            hrtime(true) + 30_000_000_000,
        );
        if ($submission->receipt === null) {
            return false;
        }
        $this->pending[$owner] = $submission->receipt;

        return true;
    }

    public function cancel(string $owner): void
    {
        $receipt = $this->pending[$owner] ?? null;
        if ($receipt === null) {
            return;
        }
        unset($this->pending[$owner]);
        $this->workers->cancel($receipt);
    }
}
