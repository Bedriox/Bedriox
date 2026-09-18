<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

final readonly class SendChat implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $sequence,
        public string $message,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 24 + strlen($this->session) + strlen($this->message);
    }
}
