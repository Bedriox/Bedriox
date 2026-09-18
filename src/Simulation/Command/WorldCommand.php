<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

interface WorldCommand
{
    public function sessionId(): string;

    public function estimatedBytes(): int;
}
