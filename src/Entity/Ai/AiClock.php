<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Ai;

interface AiClock
{
    public function nanoseconds(): int;
}
