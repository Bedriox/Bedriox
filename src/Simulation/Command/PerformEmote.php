<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

final readonly class PerformEmote implements WorldCommand
{
    public function __construct(
        public string $session,
        public string $emoteId,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 16 + strlen($this->session) + strlen($this->emoteId);
    }
}
