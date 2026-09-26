<?php

declare(strict_types=1);

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Immutable notification emitted after authoritative entity equipment commits. */
final class EntityEquipmentChangedEvent extends Event implements PostEvent
{
    public function __construct(
        public readonly LivingEntity $entity,
        public readonly EquipmentSlot $slot,
        public readonly ?ItemStack $previous,
        public readonly ?ItemStack $item,
        public readonly float $previousDropChance = 0.0,
        public readonly float $dropChance = 0.0,
    ) {
        self::validateDropChance($previousDropChance);
        self::validateDropChance($dropChance);
    }

    private static function validateDropChance(float $dropChance): void
    {
        if (!is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Entity equipment drop chance must be between 0 and 1.');
        }
    }
}
