<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Version-independent player equipment slots exposed to plugins. */
enum EquipmentSlot: string
{
    case HEAD = 'head';
    case CHEST = 'chest';
    case LEGS = 'legs';
    case FEET = 'feet';
    case MAIN_HAND = 'main_hand';
    case OFF_HAND = 'off_hand';

    public function isArmor(): bool
    {
        return match ($this) {
            self::HEAD, self::CHEST, self::LEGS, self::FEET => true,
            self::MAIN_HAND, self::OFF_HAND => false,
        };
    }
}
