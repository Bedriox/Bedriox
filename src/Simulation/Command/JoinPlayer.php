<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\PlayerBootstrap;

final readonly class JoinPlayer implements WorldCommand
{
    public function __construct(
        public string $session,
        public string $identity,
        public string $displayName,
        public ?int $runtimeActorId = null,
        public ?PlayerBootstrap $bootstrap = null,
        public bool $loginApproved = false,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 24 + strlen($this->session) + strlen($this->identity) + strlen($this->displayName)
            + ($this->bootstrap === null ? 0 : 512);
    }
}
