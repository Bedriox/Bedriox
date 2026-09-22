<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use InvalidArgumentException;
use OverflowException;

/** Fixed-size survival inventory owned by the authoritative Player aggregate. */
final class PlayerInventory
{
    public const int SLOT_COUNT = 36;
    public const int HOTBAR_SIZE = 9;

    /** @var array<int, int> Last request ID which changed each main slot. */
    private array $lastRequestIds = [];

    private ?int $cursorLastRequestId = null;

    /** @param array<int, InventoryStack> $stacks */
    private function __construct(
        private array $stacks = [],
        private int $selectedHotbarSlot = 0,
        private ?InventoryStack $cursor = null,
        private int $nextStackNetworkId = 2,
    ) {
        foreach ($stacks as $slot => $stack) {
            self::validateSlot($slot);
        }
        self::validateHotbarSlot($selectedHotbarSlot);
    }

    public static function empty(): self
    {
        return new self();
    }

    public static function starter(FixedFlatBlockPalette $palette): self
    {
        return new self([
            0 => new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock),
        ]);
    }

    /** Restores canonical content while assigning fresh play-session stack network IDs. */
    public static function restore(PlayerInventoryState $state, FixedFlatBlockPalette $palette): self
    {
        $stacks = [];
        $nextStackNetworkId = 1;
        foreach ($state->entries as $entry) {
            $stacks[$entry->slot] = self::restoreStack($entry->stack, $palette, $nextStackNetworkId++);
        }
        $cursor = $state->cursor === null
            ? null
            : self::restoreStack($state->cursor, $palette, $nextStackNetworkId++);

        return new self($stacks, $state->selectedHotbarSlot, $cursor, $nextStackNetworkId);
    }

    public function exportState(): PlayerInventoryState
    {
        $entries = [];
        foreach ($this->stacks as $slot => $stack) {
            $entries[] = new PlayerInventoryEntry(
                $slot,
                new PlayerInventoryStackState($stack->identifier, $stack->count),
            );
        }

        return new PlayerInventoryState(
            $entries,
            $this->selectedHotbarSlot,
            $this->cursor === null
                ? null
                : new PlayerInventoryStackState($this->cursor->identifier, $this->cursor->count),
        );
    }

    public function selectedHotbarSlot(): int
    {
        return $this->selectedHotbarSlot;
    }

    public function selectedStack(): ?InventoryStack
    {
        return $this->stacks[$this->selectedHotbarSlot] ?? null;
    }

    public function stackAt(int $slot): ?InventoryStack
    {
        self::validateSlot($slot);

        return $this->stacks[$slot] ?? null;
    }

    public function cursorStack(): ?InventoryStack
    {
        return $this->cursor;
    }

    /** @return list<InventoryStack|null> */
    public function slots(): array
    {
        $slots = array_fill(0, self::SLOT_COUNT, null);
        foreach ($this->stacks as $slot => $stack) {
            $slots[$slot] = $stack;
        }

        return array_values($slots);
    }

    public function selectHotbarSlot(int $slot): void
    {
        self::validateHotbarSlot($slot);
        $this->selectedHotbarSlot = $slot;
    }

    public function replaceSlot(int $slot, ?InventoryStack $stack): void
    {
        self::validateSlot($slot);
        if ($stack === null) {
            unset($this->stacks[$slot]);

            return;
        }
        $usedIds = [];
        foreach ($this->stacks as $existing) {
            $usedIds[$existing->stackNetworkId] = true;
        }
        if ($this->cursor !== null) {
            $usedIds[$this->cursor->stackNetworkId] = true;
        }
        $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $usedIds);
        if ($networkId === null) {
            throw new OverflowException('Inventory stack network ID space is exhausted.');
        }
        $this->stacks[$slot] = $stack->withCountAndNetworkId($stack->count, $networkId);
    }

    public function decrementSelectedOne(): ?InventoryStack
    {
        $slot = $this->selectedHotbarSlot;
        $stack = $this->stacks[$slot] ?? null;
        if ($stack === null) {
            return null;
        }
        $replacement = $stack->decrement();
        if ($replacement === null) {
            unset($this->stacks[$slot]);
        } else {
            $this->stacks[$slot] = $replacement;
        }

        return $replacement;
    }

    /**
     * Applies one PMMP-style item-stack request atomically against staged authoritative state.
     *
     * @param list<InventoryStackRequestAction> $actions
     */
    public function applyStackRequest(int $requestId, array $actions): InventoryStackRequestResult
    {
        if ($actions === []) {
            return new InventoryStackRequestResult(false, reason: 'empty_actions');
        }
        $stagedStacks = $this->stacks;
        $stagedCursor = $this->cursor;
        $stagedLastRequestIds = $this->lastRequestIds;
        $stagedCursorLastRequestId = $this->cursorLastRequestId;
        $affected = [];
        $mutated = [];

        foreach ($actions as $action) {
            $reason = $this->validateReference(
                $action->source,
                $requestId,
                $stagedStacks,
                $stagedCursor,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
            ) ?? $this->validateReference(
                $action->destination,
                $requestId,
                $stagedStacks,
                $stagedCursor,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
            );
            if ($reason !== null) {
                return new InventoryStackRequestResult(false, reason: $reason);
            }
            if ($action->source->key() === $action->destination->key()) {
                return new InventoryStackRequestResult(false, reason: 'same_slot');
            }
            $affected[$action->source->responseKey()] = $action->source;
            $affected[$action->destination->responseKey()] = $action->destination;
            $mutated[$action->source->key()] = $action->source;
            $mutated[$action->destination->key()] = $action->destination;

            if ($action->type === InventoryStackRequestActionType::Swap) {
                $source = self::readSlot($action->source, $stagedStacks, $stagedCursor);
                $destination = self::readSlot($action->destination, $stagedStacks, $stagedCursor);
                self::writeSlot($action->source, $destination, $stagedStacks, $stagedCursor);
                self::writeSlot($action->destination, $source, $stagedStacks, $stagedCursor);
            } else {
                if ($action->count < 1) {
                    return new InventoryStackRequestResult(false, reason: 'invalid_count');
                }
                $source = self::readSlot($action->source, $stagedStacks, $stagedCursor);
                if ($source === null || $source->count < $action->count) {
                    return new InventoryStackRequestResult(false, reason: 'source_count');
                }
                $sourceRemaining = $source->count === $action->count
                    ? null
                    : $source->withCountAndNetworkId($source->count - $action->count, $source->stackNetworkId);
                self::writeSlot($action->source, $sourceRemaining, $stagedStacks, $stagedCursor);
                $destination = self::readSlot($action->destination, $stagedStacks, $stagedCursor);
                if ($destination !== null && !self::canStack($source, $destination)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_item');
                }
                $existingCount = $destination === null ? 0 : $destination->count;
                if ($existingCount + $action->count > SupportedInventoryItem::maximumStackSize($source->identifier)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_capacity');
                }
                $destinationCount = $existingCount + $action->count;
                $destinationResult = ($destination ?? $source)->withCountAndNetworkId(
                    $destinationCount,
                    ($destination ?? $source)->stackNetworkId,
                );
                self::writeSlot($action->destination, $destinationResult, $stagedStacks, $stagedCursor);
            }

            self::writeLastRequestId(
                $action->source,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
            );
            self::writeLastRequestId(
                $action->destination,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
            );
        }

        $selectedBefore = $this->selectedStack();
        $usedIds = [];
        foreach ($stagedStacks as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        if ($stagedCursor !== null) {
            $usedIds[$stagedCursor->stackNetworkId] = true;
        }
        $nextId = $this->nextStackNetworkId;
        foreach ($mutated as $reference) {
            $before = $this->readAuthoritativeSlot($reference);
            $after = self::readSlot($reference, $stagedStacks, $stagedCursor);
            if (self::sameContent($before, $after)) {
                self::writeLastRequestId(
                    $reference,
                    $this->readLastRequestId($reference),
                    $stagedLastRequestIds,
                    $stagedCursorLastRequestId,
                );
                continue;
            }
            if ($after !== null) {
                $id = self::allocateStackNetworkId($nextId, $usedIds);
                if ($id === null) {
                    return new InventoryStackRequestResult(false, reason: 'stack_id_capacity');
                }
                $after = $after->withCountAndNetworkId($after->count, $id);
                self::writeSlot($reference, $after, $stagedStacks, $stagedCursor);
                $usedIds[$id] = true;
            }
        }

        $this->stacks = $stagedStacks;
        $this->cursor = $stagedCursor;
        $this->lastRequestIds = $stagedLastRequestIds;
        $this->cursorLastRequestId = $stagedCursorLastRequestId;
        $this->nextStackNetworkId = $nextId;

        return new InventoryStackRequestResult(
            true,
            array_values($affected),
            selectedStackChanged: !self::sameContent($selectedBefore, $this->selectedStack()),
        );
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, int> $lastRequestIds
     */
    private function validateReference(
        InventorySlotReference $reference,
        int $requestId,
        array $stacks,
        ?InventoryStack $cursor,
        array $lastRequestIds,
        ?int $cursorLastRequestId,
    ): ?string {
        if (($reference->container === InventoryContainer::Main && ($reference->slot < 0 || $reference->slot >= self::SLOT_COUNT))
            || ($reference->container === InventoryContainer::Cursor && $reference->slot !== 0)) {
            return 'slot';
        }
        $stack = self::readSlot($reference, $stacks, $cursor);
        $stackCount = $stack === null ? 0 : $stack->count;
        if ($reference->expectedCount !== null && $stackCount !== $reference->expectedCount) {
            return 'stack_count';
        }
        $lastRequestId = $reference->container === InventoryContainer::Main
            ? ($lastRequestIds[$reference->slot] ?? null)
            : $cursorLastRequestId;
        $matches = $reference->expectedStackNetworkId < 0
            ? $lastRequestId === $reference->expectedStackNetworkId
                || $reference->expectedStackNetworkId === $requestId
            : ($stack === null ? 0 : $stack->stackNetworkId) === $reference->expectedStackNetworkId;

        return $matches ? null : 'stack_network_id';
    }

    /** @param array<int, InventoryStack> $stacks */
    private static function readSlot(
        InventorySlotReference $reference,
        array $stacks,
        ?InventoryStack $cursor,
    ): ?InventoryStack {
        return $reference->container === InventoryContainer::Main
            ? ($stacks[$reference->slot] ?? null)
            : $cursor;
    }

    /** @param array<int, InventoryStack> $stacks */
    private static function writeSlot(
        InventorySlotReference $reference,
        ?InventoryStack $stack,
        array &$stacks,
        ?InventoryStack &$cursor,
    ): void {
        if ($reference->container === InventoryContainer::Cursor) {
            $cursor = $stack;
        } elseif ($stack === null) {
            unset($stacks[$reference->slot]);
        } else {
            $stacks[$reference->slot] = $stack;
        }
    }

    /** @param array<int, int> $lastRequestIds */
    private static function writeLastRequestId(
        InventorySlotReference $reference,
        ?int $requestId,
        array &$lastRequestIds,
        ?int &$cursorLastRequestId,
    ): void {
        if ($reference->container === InventoryContainer::Cursor) {
            $cursorLastRequestId = $requestId;
        } elseif ($requestId === null) {
            unset($lastRequestIds[$reference->slot]);
        } else {
            $lastRequestIds[$reference->slot] = $requestId;
        }
    }

    private function readAuthoritativeSlot(InventorySlotReference $reference): ?InventoryStack
    {
        return $reference->container === InventoryContainer::Main
            ? ($this->stacks[$reference->slot] ?? null)
            : $this->cursor;
    }

    private function readLastRequestId(InventorySlotReference $reference): ?int
    {
        return $reference->container === InventoryContainer::Main
            ? ($this->lastRequestIds[$reference->slot] ?? null)
            : $this->cursorLastRequestId;
    }

    private static function canStack(InventoryStack $left, InventoryStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->placedBlockState?->value === $right->placedBlockState?->value;
    }

    private static function restoreStack(
        PlayerInventoryStackState $state,
        FixedFlatBlockPalette $palette,
        int $stackNetworkId,
    ): InventoryStack {
        if (!SupportedInventoryItem::supports($state->identifier)
            || $state->count > SupportedInventoryItem::maximumStackSize($state->identifier)) {
            throw new InvalidArgumentException('Inventory state contains an unsupported item.');
        }

        return new InventoryStack(
            $state->identifier,
            $state->count,
            $stackNetworkId,
            $state->identifier === 'minecraft:grass_block' ? $palette->grassBlock : null,
        );
    }

    private static function sameContent(?InventoryStack $left, ?InventoryStack $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && self::canStack($left, $right) && $left->count === $right->count);
    }

    /** @param array<int, true> $usedIds */
    private static function allocateStackNetworkId(int &$nextId, array $usedIds): ?int
    {
        for ($attempt = 0; $attempt < self::SLOT_COUNT + 2; ++$attempt) {
            if ($nextId < 1 || $nextId > 0x7fffffff) {
                $nextId = 1;
            }
            $candidate = $nextId++;
            if (!isset($usedIds[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    private static function validateSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= self::SLOT_COUNT) {
            throw new InvalidArgumentException('Inventory slot is outside the main inventory.');
        }
    }

    private static function validateHotbarSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= self::HOTBAR_SIZE) {
            throw new InvalidArgumentException('Selected slot is outside the hotbar.');
        }
    }
}
