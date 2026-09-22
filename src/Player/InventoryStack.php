<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** One authoritative inventory stack expressed only in canonical server values. */
final readonly class InventoryStack
{
    public function __construct(
        public string $identifier,
        public int $count,
        public int $stackNetworkId,
        public ?InternalBlockStateId $placedBlockState = null,
        public int $damage = 0,
        public ?ItemNbt $nbt = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Inventory identifier must be canonical and namespaced.');
        }
        if ($count < 1 || $count > 64) {
            throw new InvalidArgumentException('Inventory stack count must be between 1 and 64.');
        }
        if (SupportedInventoryItem::supports($identifier)
            && $count > SupportedInventoryItem::maximumStackSize($identifier)) {
            throw new InvalidArgumentException('Inventory stack exceeds the supported item stack size.');
        }
        if ($stackNetworkId < 1 || $stackNetworkId > 0x7fffffff) {
            throw new InvalidArgumentException('Inventory stack network ID must be a positive signed 32-bit integer.');
        }
        if ($damage < 0 || $damage > PlayerInventoryStackState::MAX_DAMAGE) {
            throw new InvalidArgumentException('Inventory stack damage is outside its supported range.');
        }
    }

    public function decrement(): ?self
    {
        return $this->count === 1
            ? null
            : new self($this->identifier, $this->count - 1, $this->stackNetworkId, $this->placedBlockState, $this->damage, $this->nbt);
    }

    public function withCountAndNetworkId(int $count, int $stackNetworkId): self
    {
        return new self($this->identifier, $count, $stackNetworkId, $this->placedBlockState, $this->damage, $this->nbt);
    }

    public function withDamage(int $damage): self
    {
        return new self($this->identifier, $this->count, $this->stackNetworkId, $this->placedBlockState, $damage, $this->nbt);
    }
}
