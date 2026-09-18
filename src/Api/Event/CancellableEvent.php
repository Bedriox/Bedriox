<?php

declare(strict_types=1);

namespace Bedriox\Api\Event;

abstract class CancellableEvent extends Event
{
    private bool $cancelled = false;

    final public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    final public function cancel(): void
    {
        $this->setCancelled(true);
    }

    final public function setCancelled(bool $cancelled): void
    {
        $this->assertMutable();
        $this->cancelled = $cancelled;
    }

    protected function state(): mixed
    {
        return $this->cancelled;
    }

    protected function replaceState(mixed $state): void
    {
        $this->cancelled = (bool) $state;
    }
}
