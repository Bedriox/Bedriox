<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryStack;

final readonly class RemovePluginInventoryStack implements WorldCommand
{
    public function __construct(
        public string $session,
        public InventoryStack $stack,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 64 + strlen($this->session) + strlen($this->stack->identifier) + strlen($this->stack->nbt?->toBinary() ?? '');
    }
}
