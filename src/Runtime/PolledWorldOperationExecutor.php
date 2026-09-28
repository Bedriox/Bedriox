<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\World\WorldOperationResult;

/** @internal Cooperative lifecycle work which never blocks while an external owner is pending. */
interface PolledWorldOperationExecutor
{
    /** Returns null while work is still pending, or the terminal result once ready. */
    public function poll(): ?WorldOperationResult;
}
