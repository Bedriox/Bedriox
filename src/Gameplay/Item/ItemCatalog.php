<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

use Bedriox\Data\BlockItemMappingRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\CreativeInventoryRegistry;
use Bedriox\Data\ItemNetworkRegistry;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use InvalidArgumentException;

/** Bounded canonical item catalog admitted by the active Bedrock data set. */
final class ItemCatalog
{
    /** @var array<string, ItemType> */
    private array $types;
    private int $revision = 0;
    private ItemNetworkRegistry $networkRegistry;

    /** @param list<ItemType> $types */
    public function __construct(array $types, ItemNetworkRegistry $networkRegistry)
    {
        if ($types === [] || count($types) > 5_000) {
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
        $this->networkRegistry = $networkRegistry;
    }

    public static function vanilla(
        ItemNetworkRegistry $networkRegistry,
        ?BlockCatalog $blocks = null,
        ?CreativeInventoryRegistry $creative = null,
        ?BlockItemMappingRegistry $blockItems = null,
    ): self {
        /** @var array<string, ItemType> $types */
        $types = [];
        foreach (($blocks ?? BlockCatalog::vanilla())->all() as $block) {
            $state = $block->itemFormState();
            $identifier = $block->itemIdentifier();
            if ($state !== null && $identifier !== null) {
                $types[$identifier] = new ItemType($identifier, placedBlockState: $state);
            }
        }
        foreach (self::dropOnlyIdentifiers() as $identifier => $maximumStackSize) {
            $types[$identifier] = new ItemType(
                $identifier,
                $maximumStackSize,
                networkBlockState: self::dropOnlyBlockState($identifier),
            );
        }
        foreach (self::tierIdentifiers() as [$toolTier, $prefix]) {
            foreach (self::tieredToolNames() as $suffix => $toolType) {
                $identifier = 'minecraft:' . $prefix . '_' . $suffix;
                $types[$identifier] = new ItemType(
                    $identifier,
                    1,
                    ToolDefinition::tiered($toolType, $toolTier),
                );
            }
        }
        $types['minecraft:shears'] = new ItemType('minecraft:shears', 1, ToolDefinition::shears());

        /** @var array<string, CanonicalBlockState> $creativeBlockStates */
        $creativeBlockStates = [];
        /** @var array<string, true> $creativeIdentifiers */
        $creativeIdentifiers = [];
        if ($creative !== null) {
            foreach ($creative->entries() as $entry) {
                $item = $entry->item();
                $creativeIdentifiers[$item->identifier()] = true;
                if ($item->blockState() !== null) {
                    $creativeBlockStates[$item->identifier()] ??= $item->blockState();
                }
            }
        }
        if ($blockItems !== null) {
            foreach ($blockItems->mappings() as $mapping) {
                $creativeBlockStates[$mapping->itemIdentifier()] = $mapping->blockState();
            }
        }
        if (!isset($creativeBlockStates['minecraft:shulker_box'])
            && isset($creativeBlockStates['minecraft:undyed_shulker_box'])) {
            $creativeBlockStates['minecraft:shulker_box'] = $creativeBlockStates['minecraft:undyed_shulker_box'];
        }
        if (isset($creativeBlockStates['minecraft:stonecutter_block'])) {
            $creativeBlockStates['minecraft:stonecutter'] = $creativeBlockStates['minecraft:stonecutter_block'];
        }
        foreach ($networkRegistry->definitions() as $identifier => $_definition) {
            if ($identifier === 'minecraft:air') {
                continue;
            }
            $existing = $types[$identifier] ?? null;
            $mappedBlockState = $creativeBlockStates[$identifier] ?? null;
            $armor = VanillaArmorDefinitions::definition($identifier) ?? $existing?->armor;
            $maximumDurability = VanillaItemDurability::maximum($identifier) ?? $existing?->maximumDurability;
            $types[$identifier] = new ItemType(
                $identifier,
                maximumStackSize: $armor !== null || $maximumDurability !== null || self::isShulkerBox($identifier)
                    ? 1
                    : ($existing === null ? 64 : $existing->maximumStackSize),
                tool: $existing?->tool,
                placedBlockState: $mappedBlockState ?? $existing?->placedBlockState,
                networkBlockState: $mappedBlockState ?? $existing?->networkBlockState,
                creative: $creative === null ? ($existing !== null && $existing->creative) : isset($creativeIdentifiers[$identifier]),
                owner: $existing?->owner,
                armor: $armor,
                allowedInOffhand: true,
                maximumDurability: $maximumDurability,
            );
        }

        return new self(array_values($types), $networkRegistry);
    }

    private static function isShulkerBox(string $identifier): bool
    {
        return $identifier === 'minecraft:shulker_box' || str_ends_with($identifier, '_shulker_box');
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

    public function register(ItemType $type, bool $replace = false): void
    {
        if (isset($this->types[$type->identifier]) && !$replace) {
            throw new InvalidArgumentException('Item definition already exists.');
        }
        if (!isset($this->types[$type->identifier]) && count($this->types) >= 5_000) {
            throw new InvalidArgumentException('Item catalog capacity is exhausted.');
        }
        $this->networkRegistry->definitionForIdentifier($type->identifier);
        $this->types[$type->identifier] = $type;
        ++$this->revision;
    }

    public function revision(): int
    {
        return $this->revision;
    }

    /** @return list<ItemType> */
    public function all(): array
    {
        return array_values($this->types);
    }

    /** @return list<ItemType> */
    public function creativeItems(): array
    {
        return array_values(array_filter($this->types, static fn(ItemType $type): bool => $type->creative));
    }

    /** @return list<string> */
    public function commandIdentifiers(): array
    {
        $identifiers = [];
        foreach ($this->types as $type) {
            $identifiers[] = str_starts_with($type->identifier, 'minecraft:')
                ? substr($type->identifier, strlen('minecraft:'))
                : $type->identifier;
        }
        natcasesort($identifiers);

        return array_values($identifiers);
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
