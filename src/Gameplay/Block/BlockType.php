<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use InvalidArgumentException;

/** Canonical server-owned properties of one supported block state. */
final readonly class BlockType
{
    public function __construct(
        public CanonicalBlockState $state,
        public float $hardness,
        public ?ToolType $preferredTool,
        public ?ToolTier $requiredTier,
        public BlockDropKind $dropKind,
        public bool $hasItemForm = true,
    ) {
        if (!is_finite($hardness) || $hardness < -1.0) {
            throw new InvalidArgumentException('Block hardness must be finite and at least negative one.');
        }
        if ($requiredTier !== null && $preferredTool === null) {
            throw new InvalidArgumentException('A tier requirement needs a preferred tool type.');
        }
    }

    public function identifier(): string
    {
        return $this->state->identifier();
    }

    public function isBreakable(): bool
    {
        return $this->hardness >= 0.0;
    }

    public function itemFormState(): ?CanonicalBlockState
    {
        return $this->hasItemForm ? $this->state : null;
    }
}
