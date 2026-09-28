<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;

/** Immutable armor snapshot with session-bound authoritative mutations. */
final readonly class ArmorInventory
{
    /** @var array<string, ItemStack|null> */
    private array $contents;

    /**
     * @param array<mixed> $contents
     */
    public function __construct(
        array $contents,
        private PlayerInventoryActions $actions,
    ) {
        $this->contents = self::normalizeContents($contents);
    }

    public function getItem(EquipmentSlot $slot): ?ItemStack
    {
        self::assertArmorSlot($slot);

        return $this->contents[$slot->value];
    }

    public function getHelmet(): ?ItemStack
    {
        return $this->getItem(EquipmentSlot::HEAD);
    }

    public function getChestplate(): ?ItemStack
    {
        return $this->getItem(EquipmentSlot::CHEST);
    }

    public function getLeggings(): ?ItemStack
    {
        return $this->getItem(EquipmentSlot::LEGS);
    }

    public function getBoots(): ?ItemStack
    {
        return $this->getItem(EquipmentSlot::FEET);
    }

    /** @return array<string, ItemStack|null> */
    public function getContents(): array
    {
        return $this->contents;
    }

    public function setItem(EquipmentSlot $slot, ?ItemStack $stack): void
    {
        self::assertArmorSlot($slot);
        $this->actions->setArmorItem($slot, $stack);
    }

    public function setHelmet(?ItemStack $stack): void
    {
        $this->setItem(EquipmentSlot::HEAD, $stack);
    }

    public function setChestplate(?ItemStack $stack): void
    {
        $this->setItem(EquipmentSlot::CHEST, $stack);
    }

    public function setLeggings(?ItemStack $stack): void
    {
        $this->setItem(EquipmentSlot::LEGS, $stack);
    }

    public function setBoots(?ItemStack $stack): void
    {
        $this->setItem(EquipmentSlot::FEET, $stack);
    }

    /** @param array<mixed> $contents */
    public function setContents(array $contents): void
    {
        $this->actions->setArmorContents(self::normalizeContents($contents));
    }

    public function clear(EquipmentSlot $slot): void
    {
        $this->setItem($slot, null);
    }

    public function clearAll(): void
    {
        $this->actions->setArmorContents(self::emptyContents());
    }

    public function isEmpty(): bool
    {
        foreach ($this->contents as $stack) {
            if ($stack !== null) {
                return false;
            }
        }

        return true;
    }

    /** @return array<string, null> */
    private static function emptyContents(): array
    {
        return [
            EquipmentSlot::HEAD->value => null,
            EquipmentSlot::CHEST->value => null,
            EquipmentSlot::LEGS->value => null,
            EquipmentSlot::FEET->value => null,
        ];
    }

    /**
     * @param array<mixed> $contents
     * @return array<string, ItemStack|null>
     */
    private static function normalizeContents(array $contents): array
    {
        if ($contents === []) {
            return self::emptyContents();
        }
        if (array_keys($contents) !== array_keys(self::emptyContents())) {
            throw new InvalidArgumentException('Armor contents must define head, chest, legs, and feet in slot order.');
        }
        $normalized = [];
        foreach ($contents as $slot => $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new InvalidArgumentException('Armor contents may only contain item stacks or null.');
            }
            $normalized[(string) $slot] = $stack;
        }

        return $normalized;
    }

    private static function assertArmorSlot(EquipmentSlot $slot): void
    {
        if (!$slot->isArmor()) {
            throw new InvalidArgumentException('The requested equipment slot is not an armor slot.');
        }
    }
}
