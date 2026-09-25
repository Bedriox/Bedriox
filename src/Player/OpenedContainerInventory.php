<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use InvalidArgumentException;

/** Per-player network projection of one currently authorized storage container. */
final class OpenedContainerInventory
{
    /** @var array<int, InventoryStack> */
    private array $stacks;

    /** @var array<int, int> */
    private array $lastRequestIds = [];

    /** @param array<mixed> $stacks */
    public function __construct(
        public readonly string $identifier,
        public readonly int $size,
        array $stacks,
    ) {
        if ($identifier === '' || strlen($identifier) > 256 || $size < 1 || $size > 256) {
            throw new InvalidArgumentException('Opened container identity or size is invalid.');
        }
        $validated = [];
        foreach ($stacks as $slot => $stack) {
            if (!is_int($slot) || $slot < 0 || $slot >= $size || !$stack instanceof InventoryStack) {
                throw new InvalidArgumentException('Opened container contains an invalid slot entry.');
            }
            $validated[$slot] = $stack;
        }
        ksort($validated, SORT_NUMERIC);
        $this->stacks = $validated;
    }

    public function stackAt(int $slot): ?InventoryStack
    {
        $this->assertSlot($slot);

        return $this->stacks[$slot] ?? null;
    }

    /** @return list<InventoryStack|null> */
    public function slots(): array
    {
        $slots = array_fill(0, $this->size, null);
        foreach ($this->stacks as $slot => $stack) {
            $slots[$slot] = $stack;
        }

        return array_values($slots);
    }

    /** @return array<int, InventoryStack> */
    public function indexedStacks(): array
    {
        return $this->stacks;
    }

    /** @return array<int, int> */
    public function lastRequestIds(): array
    {
        return $this->lastRequestIds;
    }

    /**
     * @param array<mixed> $stacks
     * @param array<mixed> $lastRequestIds
     */
    public function commit(array $stacks, array $lastRequestIds): void
    {
        $validatedStacks = [];
        foreach ($stacks as $slot => $stack) {
            if (!is_int($slot) || $slot < 0 || $slot >= $this->size || !$stack instanceof InventoryStack) {
                throw new InvalidArgumentException('Committed container state contains an invalid slot entry.');
            }
            $validatedStacks[$slot] = $stack;
        }
        $validatedRequestIds = [];
        foreach ($lastRequestIds as $slot => $requestId) {
            if (!is_int($slot) || $slot < 0 || $slot >= $this->size || !is_int($requestId)) {
                throw new InvalidArgumentException('Committed container request lineage is invalid.');
            }
            $validatedRequestIds[$slot] = $requestId;
        }
        ksort($validatedStacks, SORT_NUMERIC);
        ksort($validatedRequestIds, SORT_NUMERIC);
        $this->stacks = $validatedStacks;
        $this->lastRequestIds = $validatedRequestIds;
    }

    private function assertSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= $this->size) {
            throw new InvalidArgumentException('Opened container slot is outside its inventory.');
        }
    }
}
