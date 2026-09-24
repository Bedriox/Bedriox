<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Immutable notification emitted after an authoritative item use commits. */
final class PlayerItemUsedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly ItemUseKind $kind,
        public readonly EquipmentSlot $hand,
        public readonly int $elapsedTicks,
    ) {
        if ($elapsedTicks < 0 || $elapsedTicks > 1_200) {
            throw new InvalidArgumentException('Elapsed item-use ticks must be between 0 and 1200.');
        }
    }
}
