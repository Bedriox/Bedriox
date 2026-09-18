<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

use LogicException;

abstract class Event
{
    private bool $readOnly = false;

    /** @internal */
    final public function captureState(): mixed
    {
        return $this->state();
    }

    /** @internal */
    final public function restoreState(mixed $state): void
    {
        $this->replaceState($state);
    }

    /** @internal */
    final public function setReadOnly(bool $readOnly): void
    {
        $this->readOnly = $readOnly;
    }

    final protected function assertMutable(): void
    {
        if ($this->readOnly) {
            throw new LogicException('MONITOR listeners may not modify events.');
        }
    }

    protected function state(): mixed
    {
        return null;
    }

    protected function replaceState(mixed $state): void
    {
        if ($state !== null) {
            throw new LogicException('This event has no mutable state.');
        }
    }
}
