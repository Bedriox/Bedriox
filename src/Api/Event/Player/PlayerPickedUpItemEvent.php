<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;

final class PlayerPickedUpItemEvent extends Event implements PostEvent
{
    public function __construct(public readonly Player $player, public readonly ItemStack $item) {}
}
