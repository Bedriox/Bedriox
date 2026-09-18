<?php

declare(strict_types=1);

namespace Bedriox\Server\Observability;

interface CrashContextProvider
{
    public function current(): CrashContext;
}
