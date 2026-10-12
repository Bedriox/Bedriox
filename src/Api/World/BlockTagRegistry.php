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

namespace Bedriox\Api\World;

use InvalidArgumentException;
use LogicException;

/** Immutable built-in semantic block membership resolved without runtime naming heuristics. */
final class BlockTagRegistry
{
    /** @var array<string, array<string, true>> */
    private const array EXTENDED_MEMBERS = [
        'stairs' => [
            'minecraft:acacia_stairs' => true, 'minecraft:andesite_stairs' => true,
            'minecraft:bamboo_mosaic_stairs' => true, 'minecraft:bamboo_stairs' => true,
            'minecraft:birch_stairs' => true, 'minecraft:black_concrete_stairs' => true,
            'minecraft:black_wool_stairs' => true, 'minecraft:blackstone_stairs' => true,
            'minecraft:blue_concrete_stairs' => true, 'minecraft:blue_wool_stairs' => true,
            'minecraft:brick_stairs' => true, 'minecraft:brown_concrete_stairs' => true,
            'minecraft:brown_wool_stairs' => true, 'minecraft:cherry_stairs' => true,
            'minecraft:cinnabar_brick_stairs' => true, 'minecraft:cinnabar_stairs' => true,
            'minecraft:cobbled_deepslate_stairs' => true, 'minecraft:crimson_stairs' => true,
            'minecraft:cut_copper_stairs' => true, 'minecraft:cyan_concrete_stairs' => true,
            'minecraft:cyan_wool_stairs' => true, 'minecraft:dark_oak_stairs' => true,
            'minecraft:dark_prismarine_stairs' => true, 'minecraft:deepslate_brick_stairs' => true,
            'minecraft:deepslate_tile_stairs' => true, 'minecraft:diorite_stairs' => true,
            'minecraft:end_brick_stairs' => true, 'minecraft:exposed_cut_copper_stairs' => true,
            'minecraft:granite_stairs' => true, 'minecraft:gray_concrete_stairs' => true,
            'minecraft:gray_wool_stairs' => true, 'minecraft:green_concrete_stairs' => true,
            'minecraft:green_wool_stairs' => true, 'minecraft:jungle_stairs' => true,
            'minecraft:light_blue_concrete_stairs' => true, 'minecraft:light_blue_wool_stairs' => true,
            'minecraft:light_gray_concrete_stairs' => true, 'minecraft:light_gray_wool_stairs' => true,
            'minecraft:lime_concrete_stairs' => true, 'minecraft:lime_wool_stairs' => true,
            'minecraft:magenta_concrete_stairs' => true, 'minecraft:magenta_wool_stairs' => true,
            'minecraft:mangrove_stairs' => true, 'minecraft:mossy_cobblestone_stairs' => true,
            'minecraft:mossy_stone_brick_stairs' => true, 'minecraft:mud_brick_stairs' => true,
            'minecraft:nether_brick_stairs' => true, 'minecraft:normal_stone_stairs' => true,
            'minecraft:oak_stairs' => true, 'minecraft:orange_concrete_stairs' => true,
            'minecraft:orange_wool_stairs' => true, 'minecraft:oxidized_cut_copper_stairs' => true,
            'minecraft:pale_oak_stairs' => true, 'minecraft:pink_concrete_stairs' => true,
            'minecraft:pink_wool_stairs' => true, 'minecraft:polished_andesite_stairs' => true,
            'minecraft:polished_blackstone_brick_stairs' => true, 'minecraft:polished_blackstone_stairs' => true,
            'minecraft:polished_cinnabar_stairs' => true, 'minecraft:polished_deepslate_stairs' => true,
            'minecraft:polished_diorite_stairs' => true, 'minecraft:polished_granite_stairs' => true,
            'minecraft:polished_sulfur_stairs' => true, 'minecraft:polished_tuff_stairs' => true,
            'minecraft:poplar_stairs' => true, 'minecraft:prismarine_bricks_stairs' => true,
            'minecraft:prismarine_stairs' => true, 'minecraft:purple_concrete_stairs' => true,
            'minecraft:purple_wool_stairs' => true, 'minecraft:purpur_stairs' => true,
            'minecraft:quartz_stairs' => true, 'minecraft:red_concrete_stairs' => true,
            'minecraft:red_nether_brick_stairs' => true, 'minecraft:red_sandstone_stairs' => true,
            'minecraft:red_wool_stairs' => true, 'minecraft:resin_brick_stairs' => true,
            'minecraft:sandstone_stairs' => true, 'minecraft:smooth_quartz_stairs' => true,
            'minecraft:smooth_red_sandstone_stairs' => true, 'minecraft:smooth_sandstone_stairs' => true,
            'minecraft:spruce_stairs' => true, 'minecraft:stone_brick_stairs' => true,
            'minecraft:stone_stairs' => true, 'minecraft:sulfur_brick_stairs' => true,
            'minecraft:sulfur_stairs' => true, 'minecraft:tuff_brick_stairs' => true,
            'minecraft:tuff_stairs' => true, 'minecraft:warped_stairs' => true,
            'minecraft:waxed_cut_copper_stairs' => true, 'minecraft:waxed_exposed_cut_copper_stairs' => true,
            'minecraft:waxed_oxidized_cut_copper_stairs' => true, 'minecraft:waxed_weathered_cut_copper_stairs' => true,
            'minecraft:weathered_cut_copper_stairs' => true, 'minecraft:white_concrete_stairs' => true,
            'minecraft:white_wool_stairs' => true, 'minecraft:yellow_concrete_stairs' => true,
            'minecraft:yellow_wool_stairs' => true,
        ],
        'double_slab' => [
            'minecraft:acacia_double_slab' => true, 'minecraft:andesite_double_slab' => true,
            'minecraft:bamboo_double_slab' => true, 'minecraft:bamboo_mosaic_double_slab' => true,
            'minecraft:birch_double_slab' => true, 'minecraft:black_concrete_double_slab' => true,
            'minecraft:black_wool_double_slab' => true, 'minecraft:blackstone_double_slab' => true,
            'minecraft:blue_concrete_double_slab' => true, 'minecraft:blue_wool_double_slab' => true,
            'minecraft:brick_double_slab' => true, 'minecraft:brown_concrete_double_slab' => true,
            'minecraft:brown_wool_double_slab' => true, 'minecraft:cherry_double_slab' => true,
            'minecraft:cinnabar_brick_double_slab' => true, 'minecraft:cinnabar_double_slab' => true,
            'minecraft:cobbled_deepslate_double_slab' => true, 'minecraft:cobblestone_double_slab' => true,
            'minecraft:crimson_double_slab' => true, 'minecraft:cut_red_sandstone_double_slab' => true,
            'minecraft:cut_sandstone_double_slab' => true, 'minecraft:cyan_concrete_double_slab' => true,
            'minecraft:cyan_wool_double_slab' => true, 'minecraft:dark_oak_double_slab' => true,
            'minecraft:dark_prismarine_double_slab' => true, 'minecraft:deepslate_brick_double_slab' => true,
            'minecraft:deepslate_tile_double_slab' => true, 'minecraft:diorite_double_slab' => true,
            'minecraft:double_cut_copper_slab' => true, 'minecraft:end_stone_brick_double_slab' => true,
            'minecraft:exposed_double_cut_copper_slab' => true, 'minecraft:granite_double_slab' => true,
            'minecraft:gray_concrete_double_slab' => true, 'minecraft:gray_wool_double_slab' => true,
            'minecraft:green_concrete_double_slab' => true, 'minecraft:green_wool_double_slab' => true,
            'minecraft:jungle_double_slab' => true, 'minecraft:light_blue_concrete_double_slab' => true,
            'minecraft:light_blue_wool_double_slab' => true, 'minecraft:light_gray_concrete_double_slab' => true,
            'minecraft:light_gray_wool_double_slab' => true, 'minecraft:lime_concrete_double_slab' => true,
            'minecraft:lime_wool_double_slab' => true, 'minecraft:magenta_concrete_double_slab' => true,
            'minecraft:magenta_wool_double_slab' => true, 'minecraft:mangrove_double_slab' => true,
            'minecraft:mossy_cobblestone_double_slab' => true, 'minecraft:mossy_stone_brick_double_slab' => true,
            'minecraft:mud_brick_double_slab' => true, 'minecraft:nether_brick_double_slab' => true,
            'minecraft:normal_stone_double_slab' => true, 'minecraft:oak_double_slab' => true,
            'minecraft:orange_concrete_double_slab' => true, 'minecraft:orange_wool_double_slab' => true,
            'minecraft:oxidized_double_cut_copper_slab' => true, 'minecraft:pale_oak_double_slab' => true,
            'minecraft:petrified_oak_double_slab' => true, 'minecraft:pink_concrete_double_slab' => true,
            'minecraft:pink_wool_double_slab' => true, 'minecraft:polished_andesite_double_slab' => true,
            'minecraft:polished_blackstone_brick_double_slab' => true, 'minecraft:polished_blackstone_double_slab' => true,
            'minecraft:polished_cinnabar_double_slab' => true, 'minecraft:polished_deepslate_double_slab' => true,
            'minecraft:polished_diorite_double_slab' => true, 'minecraft:polished_granite_double_slab' => true,
            'minecraft:polished_sulfur_double_slab' => true, 'minecraft:polished_tuff_double_slab' => true,
            'minecraft:poplar_double_slab' => true, 'minecraft:prismarine_brick_double_slab' => true,
            'minecraft:prismarine_double_slab' => true, 'minecraft:purple_concrete_double_slab' => true,
            'minecraft:purple_wool_double_slab' => true, 'minecraft:purpur_double_slab' => true,
            'minecraft:quartz_double_slab' => true, 'minecraft:red_concrete_double_slab' => true,
            'minecraft:red_nether_brick_double_slab' => true, 'minecraft:red_sandstone_double_slab' => true,
            'minecraft:red_wool_double_slab' => true, 'minecraft:resin_brick_double_slab' => true,
            'minecraft:sandstone_double_slab' => true, 'minecraft:smooth_quartz_double_slab' => true,
            'minecraft:smooth_red_sandstone_double_slab' => true, 'minecraft:smooth_sandstone_double_slab' => true,
            'minecraft:smooth_stone_double_slab' => true, 'minecraft:spruce_double_slab' => true,
            'minecraft:stone_brick_double_slab' => true, 'minecraft:sulfur_brick_double_slab' => true,
            'minecraft:sulfur_double_slab' => true, 'minecraft:tuff_brick_double_slab' => true,
            'minecraft:tuff_double_slab' => true, 'minecraft:warped_double_slab' => true,
            'minecraft:waxed_double_cut_copper_slab' => true, 'minecraft:waxed_exposed_double_cut_copper_slab' => true,
            'minecraft:waxed_oxidized_double_cut_copper_slab' => true, 'minecraft:waxed_weathered_double_cut_copper_slab' => true,
            'minecraft:weathered_double_cut_copper_slab' => true, 'minecraft:white_concrete_double_slab' => true,
            'minecraft:white_wool_double_slab' => true, 'minecraft:yellow_concrete_double_slab' => true,
            'minecraft:yellow_wool_double_slab' => true,
        ],
        'slab' => [
            'minecraft:acacia_slab' => true, 'minecraft:andesite_slab' => true,
            'minecraft:bamboo_mosaic_slab' => true, 'minecraft:bamboo_slab' => true,
            'minecraft:birch_slab' => true, 'minecraft:black_concrete_slab' => true,
            'minecraft:black_wool_slab' => true, 'minecraft:blackstone_slab' => true,
            'minecraft:blue_concrete_slab' => true, 'minecraft:blue_wool_slab' => true,
            'minecraft:brick_slab' => true, 'minecraft:brown_concrete_slab' => true,
            'minecraft:brown_wool_slab' => true, 'minecraft:cherry_slab' => true,
            'minecraft:cinnabar_brick_slab' => true, 'minecraft:cinnabar_slab' => true,
            'minecraft:cobbled_deepslate_slab' => true, 'minecraft:cobblestone_slab' => true,
            'minecraft:crimson_slab' => true, 'minecraft:cut_copper_slab' => true,
            'minecraft:cut_red_sandstone_slab' => true, 'minecraft:cut_sandstone_slab' => true,
            'minecraft:cyan_concrete_slab' => true, 'minecraft:cyan_wool_slab' => true,
            'minecraft:dark_oak_slab' => true, 'minecraft:dark_prismarine_slab' => true,
            'minecraft:deepslate_brick_slab' => true, 'minecraft:deepslate_tile_slab' => true,
            'minecraft:diorite_slab' => true, 'minecraft:end_stone_brick_slab' => true,
            'minecraft:exposed_cut_copper_slab' => true, 'minecraft:granite_slab' => true,
            'minecraft:gray_concrete_slab' => true, 'minecraft:gray_wool_slab' => true,
            'minecraft:green_concrete_slab' => true, 'minecraft:green_wool_slab' => true,
            'minecraft:jungle_slab' => true, 'minecraft:light_blue_concrete_slab' => true,
            'minecraft:light_blue_wool_slab' => true, 'minecraft:light_gray_concrete_slab' => true,
            'minecraft:light_gray_wool_slab' => true, 'minecraft:lime_concrete_slab' => true,
            'minecraft:lime_wool_slab' => true, 'minecraft:magenta_concrete_slab' => true,
            'minecraft:magenta_wool_slab' => true, 'minecraft:mangrove_slab' => true,
            'minecraft:mossy_cobblestone_slab' => true, 'minecraft:mossy_stone_brick_slab' => true,
            'minecraft:mud_brick_slab' => true, 'minecraft:nether_brick_slab' => true,
            'minecraft:normal_stone_slab' => true, 'minecraft:oak_slab' => true,
            'minecraft:orange_concrete_slab' => true, 'minecraft:orange_wool_slab' => true,
            'minecraft:oxidized_cut_copper_slab' => true, 'minecraft:pale_oak_slab' => true,
            'minecraft:petrified_oak_slab' => true, 'minecraft:pink_concrete_slab' => true,
            'minecraft:pink_wool_slab' => true, 'minecraft:polished_andesite_slab' => true,
            'minecraft:polished_blackstone_brick_slab' => true, 'minecraft:polished_blackstone_slab' => true,
            'minecraft:polished_cinnabar_slab' => true, 'minecraft:polished_deepslate_slab' => true,
            'minecraft:polished_diorite_slab' => true, 'minecraft:polished_granite_slab' => true,
            'minecraft:polished_sulfur_slab' => true, 'minecraft:polished_tuff_slab' => true,
            'minecraft:poplar_slab' => true, 'minecraft:prismarine_brick_slab' => true,
            'minecraft:prismarine_slab' => true, 'minecraft:purple_concrete_slab' => true,
            'minecraft:purple_wool_slab' => true, 'minecraft:purpur_slab' => true,
            'minecraft:quartz_slab' => true, 'minecraft:red_concrete_slab' => true,
            'minecraft:red_nether_brick_slab' => true, 'minecraft:red_sandstone_slab' => true,
            'minecraft:red_wool_slab' => true, 'minecraft:resin_brick_slab' => true,
            'minecraft:sandstone_slab' => true, 'minecraft:smooth_quartz_slab' => true,
            'minecraft:smooth_red_sandstone_slab' => true, 'minecraft:smooth_sandstone_slab' => true,
            'minecraft:smooth_stone_slab' => true, 'minecraft:spruce_slab' => true,
            'minecraft:stone_brick_slab' => true, 'minecraft:sulfur_brick_slab' => true,
            'minecraft:sulfur_slab' => true, 'minecraft:tuff_brick_slab' => true,
            'minecraft:tuff_slab' => true, 'minecraft:warped_slab' => true,
            'minecraft:waxed_cut_copper_slab' => true, 'minecraft:waxed_exposed_cut_copper_slab' => true,
            'minecraft:waxed_oxidized_cut_copper_slab' => true, 'minecraft:waxed_weathered_cut_copper_slab' => true,
            'minecraft:weathered_cut_copper_slab' => true, 'minecraft:white_concrete_slab' => true,
            'minecraft:white_wool_slab' => true, 'minecraft:yellow_concrete_slab' => true,
            'minecraft:yellow_wool_slab' => true,
        ],
        'wall' => [
            'minecraft:andesite_wall' => true, 'minecraft:blackstone_wall' => true,
            'minecraft:brick_wall' => true, 'minecraft:cinnabar_brick_wall' => true,
            'minecraft:cinnabar_wall' => true, 'minecraft:cobbled_deepslate_wall' => true,
            'minecraft:cobblestone_wall' => true, 'minecraft:deepslate_brick_wall' => true,
            'minecraft:deepslate_tile_wall' => true, 'minecraft:diorite_wall' => true,
            'minecraft:end_stone_brick_wall' => true, 'minecraft:granite_wall' => true,
            'minecraft:mossy_cobblestone_wall' => true, 'minecraft:mossy_stone_brick_wall' => true,
            'minecraft:mud_brick_wall' => true, 'minecraft:nether_brick_wall' => true,
            'minecraft:polished_blackstone_brick_wall' => true, 'minecraft:polished_blackstone_wall' => true,
            'minecraft:polished_cinnabar_wall' => true, 'minecraft:polished_deepslate_wall' => true,
            'minecraft:polished_sulfur_wall' => true, 'minecraft:polished_tuff_wall' => true,
            'minecraft:prismarine_wall' => true, 'minecraft:red_nether_brick_wall' => true,
            'minecraft:red_sandstone_wall' => true, 'minecraft:resin_brick_wall' => true,
            'minecraft:sandstone_wall' => true, 'minecraft:stone_brick_wall' => true,
            'minecraft:sulfur_brick_wall' => true, 'minecraft:sulfur_wall' => true,
            'minecraft:tuff_brick_wall' => true, 'minecraft:tuff_wall' => true,
        ],
        'log' => [
            'minecraft:acacia_log' => true, 'minecraft:birch_log' => true,
            'minecraft:cherry_log' => true, 'minecraft:crimson_stem' => true,
            'minecraft:dark_oak_log' => true, 'minecraft:jungle_log' => true,
            'minecraft:mangrove_log' => true, 'minecraft:melon_stem' => true,
            'minecraft:mushroom_stem' => true, 'minecraft:oak_log' => true,
            'minecraft:pale_oak_log' => true, 'minecraft:poplar_log' => true,
            'minecraft:pumpkin_stem' => true, 'minecraft:spruce_log' => true,
            'minecraft:stripped_acacia_log' => true, 'minecraft:stripped_birch_log' => true,
            'minecraft:stripped_cherry_log' => true, 'minecraft:stripped_crimson_stem' => true,
            'minecraft:stripped_dark_oak_log' => true, 'minecraft:stripped_jungle_log' => true,
            'minecraft:stripped_mangrove_log' => true, 'minecraft:stripped_oak_log' => true,
            'minecraft:stripped_pale_oak_log' => true, 'minecraft:stripped_poplar_log' => true,
            'minecraft:stripped_spruce_log' => true, 'minecraft:stripped_warped_stem' => true,
            'minecraft:warped_stem' => true,
        ],
        'wood' => [
            'minecraft:acacia_wood' => true, 'minecraft:birch_wood' => true,
            'minecraft:cherry_wood' => true, 'minecraft:crimson_hyphae' => true,
            'minecraft:dark_oak_wood' => true, 'minecraft:jungle_wood' => true,
            'minecraft:mangrove_wood' => true, 'minecraft:oak_wood' => true,
            'minecraft:pale_oak_wood' => true, 'minecraft:poplar_wood' => true,
            'minecraft:spruce_wood' => true, 'minecraft:stripped_acacia_wood' => true,
            'minecraft:stripped_birch_wood' => true, 'minecraft:stripped_cherry_wood' => true,
            'minecraft:stripped_crimson_hyphae' => true, 'minecraft:stripped_dark_oak_wood' => true,
            'minecraft:stripped_jungle_wood' => true, 'minecraft:stripped_mangrove_wood' => true,
            'minecraft:stripped_oak_wood' => true, 'minecraft:stripped_pale_oak_wood' => true,
            'minecraft:stripped_poplar_wood' => true, 'minecraft:stripped_spruce_wood' => true,
            'minecraft:stripped_warped_hyphae' => true, 'minecraft:warped_hyphae' => true,
        ],
        'leaves' => [
            'minecraft:acacia_leaves' => true, 'minecraft:azalea_leaves' => true,
            'minecraft:birch_leaves' => true, 'minecraft:cherry_leaves' => true,
            'minecraft:dark_oak_leaves' => true, 'minecraft:jungle_leaves' => true,
            'minecraft:mangrove_leaves' => true, 'minecraft:oak_leaves' => true,
            'minecraft:orange_poplar_leaves' => true, 'minecraft:pale_oak_leaves' => true,
            'minecraft:red_poplar_leaves' => true, 'minecraft:spruce_leaves' => true,
            'minecraft:yellow_poplar_leaves' => true,
        ],
        'ore' => [
            'minecraft:coal_ore' => true, 'minecraft:copper_ore' => true,
            'minecraft:deepslate_coal_ore' => true, 'minecraft:deepslate_copper_ore' => true,
            'minecraft:deepslate_diamond_ore' => true, 'minecraft:deepslate_emerald_ore' => true,
            'minecraft:deepslate_gold_ore' => true, 'minecraft:deepslate_iron_ore' => true,
            'minecraft:deepslate_lapis_ore' => true, 'minecraft:deepslate_redstone_ore' => true,
            'minecraft:diamond_ore' => true, 'minecraft:emerald_ore' => true,
            'minecraft:gold_ore' => true, 'minecraft:iron_ore' => true,
            'minecraft:lapis_ore' => true, 'minecraft:lit_deepslate_redstone_ore' => true,
            'minecraft:lit_redstone_ore' => true, 'minecraft:nether_gold_ore' => true,
            'minecraft:quartz_ore' => true, 'minecraft:redstone_ore' => true,
        ],
        'wool' => [
            'minecraft:black_wool' => true, 'minecraft:blue_wool' => true,
            'minecraft:brown_wool' => true, 'minecraft:cyan_wool' => true,
            'minecraft:gray_wool' => true, 'minecraft:green_wool' => true,
            'minecraft:light_blue_wool' => true, 'minecraft:light_gray_wool' => true,
            'minecraft:lime_wool' => true, 'minecraft:magenta_wool' => true,
            'minecraft:orange_wool' => true, 'minecraft:pink_wool' => true,
            'minecraft:purple_wool' => true, 'minecraft:red_wool' => true,
            'minecraft:white_wool' => true, 'minecraft:yellow_wool' => true,
        ],
        'carpet' => [
            'minecraft:black_carpet' => true, 'minecraft:blue_carpet' => true,
            'minecraft:brown_carpet' => true, 'minecraft:cyan_carpet' => true,
            'minecraft:gray_carpet' => true, 'minecraft:green_carpet' => true,
            'minecraft:light_blue_carpet' => true, 'minecraft:light_gray_carpet' => true,
            'minecraft:lime_carpet' => true, 'minecraft:magenta_carpet' => true,
            'minecraft:moss_carpet' => true, 'minecraft:orange_carpet' => true,
            'minecraft:pale_moss_carpet' => true, 'minecraft:pink_carpet' => true,
            'minecraft:purple_carpet' => true, 'minecraft:red_carpet' => true,
            'minecraft:white_carpet' => true, 'minecraft:yellow_carpet' => true,
        ],
        'sand' => [
            'minecraft:red_sand' => true, 'minecraft:sand' => true,
            'minecraft:soul_sand' => true, 'minecraft:suspicious_sand' => true,
        ],
        'farmland' => [
            'minecraft:farmland' => true,
        ],
        'crop' => [
            'minecraft:beetroot' => true, 'minecraft:carrots' => true,
            'minecraft:pitcher_crop' => true, 'minecraft:potatoes' => true,
            'minecraft:torchflower_crop' => true, 'minecraft:wheat' => true,
        ],
        'replaceable' => [
            'minecraft:air' => true, 'minecraft:fire' => true,
            'minecraft:portal' => true, 'minecraft:soul_fire' => true,
        ],
    ];

    /** @var array<string, array<string, true>> */
    /** @var array<string, array<string, true>> */
    private const array MEMBERS = [
        'button' => [
            'minecraft:acacia_button' => true, 'minecraft:bamboo_button' => true,
            'minecraft:birch_button' => true, 'minecraft:cherry_button' => true,
            'minecraft:crimson_button' => true, 'minecraft:dark_oak_button' => true,
            'minecraft:jungle_button' => true, 'minecraft:mangrove_button' => true,
            'minecraft:pale_oak_button' => true, 'minecraft:polished_blackstone_button' => true,
            'minecraft:poplar_button' => true, 'minecraft:spruce_button' => true,
            'minecraft:stone_button' => true, 'minecraft:warped_button' => true,
            'minecraft:wooden_button' => true,
        ],
        'door' => [
            'minecraft:acacia_door' => true, 'minecraft:bamboo_door' => true,
            'minecraft:birch_door' => true, 'minecraft:cherry_door' => true,
            'minecraft:copper_door' => true, 'minecraft:crimson_door' => true,
            'minecraft:dark_oak_door' => true, 'minecraft:exposed_copper_door' => true,
            'minecraft:iron_door' => true, 'minecraft:jungle_door' => true,
            'minecraft:mangrove_door' => true, 'minecraft:oxidized_copper_door' => true,
            'minecraft:pale_oak_door' => true, 'minecraft:poplar_door' => true,
            'minecraft:spruce_door' => true, 'minecraft:warped_door' => true,
            'minecraft:waxed_copper_door' => true, 'minecraft:waxed_exposed_copper_door' => true,
            'minecraft:waxed_oxidized_copper_door' => true, 'minecraft:waxed_weathered_copper_door' => true,
            'minecraft:weathered_copper_door' => true, 'minecraft:wooden_door' => true,
        ],
        'fence' => [
            'minecraft:acacia_fence' => true, 'minecraft:bamboo_fence' => true,
            'minecraft:birch_fence' => true, 'minecraft:cherry_fence' => true,
            'minecraft:crimson_fence' => true, 'minecraft:dark_oak_fence' => true,
            'minecraft:jungle_fence' => true, 'minecraft:mangrove_fence' => true,
            'minecraft:nether_brick_fence' => true, 'minecraft:oak_fence' => true,
            'minecraft:pale_oak_fence' => true, 'minecraft:poplar_fence' => true,
            'minecraft:spruce_fence' => true, 'minecraft:warped_fence' => true,
        ],
        'fence_gate' => [
            'minecraft:acacia_fence_gate' => true, 'minecraft:bamboo_fence_gate' => true,
            'minecraft:birch_fence_gate' => true, 'minecraft:cherry_fence_gate' => true,
            'minecraft:crimson_fence_gate' => true, 'minecraft:dark_oak_fence_gate' => true,
            'minecraft:jungle_fence_gate' => true, 'minecraft:mangrove_fence_gate' => true,
            'minecraft:pale_oak_fence_gate' => true, 'minecraft:poplar_fence_gate' => true,
            'minecraft:spruce_fence_gate' => true, 'minecraft:warped_fence_gate' => true,
        ],
        'gravity_affected' => [
            'minecraft:anvil' => true, 'minecraft:black_concrete_powder' => true,
            'minecraft:blue_concrete_powder' => true, 'minecraft:brown_concrete_powder' => true,
            'minecraft:chipped_anvil' => true, 'minecraft:cyan_concrete_powder' => true,
            'minecraft:damaged_anvil' => true, 'minecraft:dragon_egg' => true,
            'minecraft:gray_concrete_powder' => true, 'minecraft:gravel' => true,
            'minecraft:green_concrete_powder' => true, 'minecraft:light_blue_concrete_powder' => true,
            'minecraft:light_gray_concrete_powder' => true, 'minecraft:lime_concrete_powder' => true,
            'minecraft:magenta_concrete_powder' => true, 'minecraft:orange_concrete_powder' => true,
            'minecraft:pink_concrete_powder' => true, 'minecraft:purple_concrete_powder' => true,
            'minecraft:red_concrete_powder' => true, 'minecraft:red_sand' => true,
            'minecraft:sand' => true, 'minecraft:suspicious_gravel' => true,
            'minecraft:suspicious_sand' => true, 'minecraft:white_concrete_powder' => true,
            'minecraft:yellow_concrete_powder' => true,
        ],
        'hanging_sign' => [
            'minecraft:acacia_hanging_sign' => true, 'minecraft:bamboo_hanging_sign' => true,
            'minecraft:birch_hanging_sign' => true, 'minecraft:cherry_hanging_sign' => true,
            'minecraft:crimson_hanging_sign' => true, 'minecraft:dark_oak_hanging_sign' => true,
            'minecraft:jungle_hanging_sign' => true, 'minecraft:mangrove_hanging_sign' => true,
            'minecraft:oak_hanging_sign' => true, 'minecraft:pale_oak_hanging_sign' => true,
            'minecraft:poplar_hanging_sign' => true, 'minecraft:spruce_hanging_sign' => true,
            'minecraft:warped_hanging_sign' => true,
        ],
        'liquid' => [
            'minecraft:flowing_lava' => true, 'minecraft:flowing_water' => true,
            'minecraft:lava' => true, 'minecraft:water' => true,
        ],
        'pressure_plate' => [
            'minecraft:acacia_pressure_plate' => true, 'minecraft:bamboo_pressure_plate' => true,
            'minecraft:birch_pressure_plate' => true, 'minecraft:cherry_pressure_plate' => true,
            'minecraft:crimson_pressure_plate' => true, 'minecraft:dark_oak_pressure_plate' => true,
            'minecraft:heavy_weighted_pressure_plate' => true, 'minecraft:jungle_pressure_plate' => true,
            'minecraft:light_weighted_pressure_plate' => true, 'minecraft:mangrove_pressure_plate' => true,
            'minecraft:pale_oak_pressure_plate' => true, 'minecraft:polished_blackstone_pressure_plate' => true,
            'minecraft:poplar_pressure_plate' => true, 'minecraft:spruce_pressure_plate' => true,
            'minecraft:stone_pressure_plate' => true, 'minecraft:warped_pressure_plate' => true,
            'minecraft:wooden_pressure_plate' => true,
        ],
        'sign' => [
            'minecraft:acacia_hanging_sign' => true, 'minecraft:acacia_standing_sign' => true,
            'minecraft:acacia_wall_sign' => true, 'minecraft:bamboo_hanging_sign' => true,
            'minecraft:bamboo_standing_sign' => true, 'minecraft:bamboo_wall_sign' => true,
            'minecraft:birch_hanging_sign' => true, 'minecraft:birch_standing_sign' => true,
            'minecraft:birch_wall_sign' => true, 'minecraft:cherry_hanging_sign' => true,
            'minecraft:cherry_standing_sign' => true, 'minecraft:cherry_wall_sign' => true,
            'minecraft:crimson_hanging_sign' => true, 'minecraft:crimson_standing_sign' => true,
            'minecraft:crimson_wall_sign' => true, 'minecraft:dark_oak_hanging_sign' => true,
            'minecraft:darkoak_standing_sign' => true, 'minecraft:darkoak_wall_sign' => true,
            'minecraft:jungle_hanging_sign' => true, 'minecraft:jungle_standing_sign' => true,
            'minecraft:jungle_wall_sign' => true, 'minecraft:mangrove_hanging_sign' => true,
            'minecraft:mangrove_standing_sign' => true, 'minecraft:mangrove_wall_sign' => true,
            'minecraft:oak_hanging_sign' => true, 'minecraft:pale_oak_hanging_sign' => true,
            'minecraft:pale_oak_standing_sign' => true, 'minecraft:pale_oak_wall_sign' => true,
            'minecraft:poplar_hanging_sign' => true, 'minecraft:poplar_standing_sign' => true,
            'minecraft:poplar_wall_sign' => true, 'minecraft:spruce_hanging_sign' => true,
            'minecraft:spruce_standing_sign' => true, 'minecraft:spruce_wall_sign' => true,
            'minecraft:standing_sign' => true, 'minecraft:wall_sign' => true,
            'minecraft:warped_hanging_sign' => true, 'minecraft:warped_standing_sign' => true,
            'minecraft:warped_wall_sign' => true,
        ],
        'trapdoor' => [
            'minecraft:trapdoor' => true,
            'minecraft:acacia_trapdoor' => true, 'minecraft:bamboo_trapdoor' => true,
            'minecraft:birch_trapdoor' => true, 'minecraft:cherry_trapdoor' => true,
            'minecraft:copper_trapdoor' => true, 'minecraft:crimson_trapdoor' => true,
            'minecraft:dark_oak_trapdoor' => true, 'minecraft:exposed_copper_trapdoor' => true,
            'minecraft:iron_trapdoor' => true, 'minecraft:jungle_trapdoor' => true,
            'minecraft:mangrove_trapdoor' => true, 'minecraft:oxidized_copper_trapdoor' => true,
            'minecraft:pale_oak_trapdoor' => true, 'minecraft:poplar_trapdoor' => true,
            'minecraft:spruce_trapdoor' => true, 'minecraft:warped_trapdoor' => true,
            'minecraft:waxed_copper_trapdoor' => true, 'minecraft:waxed_exposed_copper_trapdoor' => true,
            'minecraft:waxed_oxidized_copper_trapdoor' => true,
            'minecraft:waxed_weathered_copper_trapdoor' => true,
            'minecraft:weathered_copper_trapdoor' => true,
        ],
    ];

    private function __construct() {}

    public static function contains(BlockTag $tag, string $identifier): bool
    {
        self::assertIdentifier($identifier);

        return isset(self::MEMBERS[$tag->value][$identifier])
            || isset(self::EXTENDED_MEMBERS[$tag->value][$identifier]);
    }

    /** @return list<BlockTag> */
    public static function tagsFor(string $identifier): array
    {
        self::assertIdentifier($identifier);
        $tags = [];
        foreach (BlockTag::cases() as $tag) {
            if (isset(self::MEMBERS[$tag->value][$identifier])
                || isset(self::EXTENDED_MEMBERS[$tag->value][$identifier])) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** @return list<string> */
    public static function members(BlockTag $tag): array
    {
        $members = self::MEMBERS[$tag->value] ?? self::EXTENDED_MEMBERS[$tag->value] ?? null;
        if ($members === null) {
            throw new LogicException('Block tag does not have an authoritative membership definition.');
        }

        return array_keys($members);
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Block identifier must be canonical and namespaced.');
        }
    }
}
