<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Throwable;

interface RuntimeFailureSource
{
    public function failure(): ?Throwable;
}
