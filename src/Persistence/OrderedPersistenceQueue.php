<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence;

use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Bounded deterministic hand-off queue for a single persistence owner.
 *
 * At most one request per key is in flight and one newer unsent request may wait behind it.
 */
final class OrderedPersistenceQueue
{
    /** @var array<string, PersistenceWriteRequest> */
    private array $queued = [];
    /** @var array<string, PersistenceWriteRequest> */
    private array $inFlight = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $completions = [];
    private int $nextRequestId = 1;
    private int $nextSequence = 1;
    private int $requestBytes = 0;
    private int $coalesced = 0;
    private int $saturated = 0;
    private int $failed = 0;

    public function __construct(
        private readonly int $maximumRequests,
        private readonly int $maximumRequestBytes,
        private readonly int $maximumPayloadBytes,
        private readonly int $maximumCompletions,
    ) {
        if ($maximumRequests < 1 || $maximumRequestBytes < 1 || $maximumPayloadBytes < 1 || $maximumCompletions < 1) {
            throw new InvalidArgumentException('Persistence queue limits must be positive.');
        }
        if ($maximumPayloadBytes > $maximumRequestBytes) {
            throw new InvalidArgumentException('A persistence payload cannot exceed the queue byte limit.');
        }
    }

    public function enqueue(string $key, int $revision, string $payload): PersistenceEnqueueResult
    {
        if ($revision < 0) {
            throw new InvalidArgumentException('Persistence revision must be non-negative.');
        }
        if (strlen($payload) > $this->maximumPayloadBytes) {
            ++$this->saturated;

            return new PersistenceEnqueueResult(PersistenceSubmission::SATURATED, null);
        }

        $queued = $this->queued[$key] ?? null;
        $inFlight = $this->inFlight[$key] ?? null;
        $newestRevision = -1;
        if ($queued instanceof PersistenceWriteRequest) {
            $newestRevision = $queued->revision;
        }
        if ($inFlight instanceof PersistenceWriteRequest) {
            $newestRevision = max($newestRevision, $inFlight->revision);
        }
        if ($revision <= $newestRevision) {
            return new PersistenceEnqueueResult(PersistenceSubmission::STALE, null);
        }

        $request = new PersistenceWriteRequest(
            $this->nextRequestId,
            $this->nextSequence,
            $key,
            $revision,
            $payload,
        );
        $replacedBytes = $queued?->bytes() ?? 0;
        $nextCount = count($this->queued) + count($this->inFlight) + ($queued === null ? 1 : 0);
        $nextBytes = $this->requestBytes - $replacedBytes + $request->bytes();
        if ($nextCount > $this->maximumRequests || $nextBytes > $this->maximumRequestBytes) {
            ++$this->saturated;

            return new PersistenceEnqueueResult(PersistenceSubmission::SATURATED, null);
        }

        ++$this->nextRequestId;
        ++$this->nextSequence;
        $this->queued[$key] = $request;
        $this->requestBytes = $nextBytes;
        if ($queued !== null) {
            ++$this->coalesced;
        }

        return new PersistenceEnqueueResult(
            $queued === null ? PersistenceSubmission::ACCEPTED : PersistenceSubmission::COALESCED,
            $request,
        );
    }

    public function dispatch(): ?PersistenceWriteRequest
    {
        if (count($this->completions) >= $this->maximumCompletions) {
            return null;
        }

        $candidate = null;
        foreach ($this->queued as $key => $request) {
            if (isset($this->inFlight[$key])) {
                continue;
            }
            if ($candidate === null || $request->sequence < $candidate->sequence) {
                $candidate = $request;
            }
        }
        if (!$candidate instanceof PersistenceWriteRequest) {
            return null;
        }

        unset($this->queued[$candidate->key]);
        $this->inFlight[$candidate->key] = $candidate;

        return $candidate;
    }

    public function complete(int $requestId, string $key, int $revision, bool $successful, ?string $failureCode = null): void
    {
        $request = $this->inFlight[$key] ?? null;
        if (!$request instanceof PersistenceWriteRequest
            || $request->id !== $requestId
            || $request->revision !== $revision) {
            throw new LogicException('Persistence completion does not match the in-flight request.');
        }
        if (count($this->completions) >= $this->maximumCompletions) {
            throw new OverflowException('Persistence completion queue is full.');
        }

        unset($this->inFlight[$key]);
        $this->requestBytes -= $request->bytes();
        if (!$successful) {
            ++$this->failed;
        }
        $this->completions[] = new PersistenceWriteCompletion(
            $requestId,
            $key,
            $revision,
            $successful,
            $successful ? null : ($failureCode ?? 'write_failed'),
        );
    }

    public function takeCompletion(): ?PersistenceWriteCompletion
    {
        return array_shift($this->completions);
    }

    public function snapshot(): PersistenceQueueSnapshot
    {
        return new PersistenceQueueSnapshot(
            count($this->queued),
            count($this->inFlight),
            count($this->completions),
            $this->requestBytes,
            $this->coalesced,
            $this->saturated,
            $this->failed,
        );
    }
}
