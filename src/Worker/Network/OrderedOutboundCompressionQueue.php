<?php

declare(strict_types=1);

namespace Bedriox\Server\Worker\Network;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Server\Worker\WorkerResult;
use Bedriox\Server\Worker\WorkerResultStatus;
use Throwable;

/** Bounded per-session reorder queue; compression completion order never becomes wire order. */
final class OrderedOutboundCompressionQueue
{
    /** @var array<int, PendingOutboundCompression> */
    private array $entries = [];
    private int $nextSequence = 1;
    private int $nextReleaseSequence = 1;
    private int $outstandingBytes = 0;
    private bool $closed = false;

    public function __construct(
        private readonly CompressionWorkerDispatcher $workers,
        private readonly int $taskTypeId,
        private int $sessionGeneration,
        private readonly CompressionMode $mode,
        private readonly int $threshold,
        private readonly BatchLimits $limits,
        private readonly int $maximumOutstandingTasks = 32,
        private readonly int $maximumOutstandingBytes = 8_388_608,
        private readonly int $maximumResultBytes = 1_048_577,
        private readonly int $minimumOffloadBytes = 1,
        private readonly int $maximumWorkerAttempts = 3,
    ) {
        if ($taskTypeId < 1 || $taskTypeId > 65_535 || $sessionGeneration < 1
            || $threshold < 0 || $threshold > 65_535
            || $maximumOutstandingTasks < 1 || $maximumOutstandingTasks > 4_096
            || $maximumOutstandingBytes < 1 || $maximumOutstandingBytes > 67_108_864
            || $maximumResultBytes < $limits->maximumInputBytes + 1 || $maximumResultBytes > 33_554_432
            || $minimumOffloadBytes < 1 || $minimumOffloadBytes > $limits->maximumDecompressedBytes
            || $maximumWorkerAttempts < 1 || $maximumWorkerAttempts > 16) {
            throw new \InvalidArgumentException('Outbound compression queue limits or identity are invalid.');
        }
    }

    public function enqueue(string $uncompressedBatch, ?int $deadlineNanoseconds = null): OutboundCompressionSubmission
    {
        if ($this->closed) {
            return OutboundCompressionSubmission::closed();
        }
        $request = new BatchCompressionRequest($uncompressedBatch, $this->mode, $this->threshold, $this->limits);
        $encodedRequest = (new BatchCompressionRequestCodec())->encode($request);
        $requestBytes = strlen($encodedRequest);
        if (count($this->entries) >= $this->maximumOutstandingTasks
            || $requestBytes > $this->maximumOutstandingBytes - $this->outstandingBytes) {
            return OutboundCompressionSubmission::saturated();
        }

        $sequence = $this->nextSequence++;
        $generation = $this->sessionGeneration;
        $entry = new PendingOutboundCompression($sequence, $generation, $encodedRequest, $deadlineNanoseconds);
        $this->entries[$sequence] = $entry;
        $this->outstandingBytes += $requestBytes;
        if (strlen($uncompressedBatch) < $this->minimumOffloadBytes || $this->workers->workerCount() === 0) {
            $this->fallback($entry);

            return OutboundCompressionSubmission::accepted($sequence, true);
        }
        $this->submit($entry);

        return OutboundCompressionSubmission::accepted($sequence, false);
    }

    /** @return list<OutboundCompressedBatch> */
    public function takeReady(int $maximumResults = 256): array
    {
        if ($maximumResults < 1 || $maximumResults > 4_096) {
            throw new \InvalidArgumentException('Compression release limit is invalid.');
        }
        $this->retryDeferred();
        $ready = [];
        while (count($ready) < $maximumResults) {
            $entry = $this->entries[$this->nextReleaseSequence] ?? null;
            if ($entry === null || !$entry->isComplete()) {
                break;
            }
            unset($this->entries[$entry->sequence]);
            $this->outstandingBytes -= strlen($entry->encodedRequest) + strlen($entry->result ?? '');
            ++$this->nextReleaseSequence;
            $ready[] = new OutboundCompressedBatch(
                $entry->sequence,
                $entry->sessionGeneration,
                $entry->result,
                $entry->failureCode,
                $entry->synchronousFallback,
            );
        }

        return $ready;
    }

    public function advanceSessionGeneration(int $sessionGeneration): void
    {
        if ($this->closed || $sessionGeneration <= $this->sessionGeneration) {
            throw new \InvalidArgumentException('Replacement session generation must increase on an open queue.');
        }
        $this->cancelAndClear();
        $this->sessionGeneration = $sessionGeneration;
        $this->nextReleaseSequence = $this->nextSequence;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->cancelAndClear();
    }

    public function outstandingCount(): int
    {
        return count($this->entries);
    }

    public function outstandingBytes(): int
    {
        return $this->outstandingBytes;
    }

    public function sessionGeneration(): int
    {
        return $this->sessionGeneration;
    }

    private function complete(int $sequence, int $generation, WorkerResult $result): void
    {
        $entry = $this->entries[$sequence] ?? null;
        if ($this->closed || $generation !== $this->sessionGeneration || $entry === null
            || $entry->sessionGeneration !== $generation || $entry->isComplete()) {
            return;
        }
        if ($result->status !== WorkerResultStatus::SUCCESS
            || $result->receipt->taskTypeId !== $this->taskTypeId
            || $entry->receipt === null
            || $result->receipt->taskId !== $entry->receipt->taskId
            || !$this->isValidWorkerPayload($result->payload)) {
            $entry->receipt = null;
            if ($entry->attempts >= $this->maximumWorkerAttempts) {
                $entry->failureCode = 'worker-attempt-limit';
            }

            return;
        }
        $entry->receipt = null;
        if (!$this->storeResult($entry, $result->payload)) {
            $entry->failureCode = 'completion-byte-limit';
        }
    }

    private function submit(PendingOutboundCompression $entry): void
    {
        if ($entry->isComplete() || $entry->receipt !== null) {
            return;
        }
        if ($entry->attempts >= $this->maximumWorkerAttempts) {
            $entry->failureCode = 'worker-attempt-limit';

            return;
        }
        ++$entry->attempts;
        $sequence = $entry->sequence;
        $generation = $entry->sessionGeneration;
        $submission = $this->workers->submit(
            $this->taskTypeId,
            $entry->encodedRequest,
            function (WorkerResult $result) use ($sequence, $generation): void {
                $this->complete($sequence, $generation, $result);
            },
            $entry->deadlineNanoseconds,
        );
        if ($submission->receipt !== null && isset($this->entries[$sequence]) && !$entry->isComplete()) {
            $entry->receipt = $submission->receipt;

            return;
        }
        if ($entry->attempts >= $this->maximumWorkerAttempts) {
            $entry->failureCode = 'worker-attempt-limit';
        }
    }

    private function retryDeferred(): void
    {
        foreach ($this->entries as $entry) {
            if (!$entry->isComplete() && $entry->receipt === null) {
                $this->submit($entry);
            }
        }
    }

    private function fallback(PendingOutboundCompression $entry): void
    {
        if ($entry->isComplete()) {
            return;
        }
        $entry->synchronousFallback = true;
        try {
            $payload = (new BatchCompressionTask())->execute($entry->encodedRequest);
            if (!$this->isValidWorkerPayload($payload)) {
                throw new \RuntimeException('Synchronous compression produced an invalid bounded result.');
            }
            if (!$this->storeResult($entry, $payload)) {
                $entry->failureCode = 'completion-byte-limit';
            }
        } catch (Throwable) {
            $entry->failureCode = 'synchronous-compression-failed';
        }
    }

    private function isValidWorkerPayload(string $payload): bool
    {
        return $payload !== '' && strlen($payload) <= $this->maximumResultBytes
            && ord($payload[0]) === BedrockBatchCodec::GAME_PACKET_MARKER;
    }

    private function storeResult(PendingOutboundCompression $entry, string $payload): bool
    {
        $bytes = strlen($payload);
        if ($bytes > $this->maximumOutstandingBytes - $this->outstandingBytes) {
            return false;
        }
        $entry->result = $payload;
        $this->outstandingBytes += $bytes;

        return true;
    }

    private function cancelAndClear(): void
    {
        foreach ($this->entries as $entry) {
            if ($entry->receipt !== null) {
                $this->workers->cancel($entry->receipt);
            }
        }
        $this->entries = [];
        $this->outstandingBytes = 0;
    }
}
