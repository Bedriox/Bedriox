<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use InvalidArgumentException;
use OverflowException;

/** Fixed-size survival inventory owned by the authoritative Player aggregate. */
final class PlayerInventory
{
    public const int SLOT_COUNT = 36;
    public const int HOTBAR_SIZE = 9;
    public const int ARMOR_SLOT_COUNT = 4;
    public const int ENDER_CHEST_SLOT_COUNT = 27;

    /** @var array<int, int> Last request ID which changed each main slot. */
    private array $lastRequestIds = [];

    private ?int $cursorLastRequestId = null;

    /** @var array<int, int> Last request ID which changed each armor slot. */
    private array $armorLastRequestIds = [];

    private ?int $offhandLastRequestId = null;

    /** @var array<int, InventoryStack> Ephemeral crafting input slots. */
    private array $crafting = [];

    /** @var array<int, int> Last request ID which changed each crafting input slot. */
    private array $craftingLastRequestIds = [];

    private int $craftingGridWidth = 2;

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $enderChest
     */
    private function __construct(
        private array $stacks = [],
        private int $selectedHotbarSlot = 0,
        private ?InventoryStack $cursor = null,
        private int $nextStackNetworkId = 2,
        private ?ItemCatalog $catalog = null,
        private array $armor = [],
        private ?InventoryStack $offhand = null,
        private array $enderChest = [],
    ) {
        foreach ($stacks as $slot => $stack) {
            self::validateSlot($slot);
        }
        self::validateHotbarSlot($selectedHotbarSlot);
        foreach ($armor as $slot => $stack) {
            self::validateArmorSlot($slot);
            if (!$this->acceptsArmor($slot, $stack)) {
                throw new InvalidArgumentException('Inventory armor stack does not match its slot.');
            }
        }
        if ($offhand !== null && !$this->acceptsOffhand($offhand)) {
            throw new InvalidArgumentException('Inventory item is not accepted by the offhand slot.');
        }
        foreach ($enderChest as $slot => $_stack) {
            self::validateEnderChestSlot($slot);
        }
    }

    public static function empty(?ItemCatalog $catalog = null): self
    {
        return new self(catalog: $catalog);
    }

    public static function starter(FixedFlatBlockPalette $palette, ?ItemCatalog $catalog = null): self
    {
        return new self([
            0 => new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock),
        ], catalog: $catalog);
    }

    /** Restores canonical content while assigning fresh play-session stack network IDs. */
    public static function restore(
        PlayerInventoryState $state,
        FixedFlatBlockPalette $palette,
        ?ItemCatalog $catalog = null,
        ?BlockStateRegistry $blockStates = null,
    ): self {
        $stacks = [];
        $nextStackNetworkId = 1;
        foreach ($state->entries as $entry) {
            $stacks[$entry->slot] = self::restoreStack(
                $entry->stack,
                $palette,
                $nextStackNetworkId++,
                $catalog,
                $blockStates,
            );
        }
        $cursor = $state->cursor === null
            ? null
            : self::restoreStack($state->cursor, $palette, $nextStackNetworkId++, $catalog, $blockStates);

        $armor = [];
        foreach ($state->armor as $entry) {
            $armor[$entry->slot] = self::restoreStack(
                $entry->stack,
                $palette,
                $nextStackNetworkId++,
                $catalog,
                $blockStates,
            );
        }
        $offhand = $state->offhand === null
            ? null
            : self::restoreStack($state->offhand, $palette, $nextStackNetworkId++, $catalog, $blockStates);

        $enderChest = [];
        foreach ($state->enderChest as $entry) {
            $enderChest[$entry->slot] = self::restoreStack(
                $entry->stack,
                $palette,
                $nextStackNetworkId++,
                $catalog,
                $blockStates,
            );
        }

        return new self(
            $stacks,
            $state->selectedHotbarSlot,
            $cursor,
            $nextStackNetworkId,
            $catalog,
            $armor,
            $offhand,
            $enderChest,
        );
    }

    public function exportState(): PlayerInventoryState
    {
        $entries = [];
        foreach ($this->stacks as $slot => $stack) {
            $entries[] = new PlayerInventoryEntry(
                $slot,
                new PlayerInventoryStackState(
                    $stack->identifier,
                    $stack->count,
                    $stack->damage,
                    $stack->nbt,
                    $stack->auxValue,
                ),
            );
        }
        $armor = [];
        foreach ($this->armor as $slot => $stack) {
            $armor[] = new PlayerInventoryEntry(
                $slot,
                self::exportStack($stack),
            );
        }
        $enderChest = [];
        foreach ($this->enderChest as $slot => $stack) {
            $enderChest[] = new PlayerInventoryEntry(
                $slot,
                self::exportStack($stack),
            );
        }

        return new PlayerInventoryState(
            $entries,
            $this->selectedHotbarSlot,
            $this->cursor === null
                ? null
                : new PlayerInventoryStackState(
                    $this->cursor->identifier,
                    $this->cursor->count,
                    $this->cursor->damage,
                    $this->cursor->nbt,
                    $this->cursor->auxValue,
                ),
            $armor,
            $this->offhand === null ? null : self::exportStack($this->offhand),
            $enderChest,
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

    public function armorStack(ArmorSlot|int $slot): ?InventoryStack
    {
        $slotId = $slot instanceof ArmorSlot ? $slot->value : $slot;
        self::validateArmorSlot($slotId);

        return $this->armor[$slotId] ?? null;
    }

    /** @return list<InventoryStack|null> */
    public function armorSlots(): array
    {
        $slots = array_fill(0, self::ARMOR_SLOT_COUNT, null);
        foreach ($this->armor as $slot => $stack) {
            $slots[$slot] = $stack;
        }

        return array_values($slots);
    }

    public function offhandStack(): ?InventoryStack
    {
        return $this->offhand;
    }

    public function enderChestStack(int $slot): ?InventoryStack
    {
        self::validateEnderChestSlot($slot);

        return $this->enderChest[$slot] ?? null;
    }

    /** @return list<InventoryStack|null> */
    public function enderChestSlots(): array
    {
        $slots = array_fill(0, self::ENDER_CHEST_SLOT_COUNT, null);
        foreach ($this->enderChest as $slot => $stack) {
            $slots[$slot] = $stack;
        }

        return array_values($slots);
    }

    /**
     * Replaces one player-owned Ender Chest slot.
     *
     * The return value tells the authoritative simulation whether persisted player state changed.
     */
    public function replaceEnderChestSlot(int $slot, ?InventoryStack $stack): bool
    {
        self::validateEnderChestSlot($slot);
        $current = $this->enderChest[$slot] ?? null;
        if (self::sameContent($current, $stack)) {
            return false;
        }
        if ($stack === null) {
            unset($this->enderChest[$slot]);

            return true;
        }
        if ($stack->count > $this->maximumStackSize($stack->identifier)) {
            throw new InvalidArgumentException('Ender Chest stack exceeds the item capacity.');
        }
        $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $this->usedNetworkIds());
        if ($networkId === null) {
            throw new OverflowException('Inventory stack network ID space is exhausted.');
        }
        $this->enderChest[$slot] = $stack->withCountAndNetworkId($stack->count, $networkId);

        return true;
    }

    public function craftingGridWidth(): int
    {
        return $this->craftingGridWidth;
    }

    public function setCraftingGridWidth(int $width): void
    {
        if (!in_array($width, [2, 3], true)) {
            throw new InvalidArgumentException('Crafting grid width must be two or three.');
        }
        if ($width === 2) {
            foreach (array_keys($this->crafting) as $slot) {
                if ($slot >= 4) {
                    throw new InvalidArgumentException('The three-by-three crafting grid must be emptied before closing.');
                }
            }
        }
        $this->craftingGridWidth = $width;
    }

    public function craftingStack(int $slot): ?InventoryStack
    {
        $this->validateCraftingSlot($slot);

        return $this->crafting[$slot] ?? null;
    }

    /** @return list<InventoryStack|null> */
    public function craftingSlots(): array
    {
        $slots = array_fill(0, $this->craftingGridWidth ** 2, null);
        foreach ($this->crafting as $slot => $stack) {
            if ($slot < count($slots)) {
                $slots[$slot] = $stack;
            }
        }

        return array_values($slots);
    }

    /** @return list<InventoryStack> Stacks which could not be restored to the main inventory. */
    public function closeCraftingGrid(): array
    {
        $contents = array_values($this->crafting);
        $this->crafting = [];
        $this->craftingLastRequestIds = [];
        $this->craftingGridWidth = 2;
        $overflow = [];
        foreach ($contents as $stack) {
            $remaining = $this->add($stack);
            if ($remaining !== null) {
                $overflow[] = $remaining;
            }
        }

        return $overflow;
    }

    public function defensePoints(): int
    {
        $points = 0;
        foreach ($this->armor as $stack) {
            $armor = $this->catalog === null ? null : $this->catalog->type($stack->identifier)->armor;
            $points += $armor === null ? 0 : $armor->defensePoints;
        }

        return min(20, $points);
    }

    public function knockbackResistance(): float
    {
        $resistance = 0.0;
        foreach ($this->armor as $stack) {
            $armor = $this->catalog === null ? null : $this->catalog->type($stack->identifier)->armor;
            $resistance += $armor === null ? 0.0 : $armor->knockbackResistance;
        }

        return min(1.0, $resistance);
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
        if ($stack->count > $this->maximumStackSize($stack->identifier)) {
            throw new InvalidArgumentException('Inventory stack exceeds the item capacity.');
        }
        $usedIds = [];
        foreach ($this->stacks as $existing) {
            $usedIds[$existing->stackNetworkId] = true;
        }
        if ($this->cursor !== null) {
            $usedIds[$this->cursor->stackNetworkId] = true;
        }
        foreach ($this->armor as $existing) {
            $usedIds[$existing->stackNetworkId] = true;
        }
        if ($this->offhand !== null) {
            $usedIds[$this->offhand->stackNetworkId] = true;
        }
        $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $usedIds);
        if ($networkId === null) {
            throw new OverflowException('Inventory stack network ID space is exhausted.');
        }
        $this->stacks[$slot] = $stack->withCountAndNetworkId($stack->count, $networkId);
    }

    public function replaceArmorSlot(ArmorSlot|int $slot, ?InventoryStack $stack): void
    {
        $slotId = $slot instanceof ArmorSlot ? $slot->value : $slot;
        self::validateArmorSlot($slotId);
        if ($stack !== null && !$this->acceptsArmor($slotId, $stack)) {
            throw new InvalidArgumentException('Item is not accepted by the requested armor slot.');
        }
        if ($stack === null) {
            unset($this->armor[$slotId]);

            return;
        }
        $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $this->usedNetworkIds());
        if ($networkId === null) {
            throw new OverflowException('Inventory stack network ID space is exhausted.');
        }
        $this->armor[$slotId] = $stack->withCountAndNetworkId(1, $networkId);
    }

    public function replaceOffhand(?InventoryStack $stack): void
    {
        if ($stack === null) {
            $this->offhand = null;

            return;
        }
        if ($stack->count > $this->maximumStackSize($stack->identifier)) {
            throw new InvalidArgumentException('Offhand stack exceeds the item capacity.');
        }
        if (!$this->acceptsOffhand($stack)) {
            throw new InvalidArgumentException('Item is not accepted by the offhand slot.');
        }
        $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $this->usedNetworkIds());
        if ($networkId === null) {
            throw new OverflowException('Inventory stack network ID space is exhausted.');
        }
        $this->offhand = $stack->withCountAndNetworkId($stack->count, $networkId);
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

    public function removeForDrop(
        int $requestId,
        InventorySlotReference $source,
        int $count,
        ?InventoryStack $expectedStack = null,
    ): InventoryStackRemovalResult {
        if (!in_array($source->container, [
            InventoryContainer::Main,
            InventoryContainer::Cursor,
            InventoryContainer::Armor,
            InventoryContainer::Offhand,
            InventoryContainer::CraftingInput,
        ], true)
            || ($source->container === InventoryContainer::Main && ($source->slot < 0 || $source->slot >= self::SLOT_COUNT))
            || ($source->container === InventoryContainer::Cursor && $source->slot !== 0)
            || ($source->container === InventoryContainer::Armor
                && ($source->slot < 0 || $source->slot >= self::ARMOR_SLOT_COUNT))
            || ($source->container === InventoryContainer::Offhand && $source->slot !== 0)
            || ($source->container === InventoryContainer::CraftingInput
                && ($source->slot < 0
                    || $source->slot >= $this->craftingGridWidth ** 2
                    || !$this->craftingResponseSlotMatchesActiveGrid($source)))
            || $count < 1) {
            return new InventoryStackRemovalResult(false, reason: 'drop_source');
        }
        $stack = $this->readAuthoritativeSlot($source);
        if ($stack === null || $stack->count < $count) {
            return new InventoryStackRemovalResult(false, reason: 'source_count');
        }
        if ($source->expectedCount !== null && $source->expectedCount !== $stack->count) {
            return new InventoryStackRemovalResult(false, reason: 'stack_count');
        }
        $lastRequestId = $this->readLastRequestId($source);
        $networkIdMatches = $source->expectedStackNetworkId === 0
            || ($source->expectedStackNetworkId < 0
                ? $lastRequestId === $source->expectedStackNetworkId
                    || $source->expectedStackNetworkId === $requestId
                : $source->expectedStackNetworkId === $stack->stackNetworkId);
        if (!$networkIdMatches) {
            return new InventoryStackRemovalResult(false, reason: 'stack_network_id');
        }
        if ($expectedStack !== null && !self::sameContent($stack, $expectedStack)) {
            return new InventoryStackRemovalResult(false, reason: 'source_item');
        }

        $removed = $stack->withCountAndNetworkId($count, $stack->stackNetworkId);
        $remaining = $stack->count - $count;
        if ($remaining === 0) {
            $this->writeAuthoritativeSlot($source, null);
        } else {
            $usedIds = $this->usedNetworkIds();
            unset($usedIds[$stack->stackNetworkId]);
            $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $usedIds);
            if ($networkId === null) {
                return new InventoryStackRemovalResult(false, reason: 'stack_id_capacity');
            }
            $replacement = $stack->withCountAndNetworkId($remaining, $networkId);
            $this->writeAuthoritativeSlot($source, $replacement);
        }
        $this->writeAuthoritativeLastRequestId($source, $requestId);

        return new InventoryStackRemovalResult(
            true,
            $removed,
            $source->container === InventoryContainer::Main && $source->slot === $this->selectedHotbarSlot,
        );
    }

    public function removeOpenedContainerForDrop(
        int $requestId,
        InventorySlotReference $source,
        int $count,
        OpenedContainerInventory $opened,
        ?InventoryStack $expectedStack = null,
    ): InventoryStackRemovalResult {
        if ($source->container !== InventoryContainer::OpenedContainer
            || $source->slot < 0 || $source->slot >= $opened->size || $count < 1) {
            return new InventoryStackRemovalResult(false, reason: 'drop_source');
        }
        $stack = $opened->stackAt($source->slot);
        if ($stack === null || $stack->count < $count) {
            return new InventoryStackRemovalResult(false, reason: 'source_count');
        }
        if ($source->expectedCount !== null && $source->expectedCount !== $stack->count) {
            return new InventoryStackRemovalResult(false, reason: 'stack_count');
        }
        $lastRequestIds = $opened->lastRequestIds();
        $lastRequestId = $lastRequestIds[$source->slot] ?? null;
        $networkIdMatches = $source->expectedStackNetworkId === 0
            || ($source->expectedStackNetworkId < 0
                ? $lastRequestId === $source->expectedStackNetworkId
                    || $source->expectedStackNetworkId === $requestId
                : $source->expectedStackNetworkId === $stack->stackNetworkId);
        if (!$networkIdMatches || ($expectedStack !== null && !self::sameContent($stack, $expectedStack))) {
            return new InventoryStackRemovalResult(false, reason: $networkIdMatches ? 'source_item' : 'stack_network_id');
        }
        $stacks = $opened->indexedStacks();
        $removed = $stack->withCountAndNetworkId($count, $stack->stackNetworkId);
        $remaining = $stack->count - $count;
        if ($remaining === 0) {
            unset($stacks[$source->slot]);
        } else {
            $usedIds = $this->usedNetworkIds();
            foreach ($stacks as $existing) {
                $usedIds[$existing->stackNetworkId] = true;
            }
            unset($usedIds[$stack->stackNetworkId]);
            $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $usedIds);
            if ($networkId === null) {
                return new InventoryStackRemovalResult(false, reason: 'stack_id_capacity');
            }
            $stacks[$source->slot] = $stack->withCountAndNetworkId($remaining, $networkId);
        }
        $lastRequestIds[$source->slot] = $requestId;
        $opened->commit($stacks, $lastRequestIds);

        return new InventoryStackRemovalResult(true, $removed);
    }

    /**
     * Applies one item-stack request atomically against staged authoritative state.
     *
     * @param list<InventoryStackRequestAction> $actions
     * @param list<InventoryStack> $createdOutputs
     */
    public function applyStackRequest(
        int $requestId,
        array $actions,
        ?InventoryStack $createdOutput = null,
        bool $createdOutputUnlimited = true,
        array $createdOutputs = [],
        bool $allowMainConsumption = false,
    ): InventoryStackRequestResult {
        if ($actions === []) {
            return new InventoryStackRequestResult(false, reason: 'empty_actions');
        }
        $stagedStacks = $this->stacks;
        $stagedCursor = $this->cursor;
        $stagedArmor = $this->armor;
        $stagedOffhand = $this->offhand;
        $stagedCrafting = $this->crafting;
        /** @var list<InventoryStack|null> $stagedCreatedOutputs */
        $stagedCreatedOutputs = $createdOutputs;
        $createdOutputIndex = 0;
        $stagedCreatedOutput = $stagedCreatedOutputs[0] ?? $createdOutput;
        $stagedLastRequestIds = $this->lastRequestIds;
        $stagedCursorLastRequestId = $this->cursorLastRequestId;
        $stagedArmorLastRequestIds = $this->armorLastRequestIds;
        $stagedOffhandLastRequestId = $this->offhandLastRequestId;
        $stagedCraftingLastRequestIds = $this->craftingLastRequestIds;
        $affected = [];
        $mutated = [];

        foreach ($actions as $action) {
            if ($action->type === InventoryStackRequestActionType::SelectCraftingResult) {
                if ($stagedCreatedOutputs === [] || !array_key_exists($action->count, $stagedCreatedOutputs)
                    || $stagedCreatedOutputs[$action->count] === null) {
                    return new InventoryStackRequestResult(false, reason: 'crafting_result');
                }
                if ($action->count !== $createdOutputIndex && $stagedCreatedOutput !== null) {
                    return new InventoryStackRequestResult(false, reason: 'unfinished_crafting_result');
                }
                $createdOutputIndex = $action->count;
                $stagedCreatedOutput = $stagedCreatedOutputs[$createdOutputIndex];
                continue;
            }
            if ($action->type === InventoryStackRequestActionType::MineBlock) {
                if ($action->source->key() !== $action->destination->key()
                    || $action->source->container !== InventoryContainer::Main
                    || $action->source->slot < 0
                    || $action->source->slot >= self::HOTBAR_SIZE) {
                    return new InventoryStackRequestResult(false, reason: 'mine_block_slot');
                }
                $affected[$action->source->responseKey()] = $action->source;
                continue;
            }
            $reason = $this->validateReference(
                $action->source,
                $requestId,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedCrafting,
                $stagedCreatedOutput,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedCraftingLastRequestIds,
            ) ?? $this->validateReference(
                $action->destination,
                $requestId,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedCrafting,
                $stagedCreatedOutput,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedCraftingLastRequestIds,
            );
            if ($reason !== null) {
                return new InventoryStackRequestResult(false, reason: $reason);
            }
            if ($action->source->key() === $action->destination->key()) {
                if ($action->type !== InventoryStackRequestActionType::Consume) {
                    return new InventoryStackRequestResult(false, reason: 'same_slot');
                }
            }
            if ($action->source->container !== InventoryContainer::CreatedOutput) {
                $affected[$action->source->responseKey()] = $action->source;
                $mutated[$action->source->key()] = $action->source;
            }
            if ($action->destination->container !== InventoryContainer::CreatedOutput) {
                $affected[$action->destination->responseKey()] = $action->destination;
                $mutated[$action->destination->key()] = $action->destination;
            }

            if ($action->type === InventoryStackRequestActionType::Consume) {
                if (!in_array(
                    $action->source->container,
                    $allowMainConsumption
                        ? [InventoryContainer::Main, InventoryContainer::CraftingInput]
                        : [InventoryContainer::CraftingInput],
                    true,
                ) || $action->count < 1) {
                    return new InventoryStackRequestResult(false, reason: 'crafting_consume');
                }
                $source = self::readSlot(
                    $action->source,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                if ($source === null || $source->count < $action->count) {
                    return new InventoryStackRequestResult(false, reason: 'source_count');
                }
                self::writeSlot(
                    $action->source,
                    $source->count === $action->count
                        ? null
                        : $source->withCountAndNetworkId($source->count - $action->count, $source->stackNetworkId),
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
            } elseif ($action->type === InventoryStackRequestActionType::Swap) {
                if ($action->destination->container === InventoryContainer::CreatedOutput) {
                    return new InventoryStackRequestResult(false, reason: 'created_output_destination');
                }
                $source = self::readSlot(
                    $action->source,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                $destination = self::readSlot(
                    $action->destination,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                if (!$this->canOccupy($action->source, $destination)
                    || !$this->canOccupy($action->destination, $source)) {
                    return new InventoryStackRequestResult(false, reason: 'equipment_slot');
                }
                if ($action->source->container !== InventoryContainer::CreatedOutput) {
                    self::writeSlot(
                        $action->source,
                        $destination,
                        $stagedStacks,
                        $stagedCursor,
                        $stagedArmor,
                        $stagedOffhand,
                        $stagedCrafting,
                        $stagedCreatedOutput,
                    );
                }
                self::writeSlot(
                    $action->destination,
                    $source,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
            } else {
                if ($action->count < 1) {
                    return new InventoryStackRequestResult(false, reason: 'invalid_count');
                }
                if ($action->destination->container === InventoryContainer::CreatedOutput) {
                    return new InventoryStackRequestResult(false, reason: 'created_output_destination');
                }
                $source = self::readSlot(
                    $action->source,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                $unlimitedCreatedOutput = $createdOutputUnlimited
                    && $action->source->container === InventoryContainer::CreatedOutput;
                if ($source === null || (!$unlimitedCreatedOutput && $source->count < $action->count)) {
                    return new InventoryStackRequestResult(false, reason: 'source_count');
                }
                if (!$unlimitedCreatedOutput) {
                    $sourceRemaining = $source->count === $action->count
                        ? null
                        : $source->withCountAndNetworkId($source->count - $action->count, $source->stackNetworkId);
                    self::writeSlot(
                        $action->source,
                        $sourceRemaining,
                        $stagedStacks,
                        $stagedCursor,
                        $stagedArmor,
                        $stagedOffhand,
                        $stagedCrafting,
                        $stagedCreatedOutput,
                    );
                    if ($action->source->container === InventoryContainer::CreatedOutput
                        && $stagedCreatedOutputs !== []) {
                        $stagedCreatedOutputs[$createdOutputIndex] = $sourceRemaining;
                    }
                }
                $destination = self::readSlot(
                    $action->destination,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                if ($destination !== null && !self::canStack($source, $destination)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_item');
                }
                $existingCount = $destination === null ? 0 : $destination->count;
                if ($existingCount + $action->count > $this->maximumStackSize($source->identifier)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_capacity');
                }
                $destinationCount = $existingCount + $action->count;
                $destinationResult = ($destination ?? $source)->withCountAndNetworkId(
                    $destinationCount,
                    ($destination ?? $source)->stackNetworkId,
                );
                if (!$this->canOccupy($action->destination, $destinationResult)) {
                    return new InventoryStackRequestResult(false, reason: 'equipment_slot');
                }
                self::writeSlot(
                    $action->destination,
                    $destinationResult,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
            }

            self::writeLastRequestId(
                $action->source,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedCraftingLastRequestIds,
            );
            self::writeLastRequestId(
                $action->destination,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedCraftingLastRequestIds,
            );
        }

        if ($stagedCreatedOutputs !== []) {
            foreach ($stagedCreatedOutputs as $remainingOutput) {
                if ($remainingOutput !== null) {
                    return new InventoryStackRequestResult(false, reason: 'unfinished_crafting_result');
                }
            }
        }

        $selectedBefore = $this->selectedStack();
        $usedIds = [];
        foreach ($stagedStacks as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        if ($stagedCursor !== null) {
            $usedIds[$stagedCursor->stackNetworkId] = true;
        }
        foreach ($stagedArmor as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        if ($stagedOffhand !== null) {
            $usedIds[$stagedOffhand->stackNetworkId] = true;
        }
        foreach ($stagedCrafting as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        $nextId = $this->nextStackNetworkId;
        foreach ($mutated as $reference) {
            $before = $this->readAuthoritativeSlot($reference);
            $after = self::readSlot(
                $reference,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedCrafting,
                $stagedCreatedOutput,
            );
            if (self::sameContent($before, $after)) {
                self::writeLastRequestId(
                    $reference,
                    $this->readLastRequestId($reference),
                    $stagedLastRequestIds,
                    $stagedCursorLastRequestId,
                    $stagedArmorLastRequestIds,
                    $stagedOffhandLastRequestId,
                    $stagedCraftingLastRequestIds,
                );
                continue;
            }
            if ($after !== null) {
                $id = self::allocateStackNetworkId($nextId, $usedIds);
                if ($id === null) {
                    return new InventoryStackRequestResult(false, reason: 'stack_id_capacity');
                }
                $after = $after->withCountAndNetworkId($after->count, $id);
                self::writeSlot(
                    $reference,
                    $after,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedCrafting,
                    $stagedCreatedOutput,
                );
                $usedIds[$id] = true;
            }
        }

        $this->stacks = $stagedStacks;
        $this->cursor = $stagedCursor;
        $this->armor = $stagedArmor;
        $this->offhand = $stagedOffhand;
        $this->crafting = $stagedCrafting;
        $this->lastRequestIds = $stagedLastRequestIds;
        $this->cursorLastRequestId = $stagedCursorLastRequestId;
        $this->armorLastRequestIds = $stagedArmorLastRequestIds;
        $this->offhandLastRequestId = $stagedOffhandLastRequestId;
        $this->craftingLastRequestIds = $stagedCraftingLastRequestIds;
        $this->nextStackNetworkId = $nextId;

        return new InventoryStackRequestResult(
            true,
            array_values($affected),
            selectedStackChanged: !self::sameContent($selectedBefore, $this->selectedStack()),
        );
    }

    /**
     * Applies a normal move/split/merge/swap request atomically across the player and one open container.
     *
     * @param list<InventoryStackRequestAction> $actions
     */
    public function applyOpenedContainerStackRequest(
        int $requestId,
        array $actions,
        OpenedContainerInventory $opened,
    ): InventoryStackRequestResult {
        if ($actions === []) {
            return new InventoryStackRequestResult(false, reason: 'empty_actions');
        }
        /** @var array<int, InventoryStack> $stagedStacks */
        $stagedStacks = $this->stacks;
        $stagedCursor = $this->cursor;
        /** @var array<int, InventoryStack> $stagedArmor */
        $stagedArmor = $this->armor;
        $stagedOffhand = $this->offhand;
        /** @var array<int, InventoryStack> $stagedOpened */
        $stagedOpened = $opened->indexedStacks();
        /** @var array<int, int> $stagedLastRequestIds */
        $stagedLastRequestIds = $this->lastRequestIds;
        $stagedCursorLastRequestId = $this->cursorLastRequestId;
        /** @var array<int, int> $stagedArmorLastRequestIds */
        $stagedArmorLastRequestIds = $this->armorLastRequestIds;
        $stagedOffhandLastRequestId = $this->offhandLastRequestId;
        /** @var array<int, int> $stagedOpenedLastRequestIds */
        $stagedOpenedLastRequestIds = $opened->lastRequestIds();
        $affected = [];
        $mutated = [];

        foreach ($actions as $action) {
            if (!in_array($action->type, [
                InventoryStackRequestActionType::Take,
                InventoryStackRequestActionType::Place,
                InventoryStackRequestActionType::Swap,
            ], true)) {
                return new InventoryStackRequestResult(false, reason: 'container_action');
            }
            $reason = $this->validateOpenedReference(
                $action->source,
                $requestId,
                $opened->size,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedOpened,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedOpenedLastRequestIds,
            ) ?? $this->validateOpenedReference(
                $action->destination,
                $requestId,
                $opened->size,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedOpened,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedOpenedLastRequestIds,
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
            $source = self::readOpenedSlot(
                $action->source,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedOpened,
            );
            $destination = self::readOpenedSlot(
                $action->destination,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedOpened,
            );
            if ($action->type === InventoryStackRequestActionType::Swap) {
                if (!$this->canOccupy($action->source, $destination)
                    || !$this->canOccupy($action->destination, $source)) {
                    return new InventoryStackRequestResult(false, reason: 'equipment_slot');
                }
                self::writeOpenedSlot(
                    $action->source,
                    $destination,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedOpened,
                );
                self::writeOpenedSlot(
                    $action->destination,
                    $source,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedOpened,
                );
            } else {
                if ($action->count < 1 || $source === null || $source->count < $action->count) {
                    return new InventoryStackRequestResult(false, reason: 'source_count');
                }
                if ($destination !== null && !self::canStack($source, $destination)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_item');
                }
                $destinationCount = ($destination === null ? 0 : $destination->count) + $action->count;
                if ($destinationCount > $this->maximumStackSize($source->identifier)) {
                    return new InventoryStackRequestResult(false, reason: 'destination_capacity');
                }
                $sourceRemaining = $source->count === $action->count
                    ? null
                    : $source->withCountAndNetworkId($source->count - $action->count, $source->stackNetworkId);
                $destinationResult = ($destination ?? $source)->withCountAndNetworkId(
                    $destinationCount,
                    ($destination ?? $source)->stackNetworkId,
                );
                if (!$this->canOccupy($action->destination, $destinationResult)) {
                    return new InventoryStackRequestResult(false, reason: 'equipment_slot');
                }
                self::writeOpenedSlot(
                    $action->source,
                    $sourceRemaining,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedOpened,
                );
                self::writeOpenedSlot(
                    $action->destination,
                    $destinationResult,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedOpened,
                );
            }
            self::writeOpenedLastRequestId(
                $action->source,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedOpenedLastRequestIds,
            );
            self::writeOpenedLastRequestId(
                $action->destination,
                $requestId,
                $stagedLastRequestIds,
                $stagedCursorLastRequestId,
                $stagedArmorLastRequestIds,
                $stagedOffhandLastRequestId,
                $stagedOpenedLastRequestIds,
            );
        }

        $selectedBefore = $this->selectedStack();
        /** @var array<int, true> $usedIds */
        $usedIds = [];
        foreach ($stagedStacks as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        foreach ($stagedArmor as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        foreach ($this->crafting as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        foreach ($this->enderChest as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        foreach ($stagedOpened as $stack) {
            $usedIds[$stack->stackNetworkId] = true;
        }
        foreach ([$stagedCursor, $stagedOffhand] as $stack) {
            if ($stack !== null) {
                $usedIds[$stack->stackNetworkId] = true;
            }
        }
        $nextId = $this->nextStackNetworkId;
        foreach ($mutated as $reference) {
            $before = $reference->container === InventoryContainer::OpenedContainer
                ? $opened->stackAt($reference->slot)
                : $this->readAuthoritativeSlot($reference);
            $after = self::readOpenedSlot(
                $reference,
                $stagedStacks,
                $stagedCursor,
                $stagedArmor,
                $stagedOffhand,
                $stagedOpened,
            );
            if (self::sameContent($before, $after)) {
                continue;
            }
            if ($after !== null) {
                $networkId = self::allocateStackNetworkId($nextId, $usedIds);
                if ($networkId === null) {
                    return new InventoryStackRequestResult(false, reason: 'stack_id_capacity');
                }
                $after = $after->withCountAndNetworkId($after->count, $networkId);
                self::writeOpenedSlot(
                    $reference,
                    $after,
                    $stagedStacks,
                    $stagedCursor,
                    $stagedArmor,
                    $stagedOffhand,
                    $stagedOpened,
                );
                $usedIds[$networkId] = true;
            }
        }

        $this->stacks = $stagedStacks;
        $this->cursor = $stagedCursor;
        $this->armor = $stagedArmor;
        $this->offhand = $stagedOffhand;
        $this->lastRequestIds = $stagedLastRequestIds;
        $this->cursorLastRequestId = $stagedCursorLastRequestId;
        $this->armorLastRequestIds = $stagedArmorLastRequestIds;
        $this->offhandLastRequestId = $stagedOffhandLastRequestId;
        $this->nextStackNetworkId = $nextId;
        $opened->commit($stagedOpened, $stagedOpenedLastRequestIds);

        return new InventoryStackRequestResult(
            true,
            array_values($affected),
            selectedStackChanged: !self::sameContent($selectedBefore, $this->selectedStack()),
        );
    }

    /** @param list<InventoryStack|null> $slots */
    public function projectOpenedContainer(string $identifier, array $slots): OpenedContainerInventory
    {
        if ($slots === [] || count($slots) > 256) {
            throw new InvalidArgumentException('Opened container projection must be a non-empty bounded slot list.');
        }
        $usedIds = $this->usedNetworkIds();
        $projected = [];
        $nextId = $this->nextStackNetworkId;
        foreach ($slots as $slot => $stack) {
            if ($stack === null) {
                continue;
            }
            if ($stack->count > $this->maximumStackSize($stack->identifier)) {
                throw new InvalidArgumentException('Opened container contains an invalid or oversized stack.');
            }
            $networkId = self::allocateStackNetworkId($nextId, $usedIds);
            if ($networkId === null) {
                throw new OverflowException('Inventory stack network ID space is exhausted.');
            }
            $projected[$slot] = $stack->withCountAndNetworkId($stack->count, $networkId);
            $usedIds[$networkId] = true;
        }
        $this->nextStackNetworkId = $nextId;

        return new OpenedContainerInventory($identifier, count($slots), $projected);
    }

    /** Atomically adopts staged state only if the authoritative baseline is still current. */
    public function commitStagedState(self $expected, self $staged): bool
    {
        if ($expected->catalog !== $this->catalog || $staged->catalog !== $this->catalog) {
            throw new InvalidArgumentException('Staged inventory does not share the authoritative item catalog.');
        }
        if (!$this->matchesState($expected)) {
            return false;
        }
        $this->stacks = $staged->stacks;
        $this->selectedHotbarSlot = $staged->selectedHotbarSlot;
        $this->cursor = $staged->cursor;
        $this->nextStackNetworkId = $staged->nextStackNetworkId;
        $this->armor = $staged->armor;
        $this->offhand = $staged->offhand;
        $this->enderChest = $staged->enderChest;
        $this->lastRequestIds = $staged->lastRequestIds;
        $this->cursorLastRequestId = $staged->cursorLastRequestId;
        $this->armorLastRequestIds = $staged->armorLastRequestIds;
        $this->offhandLastRequestId = $staged->offhandLastRequestId;
        $this->crafting = $staged->crafting;
        $this->craftingLastRequestIds = $staged->craftingLastRequestIds;
        $this->craftingGridWidth = $staged->craftingGridWidth;

        return true;
    }

    public function matchesState(self $expected): bool
    {
        return $expected->catalog === $this->catalog
            && $this->stacks === $expected->stacks
            && $this->selectedHotbarSlot === $expected->selectedHotbarSlot
            && $this->cursor === $expected->cursor
            && $this->nextStackNetworkId === $expected->nextStackNetworkId
            && $this->armor === $expected->armor
            && $this->offhand === $expected->offhand
            && $this->enderChest === $expected->enderChest
            && $this->lastRequestIds === $expected->lastRequestIds
            && $this->cursorLastRequestId === $expected->cursorLastRequestId
            && $this->armorLastRequestIds === $expected->armorLastRequestIds
            && $this->offhandLastRequestId === $expected->offhandLastRequestId
            && $this->crafting === $expected->crafting
            && $this->craftingLastRequestIds === $expected->craftingLastRequestIds
            && $this->craftingGridWidth === $expected->craftingGridWidth;
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $opened
     * @param array<int, int> $lastRequestIds
     * @param array<int, int> $armorLastRequestIds
     * @param array<int, int> $openedLastRequestIds
     */
    private function validateOpenedReference(
        InventorySlotReference $reference,
        int $requestId,
        int $openedSize,
        array $stacks,
        ?InventoryStack $cursor,
        array $armor,
        ?InventoryStack $offhand,
        array $opened,
        array $lastRequestIds,
        ?int $cursorLastRequestId,
        array $armorLastRequestIds,
        ?int $offhandLastRequestId,
        array $openedLastRequestIds,
    ): ?string {
        if (!in_array($reference->container, [
            InventoryContainer::Main,
            InventoryContainer::Cursor,
            InventoryContainer::Armor,
            InventoryContainer::Offhand,
            InventoryContainer::OpenedContainer,
        ], true)
            || ($reference->container === InventoryContainer::Main
                && ($reference->slot < 0 || $reference->slot >= self::SLOT_COUNT))
            || ($reference->container === InventoryContainer::Cursor && $reference->slot !== 0)
            || ($reference->container === InventoryContainer::Armor
                && ($reference->slot < 0 || $reference->slot >= self::ARMOR_SLOT_COUNT))
            || ($reference->container === InventoryContainer::Offhand && $reference->slot !== 0)
            || ($reference->container === InventoryContainer::OpenedContainer
                && ($reference->slot < 0 || $reference->slot >= $openedSize))) {
            return 'slot';
        }
        $stack = self::readOpenedSlot($reference, $stacks, $cursor, $armor, $offhand, $opened);
        if ($reference->expectedCount !== null
            && $reference->expectedCount !== ($stack === null ? 0 : $stack->count)) {
            return 'stack_count';
        }
        $lastRequestId = match ($reference->container) {
            InventoryContainer::Main => $lastRequestIds[$reference->slot] ?? null,
            InventoryContainer::Cursor => $cursorLastRequestId,
            InventoryContainer::Armor => $armorLastRequestIds[$reference->slot] ?? null,
            InventoryContainer::Offhand => $offhandLastRequestId,
            default => $openedLastRequestIds[$reference->slot] ?? null,
        };
        $matches = $reference->expectedStackNetworkId < 0
            ? $lastRequestId === $reference->expectedStackNetworkId
                || $reference->expectedStackNetworkId === $requestId
            : ($stack === null ? 0 : $stack->stackNetworkId) === $reference->expectedStackNetworkId;

        return $matches ? null : 'stack_network_id';
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $opened
     */
    private static function readOpenedSlot(
        InventorySlotReference $reference,
        array $stacks,
        ?InventoryStack $cursor,
        array $armor,
        ?InventoryStack $offhand,
        array $opened,
    ): ?InventoryStack {
        $stack = match ($reference->container) {
            InventoryContainer::Main => $stacks[$reference->slot] ?? null,
            InventoryContainer::Cursor => $cursor,
            InventoryContainer::Armor => $armor[$reference->slot] ?? null,
            InventoryContainer::Offhand => $offhand,
            default => $opened[$reference->slot] ?? null,
        };

        return $stack;
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $opened
     */
    private static function writeOpenedSlot(
        InventorySlotReference $reference,
        ?InventoryStack $stack,
        array &$stacks,
        ?InventoryStack &$cursor,
        array &$armor,
        ?InventoryStack &$offhand,
        array &$opened,
    ): void {
        if ($reference->container === InventoryContainer::Cursor) {
            $cursor = $stack;
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $offhand = $stack;
        } elseif ($reference->container === InventoryContainer::Main) {
            if ($stack === null) {
                unset($stacks[$reference->slot]);
            } else {
                $stacks[$reference->slot] = $stack;
            }
        } elseif ($reference->container === InventoryContainer::Armor) {
            if ($stack === null) {
                unset($armor[$reference->slot]);
            } else {
                $armor[$reference->slot] = $stack;
            }
        } elseif ($reference->container === InventoryContainer::OpenedContainer) {
            if ($stack === null) {
                unset($opened[$reference->slot]);
            } else {
                $opened[$reference->slot] = $stack;
            }
        } else {
            throw new InvalidArgumentException('Unsupported opened-container slot reference.');
        }
    }

    /**
     * @param array<int, int> $lastRequestIds
     * @param array<int, int> $armorLastRequestIds
     * @param array<int, int> $openedLastRequestIds
     */
    private static function writeOpenedLastRequestId(
        InventorySlotReference $reference,
        int $requestId,
        array &$lastRequestIds,
        ?int &$cursorLastRequestId,
        array &$armorLastRequestIds,
        ?int &$offhandLastRequestId,
        array &$openedLastRequestIds,
    ): void {
        if ($reference->container === InventoryContainer::Main) {
            $lastRequestIds[$reference->slot] = $requestId;
        } elseif ($reference->container === InventoryContainer::Cursor) {
            $cursorLastRequestId = $requestId;
        } elseif ($reference->container === InventoryContainer::Armor) {
            $armorLastRequestIds[$reference->slot] = $requestId;
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $offhandLastRequestId = $requestId;
        } else {
            $openedLastRequestIds[$reference->slot] = $requestId;
        }
    }

    /** Adds as much as possible using existing compatible stacks before empty slots. */
    public function add(InventoryStack $incoming): ?InventoryStack
    {
        $remaining = $incoming->count;
        $maximum = $this->maximumStackSize($incoming->identifier);
        foreach ($this->stacks as $slot => $stack) {
            if (!self::canStack($incoming, $stack) || $stack->count >= $maximum) {
                continue;
            }
            $added = min($remaining, $maximum - $stack->count);
            $this->stacks[$slot] = $stack->withCountAndNetworkId($stack->count + $added, $stack->stackNetworkId);
            $remaining -= $added;
            if ($remaining === 0) {
                return null;
            }
        }
        for ($slot = 0; $slot < self::SLOT_COUNT && $remaining > 0; ++$slot) {
            if (isset($this->stacks[$slot])) {
                continue;
            }
            $count = min($remaining, $maximum);
            $usedIds = [];
            foreach ($this->stacks as $stack) {
                $usedIds[$stack->stackNetworkId] = true;
            }
            if ($this->cursor !== null) {
                $usedIds[$this->cursor->stackNetworkId] = true;
            }
            foreach ($this->armor as $equipped) {
                $usedIds[$equipped->stackNetworkId] = true;
            }
            if ($this->offhand !== null) {
                $usedIds[$this->offhand->stackNetworkId] = true;
            }
            $networkId = self::allocateStackNetworkId($this->nextStackNetworkId, $usedIds);
            if ($networkId === null) {
                break;
            }
            $this->stacks[$slot] = $incoming->withCountAndNetworkId($count, $networkId);
            $remaining -= $count;
        }

        return $remaining === 0
            ? null
            : $incoming->withCountAndNetworkId($remaining, $incoming->stackNetworkId);
    }

    public function addableQuantity(InventoryStack $incoming): int
    {
        $maximum = $this->maximumStackSize($incoming->identifier);
        $quantity = 0;
        foreach ($this->stacks as $stack) {
            if (self::canStack($incoming, $stack)) {
                $quantity += max(0, $maximum - $stack->count);
            }
        }
        $quantity += (self::SLOT_COUNT - count($this->stacks)) * $maximum;

        return $quantity;
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $crafting
     * @param array<int, int> $lastRequestIds
     * @param array<int, int> $armorLastRequestIds
     * @param array<int, int> $craftingLastRequestIds
     */
    private function validateReference(
        InventorySlotReference $reference,
        int $requestId,
        array $stacks,
        ?InventoryStack $cursor,
        array $armor,
        ?InventoryStack $offhand,
        array $crafting,
        ?InventoryStack $createdOutput,
        array $lastRequestIds,
        ?int $cursorLastRequestId,
        array $armorLastRequestIds,
        ?int $offhandLastRequestId,
        array $craftingLastRequestIds,
    ): ?string {
        if ($reference->container === InventoryContainer::OpenedContainer
            || ($reference->container === InventoryContainer::Main && ($reference->slot < 0 || $reference->slot >= self::SLOT_COUNT))
            || ($reference->container === InventoryContainer::Cursor && $reference->slot !== 0)
            || ($reference->container === InventoryContainer::Armor
                && ($reference->slot < 0 || $reference->slot >= self::ARMOR_SLOT_COUNT))
            || ($reference->container === InventoryContainer::Offhand && $reference->slot !== 0)
            || ($reference->container === InventoryContainer::CraftingInput
                && ($reference->slot < 0
                    || $reference->slot >= $this->craftingGridWidth ** 2
                    || !$this->craftingResponseSlotMatchesActiveGrid($reference)))
            || ($reference->container === InventoryContainer::CreatedOutput && $reference->slot !== 50)) {
            return 'slot';
        }
        $stack = self::readSlot($reference, $stacks, $cursor, $armor, $offhand, $crafting, $createdOutput);
        $stackCount = $stack === null ? 0 : $stack->count;
        if ($reference->expectedCount !== null && $stackCount !== $reference->expectedCount) {
            return 'stack_count';
        }
        if ($reference->container === InventoryContainer::CreatedOutput) {
            return null;
        }
        if ($reference->container === InventoryContainer::Main) {
            $lastRequestId = $lastRequestIds[$reference->slot] ?? null;
        } elseif ($reference->container === InventoryContainer::Cursor) {
            $lastRequestId = $cursorLastRequestId;
        } elseif ($reference->container === InventoryContainer::Armor) {
            $lastRequestId = $armorLastRequestIds[$reference->slot] ?? null;
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $lastRequestId = $offhandLastRequestId;
        } else {
            $lastRequestId = $craftingLastRequestIds[$reference->slot] ?? null;
        }
        $matches = $reference->expectedStackNetworkId < 0
            ? $lastRequestId === $reference->expectedStackNetworkId
                || $reference->expectedStackNetworkId === $requestId
            : ($stack === null ? 0 : $stack->stackNetworkId) === $reference->expectedStackNetworkId;

        return $matches ? null : 'stack_network_id';
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $crafting
     */
    private static function readSlot(
        InventorySlotReference $reference,
        array $stacks,
        ?InventoryStack $cursor,
        array $armor,
        ?InventoryStack $offhand,
        array $crafting,
        ?InventoryStack $createdOutput,
    ): ?InventoryStack {
        return match ($reference->container) {
            InventoryContainer::Main => $stacks[$reference->slot] ?? null,
            InventoryContainer::Cursor => $cursor,
            InventoryContainer::Armor => $armor[$reference->slot] ?? null,
            InventoryContainer::Offhand => $offhand,
            InventoryContainer::CraftingInput => $crafting[$reference->slot] ?? null,
            InventoryContainer::CreatedOutput => $createdOutput,
            InventoryContainer::OpenedContainer => null,
        };
    }

    /**
     * @param array<int, InventoryStack> $stacks
     * @param array<int, InventoryStack> $armor
     * @param array<int, InventoryStack> $crafting
     */
    private static function writeSlot(
        InventorySlotReference $reference,
        ?InventoryStack $stack,
        array &$stacks,
        ?InventoryStack &$cursor,
        array &$armor,
        ?InventoryStack &$offhand,
        array &$crafting,
        ?InventoryStack &$createdOutput,
    ): void {
        if ($reference->container === InventoryContainer::OpenedContainer) {
            throw new InvalidArgumentException('Opened-container slots require the cross-inventory transaction path.');
        } elseif ($reference->container === InventoryContainer::Cursor) {
            $cursor = $stack;
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $offhand = $stack;
        } elseif ($reference->container === InventoryContainer::CreatedOutput) {
            $createdOutput = $stack;
        } elseif ($reference->container === InventoryContainer::CraftingInput) {
            if ($stack === null) {
                unset($crafting[$reference->slot]);
            } else {
                $crafting[$reference->slot] = $stack;
            }
        } elseif ($reference->container === InventoryContainer::Armor) {
            if ($stack === null) {
                unset($armor[$reference->slot]);
            } else {
                $armor[$reference->slot] = $stack;
            }
        } elseif ($stack === null) {
            unset($stacks[$reference->slot]);
        } else {
            $stacks[$reference->slot] = $stack;
        }
    }

    /**
     * @param array<int, int> $lastRequestIds
     * @param array<int, int> $armorLastRequestIds
     * @param array<int, int> $craftingLastRequestIds
     */
    private static function writeLastRequestId(
        InventorySlotReference $reference,
        ?int $requestId,
        array &$lastRequestIds,
        ?int &$cursorLastRequestId,
        array &$armorLastRequestIds,
        ?int &$offhandLastRequestId,
        array &$craftingLastRequestIds,
    ): void {
        if ($reference->container === InventoryContainer::OpenedContainer) {
            throw new InvalidArgumentException('Opened-container lineage requires the cross-inventory transaction path.');
        } elseif ($reference->container === InventoryContainer::Cursor) {
            $cursorLastRequestId = $requestId;
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $offhandLastRequestId = $requestId;
        } elseif ($reference->container === InventoryContainer::CreatedOutput) {
            return;
        } elseif ($reference->container === InventoryContainer::CraftingInput) {
            if ($requestId === null) {
                unset($craftingLastRequestIds[$reference->slot]);
            } else {
                $craftingLastRequestIds[$reference->slot] = $requestId;
            }
        } elseif ($reference->container === InventoryContainer::Armor) {
            if ($requestId === null) {
                unset($armorLastRequestIds[$reference->slot]);
            } else {
                $armorLastRequestIds[$reference->slot] = $requestId;
            }
        } elseif ($requestId === null) {
            unset($lastRequestIds[$reference->slot]);
        } else {
            $lastRequestIds[$reference->slot] = $requestId;
        }
    }

    private function readAuthoritativeSlot(InventorySlotReference $reference): ?InventoryStack
    {
        return match ($reference->container) {
            InventoryContainer::Main => $this->stacks[$reference->slot] ?? null,
            InventoryContainer::Cursor => $this->cursor,
            InventoryContainer::Armor => $this->armor[$reference->slot] ?? null,
            InventoryContainer::Offhand => $this->offhand,
            InventoryContainer::CraftingInput => $this->crafting[$reference->slot] ?? null,
            InventoryContainer::CreatedOutput => null,
            InventoryContainer::OpenedContainer => null,
        };
    }

    private function readLastRequestId(InventorySlotReference $reference): ?int
    {
        return match ($reference->container) {
            InventoryContainer::Main => $this->lastRequestIds[$reference->slot] ?? null,
            InventoryContainer::Cursor => $this->cursorLastRequestId,
            InventoryContainer::Armor => $this->armorLastRequestIds[$reference->slot] ?? null,
            InventoryContainer::Offhand => $this->offhandLastRequestId,
            InventoryContainer::CraftingInput => $this->craftingLastRequestIds[$reference->slot] ?? null,
            InventoryContainer::CreatedOutput => null,
            InventoryContainer::OpenedContainer => null,
        };
    }

    private function writeAuthoritativeSlot(InventorySlotReference $reference, ?InventoryStack $stack): void
    {
        if ($reference->container === InventoryContainer::Main) {
            if ($stack === null) {
                unset($this->stacks[$reference->slot]);
            } else {
                $this->stacks[$reference->slot] = $stack;
            }
        } elseif ($reference->container === InventoryContainer::Cursor) {
            $this->cursor = $stack;
        } elseif ($reference->container === InventoryContainer::Armor) {
            if ($stack === null) {
                unset($this->armor[$reference->slot]);
            } else {
                $this->armor[$reference->slot] = $stack;
            }
        } elseif ($reference->container === InventoryContainer::Offhand) {
            $this->offhand = $stack;
        } elseif ($reference->container === InventoryContainer::CraftingInput) {
            if ($stack === null) {
                unset($this->crafting[$reference->slot]);
            } else {
                $this->crafting[$reference->slot] = $stack;
            }
        }
    }

    private function writeAuthoritativeLastRequestId(InventorySlotReference $reference, int $requestId): void
    {
        match ($reference->container) {
            InventoryContainer::Main => $this->lastRequestIds[$reference->slot] = $requestId,
            InventoryContainer::Cursor => $this->cursorLastRequestId = $requestId,
            InventoryContainer::Armor => $this->armorLastRequestIds[$reference->slot] = $requestId,
            InventoryContainer::Offhand => $this->offhandLastRequestId = $requestId,
            InventoryContainer::CraftingInput => $this->craftingLastRequestIds[$reference->slot] = $requestId,
            InventoryContainer::CreatedOutput => null,
            InventoryContainer::OpenedContainer => null,
        };
    }

    private function canOccupy(InventorySlotReference $reference, ?InventoryStack $stack): bool
    {
        if ($stack === null) {
            return true;
        }

        return match ($reference->container) {
            InventoryContainer::Armor => $this->acceptsArmor($reference->slot, $stack),
            InventoryContainer::Offhand => $this->acceptsOffhand($stack),
            default => true,
        };
    }

    private function acceptsArmor(int $slot, InventoryStack $stack): bool
    {
        if ($this->catalog === null || !$this->catalog->has($stack->identifier)) {
            return false;
        }

        return $this->catalog->type($stack->identifier)->armor?->slot->value === $slot;
    }

    private function acceptsOffhand(InventoryStack $stack): bool
    {
        return $this->catalog === null
            || ($this->catalog->has($stack->identifier)
                && $this->catalog->type($stack->identifier)->allowedInOffhand);
    }

    private static function canStack(InventoryStack $left, InventoryStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? '')
            && $left->placedBlockState?->value === $right->placedBlockState?->value;
    }

    private static function restoreStack(
        PlayerInventoryStackState $state,
        FixedFlatBlockPalette $palette,
        int $stackNetworkId,
        ?ItemCatalog $catalog,
        ?BlockStateRegistry $blockStates,
    ): InventoryStack {
        $placedBlockState = null;
        if ($catalog !== null && $blockStates !== null && $catalog->has($state->identifier)) {
            $canonicalState = $catalog->type($state->identifier)->placedBlockState;
            if ($canonicalState !== null) {
                $placedBlockState = $blockStates->internalId($canonicalState);
            }
        }
        if ($placedBlockState === null && $state->identifier === 'minecraft:grass_block') {
            $placedBlockState = $palette->grassBlock;
        }

        return new InventoryStack(
            $state->identifier,
            $state->count,
            $stackNetworkId,
            $placedBlockState,
            $state->damage,
            $state->nbt,
            $state->auxValue,
        );
    }

    private static function exportStack(InventoryStack $stack): PlayerInventoryStackState
    {
        return new PlayerInventoryStackState(
            $stack->identifier,
            $stack->count,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        );
    }

    private static function sameContent(?InventoryStack $left, ?InventoryStack $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null && self::canStack($left, $right) && $left->count === $right->count);
    }

    private function maximumStackSize(string $identifier): int
    {
        return $this->catalog?->type($identifier)->maximumStackSize
            ?? (SupportedInventoryItem::supports($identifier) ? SupportedInventoryItem::maximumStackSize($identifier) : 64);
    }

    /** @return array<int, true> */
    private function usedNetworkIds(): array
    {
        $used = [];
        foreach ($this->stacks as $stack) {
            $used[$stack->stackNetworkId] = true;
        }
        if ($this->cursor !== null) {
            $used[$this->cursor->stackNetworkId] = true;
        }
        foreach ($this->armor as $stack) {
            $used[$stack->stackNetworkId] = true;
        }
        if ($this->offhand !== null) {
            $used[$this->offhand->stackNetworkId] = true;
        }
        foreach ($this->crafting as $stack) {
            $used[$stack->stackNetworkId] = true;
        }
        foreach ($this->enderChest as $stack) {
            $used[$stack->stackNetworkId] = true;
        }

        return $used;
    }

    /** @param array<int, true> $usedIds */
    private static function allocateStackNetworkId(int &$nextId, array $usedIds): ?int
    {
        for ($attempt = 0; $attempt < self::SLOT_COUNT + self::ARMOR_SLOT_COUNT
            + self::ENDER_CHEST_SLOT_COUNT + 12; ++$attempt) {
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

    private static function validateArmorSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= self::ARMOR_SLOT_COUNT) {
            throw new InvalidArgumentException('Inventory slot is outside the armor inventory.');
        }
    }

    private static function validateEnderChestSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= self::ENDER_CHEST_SLOT_COUNT) {
            throw new InvalidArgumentException('Inventory slot is outside the Ender Chest inventory.');
        }
    }

    private function validateCraftingSlot(int $slot): void
    {
        if ($slot < 0 || $slot >= $this->craftingGridWidth ** 2) {
            throw new InvalidArgumentException('Inventory slot is outside the active crafting grid.');
        }
    }

    private function craftingResponseSlotMatchesActiveGrid(InventorySlotReference $reference): bool
    {
        if ($reference->responseContainerId === null) {
            return true;
        }
        $offset = $this->craftingGridWidth === 2 ? 28 : 32;

        return $reference->responseSlotId() === $offset + $reference->slot;
    }
}
