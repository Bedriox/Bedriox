<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

final readonly class GiveItem implements WorldCommand
{
    public function __construct(public string $session, public string $identifier, public int $amount) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session) + strlen($this->identifier);
    }
}
