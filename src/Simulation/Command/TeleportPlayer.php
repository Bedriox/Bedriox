<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Simulation\Position;

final readonly class TeleportPlayer implements WorldCommand
{
    public function __construct(public string $session, public Position $position) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->session);
    }
}
