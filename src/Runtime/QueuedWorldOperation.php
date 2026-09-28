<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\WorldOperation;
use Bedriox\Api\World\WorldOperationResult;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Api\World\WorldOperationType;
use Closure;
use LogicException;

/** @internal Main-thread world operation whose completion callbacks are drained separately. */
final class QueuedWorldOperation implements WorldOperation
{
    private WorldOperationState $state = WorldOperationState::QUEUED;
    private ?WorldOperationResult $result = null;
    /** @var list<Closure(WorldOperationResult): void> */
    private array $callbacks = [];

    public function __construct(
        private readonly WorldOperationType $operationType,
        private readonly string $id,
    ) {}

    public function type(): WorldOperationType
    {
        return $this->operationType;
    }

    public function worldId(): string
    {
        return $this->id;
    }

    public function state(): WorldOperationState
    {
        return $this->state;
    }

    public function result(): ?WorldOperationResult
    {
        return $this->result;
    }

    public function onComplete(Closure $callback): void
    {
        $this->callbacks[] = $callback;
    }

    public function cancel(): bool
    {
        if ($this->state !== WorldOperationState::QUEUED) {
            return false;
        }
        $this->state = WorldOperationState::CANCELLED;

        return true;
    }

    public function begin(): bool
    {
        if ($this->state === WorldOperationState::CANCELLED) {
            return false;
        }
        if ($this->state !== WorldOperationState::QUEUED) {
            throw new LogicException('Only a queued world operation may begin.');
        }
        $this->state = WorldOperationState::RUNNING;

        return true;
    }

    public function complete(WorldOperationResult $result): void
    {
        if ($this->state !== WorldOperationState::RUNNING && $this->state !== WorldOperationState::CANCELLED) {
            throw new LogicException('World operation completion was attempted from an invalid state.');
        }
        if ($result->type !== $this->operationType || $result->worldId !== $this->id) {
            throw new LogicException('World operation result does not match its operation.');
        }
        $this->result = $result;
        $this->state = $result->state;
    }

    /** @return list<Closure(WorldOperationResult): void> */
    public function takeCallbacks(): array
    {
        if ($this->result === null) {
            throw new LogicException('World operation callbacks cannot run before completion.');
        }
        $callbacks = $this->callbacks;
        $this->callbacks = [];

        return $callbacks;
    }
}
