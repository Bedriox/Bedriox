<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\WorldOperationFailure;
use Bedriox\Api\World\WorldOperationFailureCode;
use Bedriox\Api\World\WorldOperationResult;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Api\World\WorldOperationType;
use Closure;
use InvalidArgumentException;
use LogicException;
use SplQueue;

/** @internal Bounded main-thread executor for public world lifecycle operations. */
final class WorldOperationQueue
{
    /**
     * @var SplQueue<array{
     *     QueuedWorldOperation,
     *     (Closure(): (WorldOperationResult|PolledWorldOperationExecutor))|PolledWorldOperationExecutor,
     *     bool
     * }>
     */
    private SplQueue $pending;
    /** @var SplQueue<QueuedWorldOperation> */
    private SplQueue $completed;

    public function __construct(private readonly int $capacity = 256)
    {
        if ($capacity < 1 || $capacity > 65_536) {
            throw new InvalidArgumentException('World operation capacity must be between 1 and 65536.');
        }
        $this->pending = new SplQueue();
        $this->completed = new SplQueue();
    }

    /** @param Closure(): (WorldOperationResult|PolledWorldOperationExecutor) $executor */
    public function enqueue(WorldOperationType $type, string $worldId, Closure $executor): QueuedWorldOperation
    {
        if ($this->pending->count() >= $this->capacity) {
            throw new LogicException('World operation queue capacity is exhausted.');
        }
        $operation = new QueuedWorldOperation($type, $worldId);
        $this->pending->enqueue([$operation, $executor, false]);

        return $operation;
    }

    public function pendingCount(): int
    {
        return $this->pending->count();
    }

    /** Executes bounded lifecycle work, then delivers callbacks completed by an earlier poll. */
    public function poll(int $budget = 1): int
    {
        if ($budget < 1 || $budget > 64) {
            throw new InvalidArgumentException('World operation poll budget must be between 1 and 64.');
        }

        $callbacks = $this->completed;
        $this->completed = new SplQueue();
        while (!$callbacks->isEmpty()) {
            $operation = $callbacks->dequeue();
            $result = $operation->result();
            if ($result === null) {
                continue;
            }
            foreach ($operation->takeCallbacks() as $callback) {
                $callback($result);
            }
        }

        $processed = 0;
        while ($processed < $budget && !$this->pending->isEmpty()) {
            [$operation, $executor, $started] = $this->pending->dequeue();
            if (!$started && !$operation->begin()) {
                $operation->complete(new WorldOperationResult(
                    $operation->type(),
                    WorldOperationState::CANCELLED,
                    $operation->worldId(),
                    failure: new WorldOperationFailure(
                        WorldOperationFailureCode::CANCELLED,
                        'World operation was cancelled before it began.',
                    ),
                ));
                $this->completed->enqueue($operation);
                ++$processed;
                continue;
            }
            if ($executor instanceof PolledWorldOperationExecutor) {
                $outcome = $executor->poll();
                if ($outcome === null) {
                    $this->pending->enqueue([$operation, $executor, true]);
                } else {
                    $operation->complete($outcome);
                    $this->completed->enqueue($operation);
                }
            } else {
                $outcome = $executor();
                if ($outcome instanceof PolledWorldOperationExecutor) {
                    $this->pending->enqueue([$operation, $outcome, true]);
                } else {
                    $operation->complete($outcome);
                    $this->completed->enqueue($operation);
                }
            }
            ++$processed;
        }

        return $processed;
    }
}
