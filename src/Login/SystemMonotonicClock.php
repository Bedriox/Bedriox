<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

final class SystemMonotonicClock implements MonotonicClock
{
    public function nowNanoseconds(): int
    {
        return hrtime(true);
    }
}
