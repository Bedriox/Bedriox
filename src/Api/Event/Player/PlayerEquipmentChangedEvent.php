<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;

/** Immutable notification emitted after authoritative equipment commits. */
final class PlayerEquipmentChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly EquipmentSlot $slot,
        public readonly ?ItemStack $previous,
        public readonly ?ItemStack $item,
    ) {}
}
