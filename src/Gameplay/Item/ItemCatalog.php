<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\ItemNetworkRegistry;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use InvalidArgumentException;

/** Bounded canonical item catalog admitted by the active Bedrock data set. */
final readonly class ItemCatalog
{
    /** @var array<string, ItemType> */
    private array $types;

    /** @param list<ItemType> $types */
    public function __construct(array $types, ItemNetworkRegistry $networkRegistry)
    {
        if ($types === [] || count($types) > 1_024) {
            throw new InvalidArgumentException('Item catalog must be non-empty and bounded.');
        }
        $indexed = [];
        foreach ($types as $type) {
            if (isset($indexed[$type->identifier])) {
                throw new InvalidArgumentException('Item catalog contains a duplicate identifier.');
            }
            $networkRegistry->definitionForIdentifier($type->identifier);
            $indexed[$type->identifier] = $type;
        }
        $this->types = $indexed;
    }

    public static function vanilla(ItemNetworkRegistry $networkRegistry, ?BlockCatalog $blocks = null): self
    {
        $types = [];
        foreach (($blocks ?? BlockCatalog::vanilla())->all() as $block) {
            $state = $block->itemFormState();
            if ($state !== null) {
                $types[] = new ItemType($block->identifier(), placedBlockState: $state);
            }
        }
        foreach (self::dropOnlyIdentifiers() as $identifier => $maximumStackSize) {
            $types[] = new ItemType(
                $identifier,
                $maximumStackSize,
                networkBlockState: self::dropOnlyBlockState($identifier),
            );
        }
        foreach (self::tierIdentifiers() as [$toolTier, $prefix]) {
            foreach (self::tieredToolNames() as $suffix => $toolType) {
                $types[] = new ItemType(
                    'minecraft:' . $prefix . '_' . $suffix,
                    1,
                    ToolDefinition::tiered($toolType, $toolTier),
                );
            }
        }
        $types[] = new ItemType('minecraft:shears', 1, ToolDefinition::shears());

        return new self($types, $networkRegistry);
    }

    public function type(string $identifier): ItemType
    {
        return $this->types[$identifier]
            ?? throw new InvalidArgumentException('Item is not present in the gameplay catalog.');
    }

    public function has(string $identifier): bool
    {
        return isset($this->types[$identifier]);
    }

    /** @return list<ItemType> */
    public function all(): array
    {
        return array_values($this->types);
    }

    /** @return list<ItemType> */
    public function creativeItems(): array
    {
        return $this->all();
    }

    /** @return array<string, int> */
    private static function dropOnlyIdentifiers(): array
    {
        return [
            'minecraft:coal' => 64,
            'minecraft:raw_copper' => 64,
            'minecraft:raw_iron' => 64,
            'minecraft:raw_gold' => 64,
            'minecraft:redstone' => 64,
            'minecraft:diamond' => 64,
            'minecraft:clay_ball' => 64,
            'minecraft:snowball' => 16,
            'minecraft:flint' => 64,
            'minecraft:stick' => 64,
            'minecraft:apple' => 64,
            'minecraft:oak_sapling' => 64,
            'minecraft:birch_sapling' => 64,
            'minecraft:spruce_sapling' => 64,
        ];
    }

    private static function dropOnlyBlockState(string $identifier): ?CanonicalBlockState
    {
        return match ($identifier) {
            'minecraft:oak_sapling', 'minecraft:birch_sapling', 'minecraft:spruce_sapling' =>
                CanonicalBlockState::from($identifier, ['age_bit' => 0]),
            default => null,
        };
    }

    /** @return list<array{ToolTier, string}> */
    private static function tierIdentifiers(): array
    {
        return [
            [ToolTier::Wood, 'wooden'],
            [ToolTier::Gold, 'golden'],
            [ToolTier::Stone, 'stone'],
            [ToolTier::Copper, 'copper'],
            [ToolTier::Iron, 'iron'],
            [ToolTier::Diamond, 'diamond'],
            [ToolTier::Netherite, 'netherite'],
        ];
    }

    /** @return array<string, ToolType> */
    private static function tieredToolNames(): array
    {
        return [
            'pickaxe' => ToolType::Pickaxe,
            'axe' => ToolType::Axe,
            'shovel' => ToolType::Shovel,
            'hoe' => ToolType::Hoe,
            'sword' => ToolType::Sword,
        ];
    }
}
