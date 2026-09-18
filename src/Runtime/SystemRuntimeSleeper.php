<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

final class SystemRuntimeSleeper implements RuntimeSleeper
{
    public function idle(): void
    {
        usleep(1_000);
    }
}
