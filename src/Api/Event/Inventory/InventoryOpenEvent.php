<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Player\Player;

/** Runs after access validation and before an authoritative container session is opened. */
final class InventoryOpenEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ContainerView $container,
        public readonly ?Container $handle = null,
    ) {}
}
