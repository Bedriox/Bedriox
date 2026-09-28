<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use Closure;
use LogicException;

/** @internal Server-owned authoritative action path attached to live player inventories. */
final readonly class PlayerInventoryActions
{
    /**
     * @param Closure(int, ItemStack|null): void             $setMainItem
     * @param Closure(list<ItemStack|null>): void            $setMainContents
     * @param Closure(ItemStack): void                       $addMainItem
     * @param Closure(ItemStack): void                       $removeMainItem
     * @param Closure(int): void                             $setSelectedHotbarSlot
     * @param Closure(EquipmentSlot, ItemStack|null): void   $setArmorItem
     * @param Closure(array<string, ItemStack|null>): void   $setArmorContents
     * @param Closure(ItemStack|null): void                  $setOffHandItem
     */
    public function __construct(
        private Closure $setMainItem,
        private Closure $setMainContents,
        private Closure $addMainItem,
        private Closure $removeMainItem,
        private Closure $setSelectedHotbarSlot,
        private Closure $setArmorItem,
        private Closure $setArmorContents,
        private Closure $setOffHandItem,
    ) {}

    public static function unavailable(): self
    {
        $unavailable = static function (): never {
            throw new LogicException('This inventory snapshot is not attached to an authoritative runtime.');
        };

        return new self(
            $unavailable,
            $unavailable,
            $unavailable,
            $unavailable,
            $unavailable,
            $unavailable,
            $unavailable,
            $unavailable,
        );
    }

    public function setMainItem(int $slot, ?ItemStack $stack): void
    {
        ($this->setMainItem)($slot, $stack);
    }

    /** @param list<ItemStack|null> $contents */
    public function setMainContents(array $contents): void
    {
        ($this->setMainContents)($contents);
    }

    public function addMainItem(ItemStack $stack): void
    {
        ($this->addMainItem)($stack);
    }

    public function removeMainItem(ItemStack $stack): void
    {
        ($this->removeMainItem)($stack);
    }

    public function setSelectedHotbarSlot(int $slot): void
    {
        ($this->setSelectedHotbarSlot)($slot);
    }

    public function setArmorItem(EquipmentSlot $slot, ?ItemStack $stack): void
    {
        ($this->setArmorItem)($slot, $stack);
    }

    /** @param array<string, ItemStack|null> $contents */
    public function setArmorContents(array $contents): void
    {
        ($this->setArmorContents)($contents);
    }

    public function setOffHandItem(?ItemStack $stack): void
    {
        ($this->setOffHandItem)($stack);
    }
}
