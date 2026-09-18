<?php

declare(strict_types=1);

namespace Bedriox\Server\Login;

interface MonotonicClock
{
    public function nowNanoseconds(): int;
}
