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

namespace Bedriox\Server\World\Collision;

use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use LogicException;

/** Bounded process-local collision definitions for generation states and runtime-created fluid variants. */
final readonly class BlockCollisionRegistry
{
    /** @var array<int, BlockCollisionShape> */
    private array $shapes;

    /** @param array<int, BlockCollisionShape> $shapes */
    private function __construct(array $shapes)
    {
        if ($shapes === [] || count($shapes) > 512) {
            throw new LogicException('Block collision registry must contain between 1 and 512 supported states.');
        }
        $this->shapes = $shapes;
    }

    public static function forGenerationPalette(
        BlockStateRegistry $states,
        GenerationBlockPalette $palette,
    ): self {
        $shapes = [];
        foreach ($palette->states() as $stateId) {
            $shapes[$stateId->value] = self::shapeForState($states->state($stateId));
        }
        $generationStateCount = count($shapes);
        if ($generationStateCount !== count($palette->states())) {
            throw new LogicException('Generation collision definitions are incomplete or duplicated.');
        }
        foreach ($states->states() as $value => $state) {
            if (in_array($state->identifier(), ['minecraft:water', 'minecraft:lava', 'minecraft:portal'], true)) {
                $shapes[$value] = BlockCollisionShape::empty();
            }
        }
        if (count($shapes) < $generationStateCount) {
            throw new LogicException('Runtime fluid collision definitions are incomplete.');
        }

        return new self($shapes);
    }

    public function find(InternalBlockStateId $state): ?BlockCollisionShape
    {
        return $this->shapes[$state->value] ?? null;
    }

    public function contains(InternalBlockStateId $state): bool
    {
        return isset($this->shapes[$state->value]);
    }

    public function count(): int
    {
        return count($this->shapes);
    }

    private static function shapeForState(CanonicalBlockState $state): BlockCollisionShape
    {
        $identifier = $state->identifier();
        if (in_array($identifier, self::emptyCollisionIdentifiers(), true)) {
            return BlockCollisionShape::empty();
        }

        return match ($identifier) {
            'minecraft:snow_layer' => self::box(0.0, 0.0, 0.0, 1.0, self::snowLayerHeight($state), 1.0),
            'minecraft:farmland', 'minecraft:grass_path' => self::box(0.0, 0.0, 0.0, 1.0, 15.0 / 16.0, 1.0),
            'minecraft:cactus' => self::box(1.0 / 16.0, 0.0, 1.0 / 16.0, 15.0 / 16.0, 15.0 / 16.0, 15.0 / 16.0),
            'minecraft:bamboo' => self::box(0.0, 0.0, 0.0, 3.0 / 16.0, 1.0, 3.0 / 16.0),
            'minecraft:glass_pane', 'minecraft:iron_bars' => self::box(
                7.0 / 16.0,
                0.0,
                7.0 / 16.0,
                9.0 / 16.0,
                1.0,
                9.0 / 16.0,
            ),
            'minecraft:lantern' => self::box(5.0 / 16.0, 0.0, 5.0 / 16.0, 11.0 / 16.0, 0.5, 11.0 / 16.0),
            'minecraft:pointed_dripstone' => self::box(0.25, 0.0, 0.25, 0.75, 1.0, 0.75),
            'minecraft:mud', 'minecraft:soul_sand' => self::box(0.0, 0.0, 0.0, 1.0, 7.0 / 8.0, 1.0),
            'minecraft:chorus_plant' => self::box(3.0 / 16.0, 0.0, 3.0 / 16.0, 13.0 / 16.0, 1.0, 13.0 / 16.0),
            'minecraft:chorus_flower' => self::box(2.0 / 16.0, 0.0, 2.0 / 16.0, 14.0 / 16.0, 1.0, 14.0 / 16.0),
            'minecraft:dragon_head' => self::box(0.25, 0.0, 0.25, 0.75, 0.5, 0.75),
            'minecraft:brewing_stand' => self::box(1.0 / 8.0, 0.0, 1.0 / 8.0, 7.0 / 8.0, 7.0 / 8.0, 7.0 / 8.0),
            'minecraft:chest' => self::box(1.0 / 16.0, 0.0, 1.0 / 16.0, 15.0 / 16.0, 7.0 / 8.0, 15.0 / 16.0),
            'minecraft:end_rod' => self::box(3.0 / 8.0, 0.0, 3.0 / 8.0, 5.0 / 8.0, 1.0, 5.0 / 8.0),
            default => self::fullCubeFor($identifier),
        };
    }

    private static function snowLayerHeight(CanonicalBlockState $state): float
    {
        $height = $state->properties()['height'] ?? null;
        if (!is_int($height) || $height < 0 || $height > 7) {
            throw new LogicException('Generation snow layer has an unsupported height state.');
        }

        return ($height + 1) / 8.0;
    }

    private static function box(
        float $minX,
        float $minY,
        float $minZ,
        float $maxX,
        float $maxY,
        float $maxZ,
    ): BlockCollisionShape {
        return BlockCollisionShape::fromBoxes([
            new AxisAlignedBox($minX, $minY, $minZ, $maxX, $maxY, $maxZ),
        ]);
    }

    private static function fullCubeFor(string $identifier): BlockCollisionShape
    {
        if (!in_array($identifier, self::fullCubeIdentifiers(), true)) {
            throw new LogicException("Generation block $identifier has no collision definition.");
        }

        return BlockCollisionShape::fullCube();
    }

    /** @return list<string> */
    private static function emptyCollisionIdentifiers(): array
    {
        return [
            'minecraft:air',
            'minecraft:water',
            'minecraft:lava',
            'minecraft:wheat',
            'minecraft:deadbush',
            'minecraft:short_grass',
            'minecraft:tall_grass',
            'minecraft:dandelion',
            'minecraft:poppy',
            'minecraft:blue_orchid',
            'minecraft:allium',
            'minecraft:azure_bluet',
            'minecraft:red_tulip',
            'minecraft:white_tulip',
            'minecraft:pink_tulip',
            'minecraft:oxeye_daisy',
            'minecraft:lily_of_the_valley',
            'minecraft:glow_lichen',
            'minecraft:hanging_roots',
            'minecraft:spore_blossom',
            'minecraft:brown_mushroom',
            'minecraft:red_mushroom',
            'minecraft:vine',
            'minecraft:kelp',
            'minecraft:seagrass',
            'minecraft:rail',
            'minecraft:torch',
            'minecraft:fire',
            'minecraft:soul_fire',
            'minecraft:crimson_roots',
            'minecraft:warped_roots',
            'minecraft:nether_sprouts',
            'minecraft:weeping_vines',
            'minecraft:twisting_vines',
            'minecraft:end_portal',
        ];
    }

    /** @return list<string> */
    private static function fullCubeIdentifiers(): array
    {
        return [
            'minecraft:bedrock', 'minecraft:stone', 'minecraft:deepslate', 'minecraft:dirt',
            'minecraft:grass_block', 'minecraft:sand', 'minecraft:red_sand', 'minecraft:sandstone',
            'minecraft:red_sandstone', 'minecraft:gravel', 'minecraft:clay', 'minecraft:snow',
            'minecraft:ice', 'minecraft:packed_ice', 'minecraft:podzol', 'minecraft:coarse_dirt',
            'minecraft:moss_block', 'minecraft:pale_moss_block', 'minecraft:calcite',
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
            'minecraft:pale_oak_planks', 'minecraft:hay_block', 'minecraft:coal_ore',
            'minecraft:iron_ore', 'minecraft:copper_ore', 'minecraft:gold_ore',
            'minecraft:redstone_ore', 'minecraft:diamond_ore', 'minecraft:emerald_ore',
            'minecraft:lapis_ore', 'minecraft:dripstone_block', 'minecraft:sculk',
            'minecraft:azalea_leaves', 'minecraft:azalea_leaves_flowered', 'minecraft:dirt_with_roots',
            'minecraft:amethyst_block', 'minecraft:budding_amethyst', 'minecraft:mycelium',
            'minecraft:blue_ice', 'minecraft:tube_coral_block', 'minecraft:brain_coral_block',
            'minecraft:bubble_coral_block', 'minecraft:fire_coral_block', 'minecraft:horn_coral_block',
            'minecraft:stone_bricks', 'minecraft:mossy_stone_bricks', 'minecraft:cracked_stone_bricks',
            'minecraft:chiseled_stone_bricks', 'minecraft:polished_andesite', 'minecraft:smooth_stone',
            'minecraft:obsidian', 'minecraft:crying_obsidian', 'minecraft:netherrack',
            'minecraft:magma', 'minecraft:gold_block', 'minecraft:prismarine',
            'minecraft:prismarine_bricks', 'minecraft:dark_prismarine', 'minecraft:sea_lantern',
            'minecraft:sponge', 'minecraft:wet_sponge', 'minecraft:cut_sandstone',
            'minecraft:smooth_sandstone', 'minecraft:chiseled_sandstone',
            'minecraft:polished_blackstone_bricks', 'minecraft:nether_brick', 'minecraft:basalt', 'minecraft:bookshelf',
            'minecraft:soul_soil', 'minecraft:blackstone', 'minecraft:glowstone',
            'minecraft:quartz_ore', 'minecraft:nether_gold_ore', 'minecraft:nether_wart_block',
            'minecraft:warped_wart_block', 'minecraft:crimson_nylium', 'minecraft:warped_nylium',
            'minecraft:shroomlight', 'minecraft:crimson_stem', 'minecraft:warped_stem',
            'minecraft:end_stone', 'minecraft:purpur_block', 'minecraft:purpur_pillar',
        ];
    }
}
