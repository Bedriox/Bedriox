<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\World;

use Bedriox\Server\Worker\WorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use RuntimeException;
use Throwable;

/** Main-process owner of one bounded world-preparation task. */
final class PendingWorldPreparation
{
    private ?WorkerReceipt $receipt = null;
    private ?WorldPreparationResult $result = null;
    private ?string $failureCode = null;
    private bool $completed = false;
    private bool $cancelled = false;

    public function __construct(
        private readonly WorkerDispatcher $workers,
        int $taskTypeId,
        private readonly WorldPreparationRequest $request,
    ) {
        $codec = new WorldPreparationCodec();
        $submission = $workers->submit(
            $taskTypeId,
            $codec->encodeRequest($request),
            function (WorkerResult $workerResult) use ($codec): void {
                if ($this->cancelled || ($this->receipt !== null && $workerResult->receipt->taskId !== $this->receipt->taskId)) {
                    return;
                }
                $this->completed = true;
                if ($workerResult->status !== WorkerResultStatus::SUCCESS) {
                    $this->failureCode = $workerResult->failureCode ?? $workerResult->status->value;

                    return;
                }
                try {
                    $result = $codec->decodeResult($workerResult->payload);
                    if ($result->generatorIdentifier !== $this->request->generatorIdentifier
                        || $result->generatorVersion !== $this->request->generatorVersion) {
                        throw new RuntimeException('Worker returned world metadata for a different generator.');
                    }
                    $this->result = $result;
                } catch (Throwable) {
                    $this->failureCode = 'invalid-result';
                }
            },
        );
        if ($submission->receipt === null) {
            $this->completed = true;
            $this->failureCode = 'queue-rejected';

            return;
        }
        $this->receipt = $submission->receipt;
    }

    public function poll(): ?WorldPreparationResult
    {
        if ($this->cancelled) {
            throw new RuntimeException('World preparation was cancelled.');
        }
        if (!$this->completed) {
            return null;
        }
        if (!$this->result instanceof WorldPreparationResult) {
            throw new RuntimeException('World preparation worker failed: ' . ($this->failureCode ?? 'unknown'));
        }

        return $this->result;
    }

    public function cancel(): void
    {
        if ($this->cancelled || $this->completed) {
            return;
        }
        $this->cancelled = true;
        if ($this->receipt !== null) {
            $this->workers->cancel($this->receipt);
        }
    }
}
