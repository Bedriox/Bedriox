<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

/** Client intent to close the currently authorized dynamic storage window. */
final readonly class CloseContainer implements WorldCommand
{
    public function __construct(public string $session, public int $windowId) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 28 + strlen($this->session);
    }
}
