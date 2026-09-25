<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Inventory;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Player\Player;

/** Published after the authoritative container session and client window are established. */
final class InventoryOpenedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ContainerView $container,
        public readonly ?Container $handle = null,
    ) {}
}
