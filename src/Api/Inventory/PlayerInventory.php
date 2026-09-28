<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

use Closure;
use InvalidArgumentException;
use LogicException;

/** Immutable main-inventory snapshot with session-bound authoritative mutations. */
final readonly class PlayerInventory
{
    /**
     * @param Closure(ItemStack): int|null $maximumStackSize
     */
    public function __construct(
        private Inventory $snapshot,
        private PlayerInventoryActions $actions,
        private ?Closure $maximumStackSize = null,
    ) {}

    public function getSize(): int
    {
        return count($this->snapshot->slots);
    }

    public function getSelectedHotbarSlot(): int
    {
        return $this->snapshot->selectedHotbarSlot;
    }

    public function getHeldItem(): ?ItemStack
    {
        return $this->snapshot->stackAt($this->snapshot->selectedHotbarSlot);
    }

    public function getItem(int $slot): ?ItemStack
    {
        return $this->snapshot->stackAt($slot);
    }

    /** @return list<ItemStack|null> */
    public function getContents(): array
    {
        return $this->snapshot->slots;
    }

    public function isEmpty(): bool
    {
        foreach ($this->snapshot->slots as $stack) {
            if ($stack !== null) {
                return false;
            }
        }

        return true;
    }

    public function contains(ItemStack $stack): bool
    {
        $count = 0;
        foreach ($this->snapshot->slots as $existing) {
            if ($existing !== null && self::sameType($existing, $stack)) {
                $count += $existing->count;
                if ($count >= $stack->count) {
                    return true;
                }
            }
        }

        return false;
    }

    public function getAddableQuantity(ItemStack $stack): int
    {
        if ($this->maximumStackSize === null) {
            throw new LogicException('Stack-size rules are unavailable for this inventory snapshot.');
        }
        $maximum = ($this->maximumStackSize)($stack);
        if ($maximum < 1 || $maximum > 64) {
            throw new LogicException('The authoritative item stack size is outside the supported range.');
        }

        $quantity = 0;
        foreach ($this->snapshot->slots as $existing) {
            if ($existing === null) {
                $quantity += $maximum;
            } elseif (self::sameType($existing, $stack)) {
                $quantity += max(0, $maximum - $existing->count);
            }
        }

        return $quantity;
    }

    public function firstEmpty(): ?int
    {
        foreach ($this->snapshot->slots as $slot => $stack) {
            if ($stack === null) {
                return $slot;
            }
        }

        return null;
    }

    public function setItem(int $slot, ?ItemStack $stack): void
    {
        $this->assertSlot($slot);
        $this->actions->setMainItem($slot, $stack);
    }

    /** @param array<mixed> $contents */
    public function setContents(array $contents): void
    {
        if (!array_is_list($contents) || count($contents) !== $this->getSize()) {
            throw new InvalidArgumentException('Inventory contents must be a list matching the inventory size.');
        }
        $normalized = [];
        foreach ($contents as $stack) {
            if ($stack !== null && !$stack instanceof ItemStack) {
                throw new InvalidArgumentException('Inventory contents may only contain item stacks or null.');
            }
            $normalized[] = $stack;
        }
        $this->actions->setMainContents($normalized);
    }

    public function addItem(ItemStack $stack): void
    {
        $this->actions->addMainItem($stack);
    }

    public function removeItem(ItemStack $stack): void
    {
        $this->actions->removeMainItem($stack);
    }

    public function clear(int $slot): void
    {
        $this->setItem($slot, null);
    }

    public function clearAll(): void
    {
        $this->actions->setMainContents(array_fill(0, $this->getSize(), null));
    }

    public function setSelectedHotbarSlot(int $slot): void
    {
        if ($slot < 0 || $slot > 8 || $slot >= $this->getSize()) {
            throw new InvalidArgumentException('Selected hotbar slot must be between 0 and 8.');
        }
        $this->actions->setSelectedHotbarSlot($slot);
    }

    private function assertSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= $this->getSize()) {
            throw new InvalidArgumentException('Inventory slot is outside the inventory.');
        }
    }

    private static function sameType(ItemStack $left, ItemStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && $left->nbt == $right->nbt;
    }
}
