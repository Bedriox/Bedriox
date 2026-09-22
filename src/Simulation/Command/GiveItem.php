<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Api\Inventory\ItemNbt;

final readonly class GiveItem implements WorldCommand
{
    public function __construct(
        public string $session,
        public string $identifier,
        public int $amount,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 48 + strlen($this->session) + strlen($this->identifier) + strlen($this->nbt?->toBinary() ?? '');
    }
}
