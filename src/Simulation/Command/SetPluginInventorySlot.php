<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryStack;

final readonly class SetPluginInventorySlot implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $slot,
        public ?InventoryStack $stack,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->session) + ($this->stack === null ? 0 : strlen($this->stack->identifier));
    }
}
