<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;

final readonly class DropItem implements WorldCommand
{
    public function __construct(
        public string $session,
        public int $requestId,
        public InventorySlotReference $source,
        public int $count,
        public InventoryResponseMode $responseMode,
        public ?InventoryStack $expectedStack = null,
    ) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 80 + strlen($this->session)
            + ($this->expectedStack === null ? 0 : strlen($this->expectedStack->identifier));
    }
}
