<?php

declare(strict_types=1);

namespace Bedriox\Server\Inventory;

use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;
use LogicException;

/** Single-threaded staging area which validates every revision before committing any container. */
final class ContainerInventoryTransaction
{
    /** @var array<int, ContainerInventory> */
    private array $inventories = [];

    /** @var array<int, string> */
    private array $revisions = [];

    /** @var array<int, list<ItemStack|null>> */
    private array $before = [];

    /** @var array<int, list<ItemStack|null>> */
    private array $after = [];

    private bool $committed = false;

    public function __construct(ContainerInventory ...$inventories)
    {
        if ($inventories === [] || count($inventories) > 16) {
            throw new InvalidArgumentException('A container transaction must involve between one and sixteen inventories.');
        }
        foreach ($inventories as $inventory) {
            $id = spl_object_id($inventory);
            if (isset($this->inventories[$id])) {
                throw new InvalidArgumentException('A container transaction may not contain an inventory twice.');
            }
            $this->inventories[$id] = $inventory;
            $this->revisions[$id] = $inventory->revision();
            $this->before[$id] = $inventory->contents();
            $this->after[$id] = $inventory->contents();
        }
    }

    public function stage(ContainerInventory $inventory, int $slot, ?ItemStack $stack): void
    {
        $this->assertOpen();
        $id = spl_object_id($inventory);
        if (!isset($this->inventories[$id]) || $this->inventories[$id] !== $inventory) {
            throw new InvalidArgumentException('Inventory is not part of this container transaction.');
        }
        if ($slot < 0 || $slot >= $inventory->size()) {
            throw new InvalidArgumentException('Container transaction slot is outside the inventory.');
        }
        $updated = [];
        foreach ($this->after[$id] as $index => $current) {
            $updated[] = $index === $slot ? $stack : $current;
        }
        $this->after[$id] = $updated;
    }

    /** @return list<ItemStack|null> */
    public function before(ContainerInventory $inventory): array
    {
        return $this->snapshot($inventory, $this->before);
    }

    /** @return list<ItemStack|null> */
    public function after(ContainerInventory $inventory): array
    {
        return $this->snapshot($inventory, $this->after);
    }

    /** @throws ContainerRevisionMismatchException */
    public function commit(): bool
    {
        $this->assertOpen();
        foreach ($this->inventories as $id => $inventory) {
            if (!hash_equals($inventory->revision(), $this->revisions[$id])) {
                throw new ContainerRevisionMismatchException();
            }
        }

        $changed = false;
        foreach ($this->inventories as $id => $inventory) {
            $changed = $inventory->replaceContents($this->after[$id], $this->revisions[$id]) || $changed;
        }
        $this->committed = true;

        return $changed;
    }

    /**
     * @param array<int, list<ItemStack|null>> $snapshots
     * @return list<ItemStack|null>
     */
    private function snapshot(ContainerInventory $inventory, array $snapshots): array
    {
        $id = spl_object_id($inventory);
        if (!isset($this->inventories[$id]) || $this->inventories[$id] !== $inventory) {
            throw new InvalidArgumentException('Inventory is not part of this container transaction.');
        }

        return $snapshots[$id];
    }

    private function assertOpen(): void
    {
        if ($this->committed) {
            throw new LogicException('Container transaction has already committed.');
        }
    }
}
