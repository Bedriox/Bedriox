<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\InventoryTransaction;
use Bedriox\Api\Player\Player;

/** Published after every inventory in an atomic transaction commits successfully. */
final class InventoryTransactionCommittedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly InventoryTransaction $transaction,
    ) {}
}
