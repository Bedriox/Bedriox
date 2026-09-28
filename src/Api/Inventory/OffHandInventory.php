<?php

declare(strict_types=1);

namespace Bedriox\Api\Inventory;

/** Immutable off-hand snapshot with session-bound authoritative mutations. */
final readonly class OffHandInventory
{
    public function __construct(
        private ?ItemStack $item,
        private PlayerInventoryActions $actions,
    ) {}

    public function getItem(): ?ItemStack
    {
        return $this->item;
    }

    public function setItem(?ItemStack $stack): void
    {
        $this->actions->setOffHandItem($stack);
    }

    public function clear(): void
    {
        $this->setItem(null);
    }

    public function isEmpty(): bool
    {
        return $this->item === null;
    }
}
