<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\WorldOperationResult;
use Closure;

/** @internal */
final readonly class CallbackPolledWorldOperationExecutor implements PolledWorldOperationExecutor
{
    /** @param Closure(): ?WorldOperationResult $poll */
    public function __construct(private Closure $poll) {}

    public function poll(): ?WorldOperationResult
    {
        return ($this->poll)();
    }
}
