<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Data\CanonicalBlockState;
use InvalidArgumentException;

/** Canonical server-owned properties of one supported item. */
final readonly class ItemType
{
    public function __construct(
        public string $identifier,
        public int $maximumStackSize = 64,
        public ?ToolDefinition $tool = null,
        public ?CanonicalBlockState $placedBlockState = null,
        public ?CanonicalBlockState $networkBlockState = null,
        public bool $creative = true,
        public ?string $owner = null,
        public ?ArmorDefinition $armor = null,
        public bool $allowedInOffhand = false,
        public ?int $maximumDurability = null,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item identifier must be canonical and namespaced.');
        }
        if ($maximumStackSize < 1 || $maximumStackSize > 64) {
            throw new InvalidArgumentException('Item maximum stack size must be between 1 and 64.');
        }
        if ($tool !== null && $maximumStackSize !== 1) {
            throw new InvalidArgumentException('Tools must have a maximum stack size of one.');
        }
        if ($armor !== null && $maximumStackSize !== 1) {
            throw new InvalidArgumentException('Armor must have a maximum stack size of one.');
        }
        if ($maximumDurability !== null && ($maximumDurability < 1 || $maximumDurability > 65_535)) {
            throw new InvalidArgumentException('Item durability must be between one and 65535.');
        }
        if ($maximumDurability !== null && $maximumStackSize !== 1) {
            throw new InvalidArgumentException('Damageable items must have a maximum stack size of one.');
        }
    }

    public function isPlaceable(): bool
    {
        return $this->placedBlockState !== null;
    }

    public function itemBlockState(): ?CanonicalBlockState
    {
        return $this->networkBlockState ?? $this->placedBlockState;
    }

    public function durability(): ?int
    {
        if ($this->maximumDurability !== null) {
            return $this->maximumDurability;
        }
        if ($this->tool !== null) {
            return $this->tool->durability;
        }

        return $this->armor?->maximumDurability;
    }
}
