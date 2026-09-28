<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Closure;

/** @internal Composes simulation state only after asynchronous storage startup is ready. */
final class PendingManagedWorldRuntime
{
    private ?ManagedWorldRuntime $runtime = null;

    /** @param Closure(OpenedWorld): ManagedWorldRuntime $compose */
    public function __construct(
        private readonly PendingOpenedWorld $opened,
        private readonly Closure $compose,
    ) {}

    public function poll(): ?ManagedWorldRuntime
    {
        if ($this->runtime instanceof ManagedWorldRuntime) {
            return $this->runtime;
        }
        $opened = $this->opened->poll();
        if (!$opened instanceof OpenedWorld) {
            return null;
        }

        return $this->runtime = ($this->compose)($opened);
    }

    public function cancel(): void
    {
        if (!$this->runtime instanceof ManagedWorldRuntime) {
            $this->opened->cancel();
        }
    }
}
