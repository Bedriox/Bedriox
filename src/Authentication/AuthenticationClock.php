<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

interface AuthenticationClock
{
    public function nowEpochSeconds(): int;
}
