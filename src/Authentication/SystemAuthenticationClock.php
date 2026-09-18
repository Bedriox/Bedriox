<?php

declare(strict_types=1);

namespace Bedriox\Server\Authentication;

final class SystemAuthenticationClock implements AuthenticationClock
{
    public function nowEpochSeconds(): int
    {
        return time();
    }
}
