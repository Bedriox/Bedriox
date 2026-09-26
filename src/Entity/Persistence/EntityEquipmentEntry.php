<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Persistence;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final readonly class EntityEquipmentEntry
{
    public function __construct(
        public string $slot,
        public string $itemIdentifier,
        public int $count = 1,
        public int $damage = 0,
        public int $auxValue = 0,
        public string $customData = '',
        public float $dropChance = 0.0,
    ) {
        if (EquipmentSlot::tryFrom($slot) === null) {
            throw new InvalidArgumentException('Entity equipment slot is invalid.');
        }
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $itemIdentifier) !== 1
            || strlen($itemIdentifier) > EntityPersistenceLimits::MAX_IDENTIFIER_BYTES) {
            throw new InvalidArgumentException('Entity equipment item identifier is invalid.');
        }
        if ($count < 1 || $count > 64 || $damage < 0 || $damage > 65_535
            || $auxValue < 0 || $auxValue > 32_767
            || strlen($customData) > EntityPersistenceLimits::MAX_ITEM_DATA_BYTES
            || !is_finite($dropChance) || $dropChance < 0.0 || $dropChance > 1.0) {
            throw new InvalidArgumentException('Entity equipment state is outside its supported bounds.');
        }
        if ($customData !== '') {
            ItemNbt::fromBinary($customData);
        }
    }

    public function equipmentSlot(): EquipmentSlot
    {
        return EquipmentSlot::from($this->slot);
    }

    public function itemStack(): ItemStack
    {
        return new ItemStack(
            $this->itemIdentifier,
            $this->count,
            $this->damage,
            $this->customData === '' ? null : ItemNbt::fromBinary($this->customData),
            $this->auxValue,
        );
    }
}
