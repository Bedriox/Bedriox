<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

final readonly class InventoryStackRequestAction
{
    public function __construct(
        public InventoryStackRequestActionType $type,
        public InventorySlotReference $source,
        public InventorySlotReference $destination,
        public int $count = 0,
    ) {}
}
