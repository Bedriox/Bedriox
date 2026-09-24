<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemDamageCause;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;

/** Immutable notification emitted after a durability change breaks an item. */
final class PlayerItemBreakEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly ItemDamageCause $cause,
        public readonly EquipmentSlot $slot,
    ) {}
}
