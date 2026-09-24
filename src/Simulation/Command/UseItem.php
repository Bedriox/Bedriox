<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

/** Version-independent intent to use the current authoritative main-hand item. */
final readonly class UseItem implements WorldCommand
{
    public function __construct(public string $session, public int $hotbarSlot) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 32 + strlen($this->session);
    }
}
