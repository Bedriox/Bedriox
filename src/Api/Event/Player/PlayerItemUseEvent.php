<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable intent emitted after validation and before an item use begins or applies. */
final class PlayerItemUseEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly ItemStack $item,
        public readonly ItemUseKind $kind,
        public readonly EquipmentSlot $hand,
        public readonly int $requiredTicks,
    ) {
        if ($requiredTicks < 0 || $requiredTicks > 1_200) {
            throw new InvalidArgumentException('Required item-use ticks must be between 0 and 1200.');
        }
    }
}
