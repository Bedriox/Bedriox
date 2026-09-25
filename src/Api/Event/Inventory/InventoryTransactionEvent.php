<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\InventoryTransaction;
use Bedriox\Api\Player\Player;

/** Runs after complete transaction validation and before any involved inventory is mutated. */
final class InventoryTransactionEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly InventoryTransaction $transaction,
    ) {}
}
