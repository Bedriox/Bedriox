<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Command;

use Bedriox\Server\Player\InventorySlotReference;

final readonly class SyncInventorySlots implements WorldCommand
{
    /** @param list<InventorySlotReference> $slots */
    public function __construct(public string $session, public array $slots) {}

    public function sessionId(): string
    {
        return $this->session;
    }

    public function estimatedBytes(): int
    {
        return 24 + strlen($this->session) + count($this->slots) * 32;
    }
}
