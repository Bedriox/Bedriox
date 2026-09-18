<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;

final class InventoryChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Inventory $before,
        public readonly Inventory $after,
    ) {}
}
