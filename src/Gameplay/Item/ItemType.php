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
    }

    public function isPlaceable(): bool
    {
        return $this->placedBlockState !== null;
    }

    public function itemBlockState(): ?CanonicalBlockState
    {
        return $this->networkBlockState ?? $this->placedBlockState;
    }
}
