<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

final readonly class JoinPlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public string $identity,
        public string $displayName,
        public ?int $runtimeActorId = null,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 24 + strlen($this->session) + strlen($this->identity) + strlen($this->displayName);
    }
}
