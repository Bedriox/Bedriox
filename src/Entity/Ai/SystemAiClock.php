<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

final class SystemAiClock implements AiClock
{
    public function nanoseconds(): int
    {
        return hrtime(true);
    }
}
