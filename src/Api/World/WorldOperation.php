<?php

declare(strict_types=1);

namespace Bedriox\Api\World;

use Closure;

/** A non-blocking world lifecycle operation completed on the main server thread. */
interface WorldOperation
{
    public function type(): WorldOperationType;

    public function worldId(): string;

    public function state(): WorldOperationState;

    public function result(): ?WorldOperationResult;

    /**
     * Registers a main-thread completion callback, invoked exactly once and never inline.
     *
     * @param Closure(WorldOperationResult): void $callback
     */
    public function onComplete(Closure $callback): void;

    /** Returns true only when queued work was cancelled before committing world state. */
    public function cancel(): bool;
}
