<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation\Event;

use Bedriox\Server\World\BlockPosition;

final readonly class CraftingTableOpened implements WorldEvent
{
    public function __construct(public string $ownerSessionId, public BlockPosition $position) {}

    public function recipients(): array
    {
        return [$this->ownerSessionId];
    }
}
