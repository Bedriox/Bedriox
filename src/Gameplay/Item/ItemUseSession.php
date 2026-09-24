<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Server\Player\InventoryStack;
use InvalidArgumentException;

/** One transient authoritative press-and-hold action for a player's selected stack. */
final readonly class ItemUseSession
{
    public function __construct(
        public int $hotbarSlot,
        public InventoryStack $stack,
        public ItemUseBehavior $behavior,
        public int $startedAtTick,
    ) {
        if ($hotbarSlot < 0 || $hotbarSlot > 8 || $startedAtTick < 0) {
            throw new InvalidArgumentException('Item use session contains invalid authoritative state.');
        }
        if ($stack->identifier !== $behavior->identifier) {
            throw new InvalidArgumentException('Item use behavior does not match the authoritative held item.');
        }
    }

    public function completionTick(): int
    {
        return $this->startedAtTick + $this->behavior->useDurationTicks;
    }

    public function isCompleteAt(int $tick): bool
    {
        return $tick >= $this->completionTick();
    }

    public function matches(int $hotbarSlot, ?InventoryStack $stack): bool
    {
        return $stack !== null
            && $hotbarSlot === $this->hotbarSlot
            && $stack->identifier === $this->stack->identifier
            && $stack->count === $this->stack->count
            && $stack->stackNetworkId === $this->stack->stackNetworkId
            && $stack->damage === $this->stack->damage
            && $stack->auxValue === $this->stack->auxValue
            && $stack->placedBlockState?->value === $this->stack->placedBlockState?->value
            && ($stack->nbt?->toBinary() ?? '') === ($this->stack->nbt?->toBinary() ?? '');
    }
}
