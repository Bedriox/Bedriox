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

namespace Bedriox\Server\World\Generation;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockAxis;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Bounded set of canonical block states used by the built-in overworld generator. */
final readonly class GenerationBlockPalette
{
    /** @var array<string, InternalBlockStateId> */
    private array $statesByIdentifier;

    /** @var array<string, array<string, InternalBlockStateId>> */
    private array $pillarStatesByIdentifier;

    /** @var list<InternalBlockStateId> */
    private array $states;

    /** @var array<int, int> */
    private array $indicesByState;

    /**
     * @param array<string, InternalBlockStateId> $statesByIdentifier
     * @param array<string, array<string, InternalBlockStateId>> $pillarStatesByIdentifier
     */
    private function __construct(array $statesByIdentifier, array $pillarStatesByIdentifier)
    {
        $states = [];
        $seen = [];
        foreach ($statesByIdentifier as $state) {
            $states[] = $state;
            $seen[$state->value] = true;
        }
        foreach ($pillarStatesByIdentifier as $variants) {
            foreach ($variants as $state) {
                if (!isset($seen[$state->value])) {
                    $states[] = $state;
                    $seen[$state->value] = true;
                }
            }
        }
        if ($states === [] || count($states) > 256) {
            throw new InvalidArgumentException('Generation block palette must contain between 1 and 256 states.');
        }
        $indices = [];
        foreach ($states as $index => $state) {
            $indices[$state->value] = $index;
        }
        $this->statesByIdentifier = $statesByIdentifier;
        $this->pillarStatesByIdentifier = $pillarStatesByIdentifier;
        $this->states = $states;
        $this->indicesByState = $indices;
    }

    public static function fromRegistry(BlockStateRegistry $registry): self
    {
        $firstByIdentifier = [];
        foreach ($registry->states() as $state) {
            $firstByIdentifier[$state->identifier()] ??= $state;
        }
        $identifiers = [
            'minecraft:air', 'minecraft:bedrock', 'minecraft:stone', 'minecraft:deepslate',
            'minecraft:water', 'minecraft:lava', 'minecraft:dirt', 'minecraft:grass_block',
            'minecraft:sand', 'minecraft:red_sand', 'minecraft:sandstone', 'minecraft:red_sandstone',
            'minecraft:gravel', 'minecraft:clay', 'minecraft:snow', 'minecraft:snow_layer',
            'minecraft:ice', 'minecraft:packed_ice', 'minecraft:podzol', 'minecraft:coarse_dirt',
            'minecraft:mud', 'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:calcite',
            'minecraft:tuff', 'minecraft:granite', 'minecraft:diorite', 'minecraft:andesite',
            'minecraft:white_terracotta', 'minecraft:orange_terracotta', 'minecraft:yellow_terracotta',
            'minecraft:brown_terracotta', 'minecraft:red_terracotta', 'minecraft:cobblestone',
            'minecraft:mossy_cobblestone', 'minecraft:oak_log', 'minecraft:oak_leaves',
            'minecraft:birch_log', 'minecraft:birch_leaves', 'minecraft:spruce_log',
            'minecraft:spruce_leaves', 'minecraft:acacia_log', 'minecraft:acacia_leaves',
            'minecraft:dark_oak_log', 'minecraft:dark_oak_leaves', 'minecraft:jungle_log',
            'minecraft:jungle_leaves', 'minecraft:cherry_log', 'minecraft:cherry_leaves',
            'minecraft:mangrove_log', 'minecraft:mangrove_leaves', 'minecraft:pale_oak_log',
            'minecraft:pale_oak_leaves', 'minecraft:oak_planks', 'minecraft:spruce_planks',
            'minecraft:birch_planks', 'minecraft:jungle_planks', 'minecraft:acacia_planks',
            'minecraft:dark_oak_planks', 'minecraft:cherry_planks', 'minecraft:mangrove_planks',
            'minecraft:pale_oak_planks', 'minecraft:glass_pane', 'minecraft:lantern',
            'minecraft:hay_block', 'minecraft:farmland', 'minecraft:wheat', 'minecraft:grass_path',
            'minecraft:bamboo', 'minecraft:cactus', 'minecraft:deadbush', 'minecraft:short_grass',
            'minecraft:tall_grass', 'minecraft:dandelion', 'minecraft:poppy', 'minecraft:blue_orchid',
            'minecraft:allium', 'minecraft:azure_bluet', 'minecraft:red_tulip', 'minecraft:white_tulip',
            'minecraft:pink_tulip', 'minecraft:oxeye_daisy', 'minecraft:lily_of_the_valley',
            'minecraft:coal_ore', 'minecraft:iron_ore', 'minecraft:copper_ore', 'minecraft:gold_ore',
            'minecraft:redstone_ore', 'minecraft:diamond_ore', 'minecraft:emerald_ore',
            'minecraft:lapis_ore', 'minecraft:dripstone_block', 'minecraft:pointed_dripstone',
            'minecraft:sculk', 'minecraft:glow_lichen', 'minecraft:azalea_leaves',
            'minecraft:azalea_leaves_flowered', 'minecraft:dirt_with_roots', 'minecraft:hanging_roots',
            'minecraft:spore_blossom', 'minecraft:amethyst_block', 'minecraft:budding_amethyst',
            'minecraft:mycelium', 'minecraft:brown_mushroom', 'minecraft:red_mushroom',
            'minecraft:vine', 'minecraft:kelp', 'minecraft:seagrass', 'minecraft:blue_ice',
            'minecraft:tube_coral_block', 'minecraft:brain_coral_block', 'minecraft:bubble_coral_block',
            'minecraft:fire_coral_block', 'minecraft:horn_coral_block', 'minecraft:stone_bricks',
            'minecraft:mossy_stone_bricks', 'minecraft:cracked_stone_bricks',
            'minecraft:chiseled_stone_bricks', 'minecraft:polished_andesite', 'minecraft:smooth_stone',
            'minecraft:obsidian', 'minecraft:crying_obsidian', 'minecraft:netherrack',
            'minecraft:magma', 'minecraft:gold_block', 'minecraft:prismarine',
            'minecraft:prismarine_bricks', 'minecraft:dark_prismarine', 'minecraft:sea_lantern',
            'minecraft:sponge', 'minecraft:wet_sponge', 'minecraft:cut_sandstone',
            'minecraft:smooth_sandstone', 'minecraft:chiseled_sandstone', 'minecraft:iron_bars',
            'minecraft:polished_blackstone_bricks', 'minecraft:basalt', 'minecraft:soul_sand',
            'minecraft:rail', 'minecraft:bookshelf', 'minecraft:torch',
        ];
        $resolved = [];
        $pillarStates = [];
        foreach ($identifiers as $identifier) {
            $state = $firstByIdentifier[$identifier] ?? null;
            if (!$state instanceof CanonicalBlockState) {
                throw new InvalidArgumentException("Pinned block registry does not contain required generation state $identifier.");
            }
            $properties = $state->properties();
            if (array_key_exists('pillar_axis', $properties)) {
                foreach (BlockAxis::cases() as $axis) {
                    $variantProperties = $properties;
                    $variantProperties['pillar_axis'] = $axis->value;
                    try {
                        $pillarStates[$identifier][$axis->value] = $registry->internalId(
                            CanonicalBlockState::from($identifier, $variantProperties),
                        );
                    } catch (InvalidArgumentException $error) {
                        throw new InvalidArgumentException(
                            "Pinned block registry does not contain required $axis->value-axis generation state $identifier.",
                            previous: $error,
                        );
                    }
                }
                $resolved[$identifier] = $pillarStates[$identifier][BlockAxis::Y->value];
            } else {
                $resolved[$identifier] = $registry->internalId($state);
            }
        }

        return new self($resolved, $pillarStates);
    }

    public function state(string $identifier, ?BlockAxis $axis = null): InternalBlockStateId
    {
        if ($axis !== null) {
            return $this->pillarStatesByIdentifier[$identifier][$axis->value]
                ?? throw new InvalidArgumentException("Generation block state $identifier does not define pillar_axis.");
        }

        return $this->statesByIdentifier[$identifier]
            ?? throw new InvalidArgumentException("Generation block state $identifier is not registered.");
    }

    public function index(InternalBlockStateId $state): int
    {
        return $this->indicesByState[$state->value]
            ?? throw new InvalidArgumentException('Block state is outside the generation palette.');
    }

    /** @return list<InternalBlockStateId> */
    public function states(): array
    {
        return $this->states;
    }
}
