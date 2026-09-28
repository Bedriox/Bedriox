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

namespace Bedriox\Server\Gameplay\Block;

use Bedriox\Data\BlockItemMappingRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Gameplay\Item\ToolTier;
use Bedriox\Server\Gameplay\Item\ToolType;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Block\VanillaBlockStates;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use InvalidArgumentException;

/** Bounded gameplay definitions for generated terrain and admitted block-item states. */
final readonly class BlockCatalog
{
    /** @var array<string, BlockType> */
    private array $typesByStateKey;

    /** @var array<string, BlockType> */
    private array $typesByIdentifier;

    /** @param list<BlockType> $types */
    public function __construct(array $types)
    {
        if ($types === [] || count($types) > 2_048) {
            throw new InvalidArgumentException('Block catalog must be non-empty and bounded.');
        }
        $byState = [];
        $byIdentifier = [];
        foreach ($types as $type) {
            $key = $type->state->canonicalKey();
            $identifier = $type->identifier();
            if (isset($byState[$key]) || isset($byIdentifier[$identifier])) {
                throw new InvalidArgumentException('Block catalog contains a duplicate state or identifier.');
            }
            $byState[$key] = $type;
            $byIdentifier[$identifier] = $type;
        }
        $this->typesByStateKey = $byState;
        $this->typesByIdentifier = $byIdentifier;
    }

    public static function vanilla(
        ?BlockStateRegistry $registry = null,
        ?BlockItemMappingRegistry $blockItems = null,
    ): self {
        $pickaxe = ToolType::Pickaxe;
        $shovel = ToolType::Shovel;
        $axe = ToolType::Axe;

        $types = [
            new BlockType(VanillaBlockStates::air(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::bedrock(), -1.0, null, null, BlockDropKind::None),
            new BlockType(VanillaBlockStates::stone(), 1.5, $pickaxe, ToolTier::Wood, BlockDropKind::Cobblestone),
            new BlockType(VanillaBlockStates::cobblestone(), 2.0, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::cobbledDeepslate(), 3.5, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::dirt(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::grassBlock(), 0.6, $shovel, null, BlockDropKind::Dirt),
            new BlockType(VanillaBlockStates::sand(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::sandstone(), 0.8, $pickaxe, ToolTier::Wood, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::gravel(), 0.6, $shovel, null, BlockDropKind::Gravel),
            new BlockType(VanillaBlockStates::water(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::coalOre(), 3.0, $pickaxe, ToolTier::Wood, BlockDropKind::Coal),
            new BlockType(VanillaBlockStates::ironOre(), 3.0, $pickaxe, ToolTier::Stone, BlockDropKind::RawIron),
            new BlockType(VanillaBlockStates::oakLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::oakLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::OakLeaves),
            new BlockType(VanillaBlockStates::clay(), 0.6, $shovel, null, BlockDropKind::ClayBalls),
            new BlockType(VanillaBlockStates::ice(), 0.5, $pickaxe, null, BlockDropKind::Ice),
            new BlockType(VanillaBlockStates::snow(), 0.2, $shovel, ToolTier::Wood, BlockDropKind::Snowballs),
            new BlockType(VanillaBlockStates::coarseDirt(), 0.5, $shovel, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::podzol(), 0.5, $shovel, null, BlockDropKind::Dirt),
            new BlockType(VanillaBlockStates::deepslate(), 3.0, $pickaxe, ToolTier::Wood, BlockDropKind::CobbledDeepslate),
            new BlockType(VanillaBlockStates::lava(), -1.0, null, null, BlockDropKind::None, false),
            new BlockType(VanillaBlockStates::copperOre(), 3.0, $pickaxe, ToolTier::Stone, BlockDropKind::RawCopper),
            new BlockType(VanillaBlockStates::goldOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::RawGold),
            new BlockType(VanillaBlockStates::redstoneOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::Redstone),
            new BlockType(VanillaBlockStates::diamondOre(), 3.0, $pickaxe, ToolTier::Iron, BlockDropKind::Diamond),
            new BlockType(VanillaBlockStates::birchLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::birchLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::BirchLeaves),
            new BlockType(VanillaBlockStates::spruceLog(), 2.0, $axe, null, BlockDropKind::Self),
            new BlockType(VanillaBlockStates::spruceLeaves(), 0.2, ToolType::Hoe, null, BlockDropKind::SpruceLeaves),
        ];
        $indicesByIdentifier = [];
        foreach ($types as $index => $type) {
            $indicesByIdentifier[$type->identifier()] = $index;
        }
        if ($registry !== null) {
            foreach (GenerationBlockPalette::fromRegistry($registry)->states() as $stateId) {
                $state = $registry->state($stateId);
                if (!isset($indicesByIdentifier[$state->identifier()])) {
                    $types[] = self::generatedType($state);
                    $indicesByIdentifier[$state->identifier()] = count($types) - 1;
                }
            }
        }
        if ($blockItems !== null) {
            foreach ($blockItems->mappings() as $mapping) {
                $state = $mapping->blockState();
                if ($registry !== null) {
                    $registry->internalId($state);
                }
                $index = $indicesByIdentifier[$state->identifier()] ?? null;
                if ($index !== null) {
                    $types[$index] = $types[$index]->withItemIdentifier($mapping->itemIdentifier());
                    continue;
                }
                $types[] = self::mappedType($state, $mapping->itemIdentifier());
                $indicesByIdentifier[$state->identifier()] = count($types) - 1;
            }
        }

        return new self(array_values($types));
    }

    public function typeForState(CanonicalBlockState $state): BlockType
    {
        return $this->typesByStateKey[$state->canonicalKey()]
            ?? $this->typesByIdentifier[$state->identifier()]
            ?? throw new InvalidArgumentException('Block state is not present in the gameplay catalog.');
    }

    public function findTypeForState(CanonicalBlockState $state): ?BlockType
    {
        return $this->typesByStateKey[$state->canonicalKey()]
            ?? $this->typesByIdentifier[$state->identifier()]
            ?? null;
    }

    public function typeForInternalId(InternalBlockStateId $id, BlockStateRegistry $registry): BlockType
    {
        return $this->typeForState($registry->state($id));
    }

    public function findTypeForInternalId(InternalBlockStateId $id, BlockStateRegistry $registry): ?BlockType
    {
        return $this->findTypeForState($registry->state($id));
    }

    public function type(string $identifier): BlockType
    {
        return $this->typesByIdentifier[$identifier]
            ?? throw new InvalidArgumentException('Block is not present in the gameplay catalog.');
    }

    public function has(string $identifier): bool
    {
        return isset($this->typesByIdentifier[$identifier]);
    }

    /** @return list<BlockType> */
    public function all(): array
    {
        return array_values($this->typesByStateKey);
    }

    private static function generatedType(CanonicalBlockState $state): BlockType
    {
        $identifier = $state->identifier();
        $hasItemForm = !in_array($identifier, [
            'minecraft:air',
            'minecraft:water',
            'minecraft:lava',
            'minecraft:farmland',
            'minecraft:wheat',
            'minecraft:grass_path',
        ], true);
        if (in_array($identifier, self::unbreakableGeneratedIdentifiers(), true)) {
            return new BlockType($state, -1.0, null, null, BlockDropKind::None, $hasItemForm);
        }
        if (in_array($identifier, self::instantGeneratedIdentifiers(), true)) {
            return new BlockType(
                $state,
                0.0,
                null,
                null,
                BlockDropKind::None,
                $hasItemForm,
            );
        }
        if (str_ends_with($identifier, '_log') || str_ends_with($identifier, '_planks')
            || $identifier === 'minecraft:bookshelf' || $identifier === 'minecraft:hay_block'
            || $identifier === 'minecraft:bamboo') {
            return new BlockType($state, 2.0, ToolType::Axe, null, BlockDropKind::None, $hasItemForm);
        }
        if (str_ends_with($identifier, '_leaves') || $identifier === 'minecraft:azalea_leaves_flowered') {
            return new BlockType($state, 0.2, ToolType::Hoe, null, BlockDropKind::None, $hasItemForm);
        }
        if (in_array($identifier, self::shovelGeneratedIdentifiers(), true)) {
            return new BlockType(
                $state,
                $identifier === 'minecraft:snow_layer' ? 0.1 : 0.5,
                ToolType::Shovel,
                null,
                BlockDropKind::None,
                $hasItemForm,
            );
        }

        return new BlockType(
            $state,
            1.5,
            ToolType::Pickaxe,
            null,
            BlockDropKind::None,
            $hasItemForm,
        );
    }

    private static function mappedType(CanonicalBlockState $state, string $itemIdentifier): BlockType
    {
        if ($state->identifier() === 'minecraft:crafting_table') {
            return new BlockType(
                $state,
                2.5,
                ToolType::Axe,
                null,
                BlockDropKind::Self,
                true,
                $itemIdentifier,
            );
        }
        $generated = self::generatedType($state);

        return new BlockType(
            $state,
            $generated->hardness,
            $generated->preferredTool,
            $generated->requiredTier,
            $generated->isBreakable() ? BlockDropKind::Self : BlockDropKind::None,
            true,
            $itemIdentifier,
        );
    }

    /** @return list<string> */
    private static function unbreakableGeneratedIdentifiers(): array
    {
        return ['minecraft:air', 'minecraft:bedrock', 'minecraft:water', 'minecraft:lava'];
    }

    /** @return list<string> */
    private static function instantGeneratedIdentifiers(): array
    {
        return [
            'minecraft:wheat', 'minecraft:deadbush', 'minecraft:short_grass', 'minecraft:tall_grass',
            'minecraft:dandelion', 'minecraft:poppy', 'minecraft:blue_orchid', 'minecraft:allium',
            'minecraft:azure_bluet', 'minecraft:red_tulip', 'minecraft:white_tulip',
            'minecraft:pink_tulip', 'minecraft:oxeye_daisy', 'minecraft:lily_of_the_valley',
            'minecraft:glow_lichen', 'minecraft:hanging_roots', 'minecraft:spore_blossom',
            'minecraft:brown_mushroom', 'minecraft:red_mushroom', 'minecraft:vine',
            'minecraft:kelp', 'minecraft:seagrass', 'minecraft:rail', 'minecraft:torch',
        ];
    }

    /** @return list<string> */
    private static function shovelGeneratedIdentifiers(): array
    {
        return [
            'minecraft:dirt', 'minecraft:grass_block', 'minecraft:sand', 'minecraft:red_sand',
            'minecraft:gravel', 'minecraft:clay', 'minecraft:snow', 'minecraft:snow_layer',
            'minecraft:podzol', 'minecraft:coarse_dirt', 'minecraft:mud', 'minecraft:farmland',
            'minecraft:grass_path', 'minecraft:soul_sand',
        ];
    }
}
