<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;

final class InventoryChangeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Inventory $before,
        public readonly Inventory $after,
    ) {}
}
