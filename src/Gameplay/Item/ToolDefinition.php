<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use InvalidArgumentException;

/** Immutable mining and durability properties of one tool item. */
final readonly class ToolDefinition
{
    private function __construct(
        public ToolType $type,
        public ?ToolTier $tier,
        public int $durability,
        public float $miningEfficiency,
        public int $durabilityDamagePerBlock,
    ) {
        if ($durability < 1 || $miningEfficiency <= 0.0 || $durabilityDamagePerBlock < 1) {
            throw new InvalidArgumentException('Tool properties must be positive.');
        }
    }

    public static function tiered(ToolType $type, ToolTier $tier): self
    {
        if ($type === ToolType::Shears) {
            throw new InvalidArgumentException('Shears are not a tiered tool.');
        }

        return new self(
            $type,
            $tier,
            $tier->durability(),
            $tier->miningEfficiency(),
            $type === ToolType::Sword ? 2 : 1,
        );
    }

    public static function shears(): self
    {
        return new self(ToolType::Shears, null, 239, 1.0, 1);
    }

    public function harvestLevel(): int
    {
        return $this->tier?->harvestLevel() ?? 0;
    }
}
