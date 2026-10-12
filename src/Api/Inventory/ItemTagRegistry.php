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

namespace Bedriox\Api\Inventory;

use InvalidArgumentException;
use LogicException;

/** Immutable built-in semantic item membership resolved without runtime naming heuristics. */
final class ItemTagRegistry
{
    /** @var array<string, array<string, true>> */
    private const array EXTENDED_MEMBERS = [
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
        'sign' => [
            'minecraft:acacia_hanging_sign' => true, 'minecraft:acacia_sign' => true,
            'minecraft:acacia_standing_sign' => true, 'minecraft:acacia_wall_sign' => true,
            'minecraft:bamboo_hanging_sign' => true, 'minecraft:bamboo_sign' => true,
            'minecraft:bamboo_standing_sign' => true, 'minecraft:bamboo_wall_sign' => true,
            'minecraft:birch_hanging_sign' => true, 'minecraft:birch_sign' => true,
            'minecraft:birch_standing_sign' => true, 'minecraft:birch_wall_sign' => true,
            'minecraft:cherry_hanging_sign' => true, 'minecraft:cherry_sign' => true,
            'minecraft:cherry_standing_sign' => true, 'minecraft:cherry_wall_sign' => true,
            'minecraft:crimson_hanging_sign' => true, 'minecraft:crimson_sign' => true,
            'minecraft:crimson_standing_sign' => true, 'minecraft:crimson_wall_sign' => true,
            'minecraft:dark_oak_hanging_sign' => true, 'minecraft:dark_oak_sign' => true,
            'minecraft:darkoak_standing_sign' => true, 'minecraft:darkoak_wall_sign' => true,
            'minecraft:jungle_hanging_sign' => true, 'minecraft:jungle_sign' => true,
            'minecraft:jungle_standing_sign' => true, 'minecraft:jungle_wall_sign' => true,
            'minecraft:mangrove_hanging_sign' => true, 'minecraft:mangrove_sign' => true,
            'minecraft:mangrove_standing_sign' => true, 'minecraft:mangrove_wall_sign' => true,
            'minecraft:oak_hanging_sign' => true, 'minecraft:oak_sign' => true,
            'minecraft:pale_oak_hanging_sign' => true, 'minecraft:pale_oak_sign' => true,
            'minecraft:pale_oak_standing_sign' => true, 'minecraft:pale_oak_wall_sign' => true,
            'minecraft:poplar_hanging_sign' => true, 'minecraft:poplar_sign' => true,
            'minecraft:poplar_standing_sign' => true, 'minecraft:poplar_wall_sign' => true,
            'minecraft:spruce_hanging_sign' => true, 'minecraft:spruce_sign' => true,
            'minecraft:spruce_standing_sign' => true, 'minecraft:spruce_wall_sign' => true,
            'minecraft:standing_sign' => true, 'minecraft:wall_sign' => true,
            'minecraft:warped_hanging_sign' => true, 'minecraft:warped_sign' => true,
            'minecraft:warped_standing_sign' => true, 'minecraft:warped_wall_sign' => true,
        ],
        'projectile' => [
            'minecraft:arrow' => true, 'minecraft:ender_pearl' => true,
            'minecraft:fishing_rod' => true, 'minecraft:lingering_potion' => true,
            'minecraft:splash_potion' => true, 'minecraft:trident' => true,
        ],
        'fuel' => [
            'minecraft:acacia_log' => true, 'minecraft:acacia_planks' => true,
            'minecraft:acacia_wood' => true, 'minecraft:bamboo' => true,
            'minecraft:bamboo_planks' => true, 'minecraft:birch_log' => true,
            'minecraft:birch_planks' => true, 'minecraft:birch_wood' => true,
            'minecraft:blaze_rod' => true, 'minecraft:charcoal' => true,
            'minecraft:cherry_log' => true, 'minecraft:cherry_planks' => true,
            'minecraft:cherry_wood' => true, 'minecraft:coal' => true,
            'minecraft:coal_block' => true, 'minecraft:crimson_hyphae' => true,
            'minecraft:crimson_planks' => true, 'minecraft:crimson_stem' => true,
            'minecraft:dark_oak_log' => true, 'minecraft:dark_oak_planks' => true,
            'minecraft:dark_oak_wood' => true, 'minecraft:dried_kelp_block' => true,
            'minecraft:jungle_log' => true, 'minecraft:jungle_planks' => true,
            'minecraft:jungle_wood' => true, 'minecraft:lava_bucket' => true,
            'minecraft:mangrove_log' => true, 'minecraft:mangrove_planks' => true,
            'minecraft:mangrove_wood' => true, 'minecraft:melon_stem' => true,
            'minecraft:mushroom_stem' => true, 'minecraft:oak_log' => true,
            'minecraft:oak_planks' => true, 'minecraft:oak_wood' => true,
            'minecraft:pale_oak_log' => true, 'minecraft:pale_oak_planks' => true,
            'minecraft:pale_oak_wood' => true, 'minecraft:poplar_log' => true,
            'minecraft:poplar_planks' => true, 'minecraft:poplar_wood' => true,
            'minecraft:pumpkin_stem' => true, 'minecraft:spruce_log' => true,
            'minecraft:spruce_planks' => true, 'minecraft:spruce_wood' => true,
            'minecraft:stick' => true, 'minecraft:stripped_acacia_log' => true,
            'minecraft:stripped_acacia_wood' => true, 'minecraft:stripped_birch_log' => true,
            'minecraft:stripped_birch_wood' => true, 'minecraft:stripped_cherry_log' => true,
            'minecraft:stripped_cherry_wood' => true, 'minecraft:stripped_crimson_hyphae' => true,
            'minecraft:stripped_crimson_stem' => true, 'minecraft:stripped_dark_oak_log' => true,
            'minecraft:stripped_dark_oak_wood' => true, 'minecraft:stripped_jungle_log' => true,
            'minecraft:stripped_jungle_wood' => true, 'minecraft:stripped_mangrove_log' => true,
            'minecraft:stripped_mangrove_wood' => true, 'minecraft:stripped_oak_log' => true,
            'minecraft:stripped_oak_wood' => true, 'minecraft:stripped_pale_oak_log' => true,
            'minecraft:stripped_pale_oak_wood' => true, 'minecraft:stripped_poplar_log' => true,
            'minecraft:stripped_poplar_wood' => true, 'minecraft:stripped_spruce_log' => true,
            'minecraft:stripped_spruce_wood' => true, 'minecraft:stripped_warped_hyphae' => true,
            'minecraft:stripped_warped_stem' => true, 'minecraft:warped_hyphae' => true,
            'minecraft:warped_planks' => true, 'minecraft:warped_stem' => true,
        ],
        'consumable' => [
            'minecraft:apple' => true, 'minecraft:baked_potato' => true,
            'minecraft:beef' => true, 'minecraft:beetroot' => true,
            'minecraft:beetroot_soup' => true, 'minecraft:bread' => true,
            'minecraft:carrot' => true, 'minecraft:chicken' => true,
            'minecraft:cod' => true, 'minecraft:cooked_beef' => true,
            'minecraft:cooked_chicken' => true, 'minecraft:cooked_cod' => true,
            'minecraft:cooked_mutton' => true, 'minecraft:cooked_porkchop' => true,
            'minecraft:cooked_rabbit' => true, 'minecraft:cooked_salmon' => true,
            'minecraft:cookie' => true, 'minecraft:dried_kelp' => true,
            'minecraft:enchanted_golden_apple' => true, 'minecraft:glow_berries' => true,
            'minecraft:golden_apple' => true, 'minecraft:golden_carrot' => true,
            'minecraft:honey_bottle' => true, 'minecraft:melon_slice' => true,
            'minecraft:milk_bucket' => true, 'minecraft:mushroom_stew' => true,
            'minecraft:mutton' => true, 'minecraft:porkchop' => true,
            'minecraft:potato' => true, 'minecraft:potion' => true,
            'minecraft:pumpkin_pie' => true, 'minecraft:rabbit' => true,
            'minecraft:rabbit_stew' => true, 'minecraft:salmon' => true,
            'minecraft:suspicious_stew' => true, 'minecraft:sweet_berries' => true,
            'minecraft:tropical_fish' => true,
        ],
    ];

    /** @var array<string, array<string, true>> */
    /** @var array<string, array<string, true>> */
    private const array MEMBERS = [
        'armor' => [
            'minecraft:chainmail_boots' => true, 'minecraft:chainmail_chestplate' => true,
            'minecraft:chainmail_helmet' => true, 'minecraft:chainmail_leggings' => true,
            'minecraft:copper_boots' => true, 'minecraft:copper_chestplate' => true,
            'minecraft:copper_helmet' => true, 'minecraft:copper_leggings' => true,
            'minecraft:diamond_boots' => true, 'minecraft:diamond_chestplate' => true,
            'minecraft:diamond_helmet' => true, 'minecraft:diamond_leggings' => true,
            'minecraft:golden_boots' => true, 'minecraft:golden_chestplate' => true,
            'minecraft:golden_helmet' => true, 'minecraft:golden_leggings' => true,
            'minecraft:iron_boots' => true, 'minecraft:iron_chestplate' => true,
            'minecraft:iron_helmet' => true, 'minecraft:iron_leggings' => true,
            'minecraft:leather_boots' => true, 'minecraft:leather_chestplate' => true,
            'minecraft:leather_helmet' => true, 'minecraft:leather_leggings' => true,
            'minecraft:netherite_boots' => true, 'minecraft:netherite_chestplate' => true,
            'minecraft:netherite_helmet' => true, 'minecraft:netherite_leggings' => true,
            'minecraft:turtle_helmet' => true,
        ],
        'arrow' => ['minecraft:arrow' => true],
        'axe' => [
            'minecraft:copper_axe' => true, 'minecraft:diamond_axe' => true,
            'minecraft:golden_axe' => true, 'minecraft:iron_axe' => true,
            'minecraft:netherite_axe' => true, 'minecraft:stone_axe' => true,
            'minecraft:wooden_axe' => true,
        ],
        'boat' => [
            'minecraft:acacia_boat' => true, 'minecraft:acacia_chest_boat' => true,
            'minecraft:birch_boat' => true, 'minecraft:birch_chest_boat' => true,
            'minecraft:cherry_boat' => true, 'minecraft:cherry_chest_boat' => true,
            'minecraft:chest_boat' => true, 'minecraft:dark_oak_boat' => true,
            'minecraft:dark_oak_chest_boat' => true, 'minecraft:jungle_boat' => true,
            'minecraft:jungle_chest_boat' => true, 'minecraft:mangrove_boat' => true,
            'minecraft:mangrove_chest_boat' => true, 'minecraft:oak_boat' => true,
            'minecraft:oak_chest_boat' => true, 'minecraft:pale_oak_boat' => true,
            'minecraft:pale_oak_chest_boat' => true, 'minecraft:poplar_boat' => true,
            'minecraft:poplar_chest_boat' => true, 'minecraft:spruce_boat' => true,
            'minecraft:spruce_chest_boat' => true,
        ],
        'boots' => [
            'minecraft:chainmail_boots' => true, 'minecraft:copper_boots' => true,
            'minecraft:diamond_boots' => true, 'minecraft:golden_boots' => true,
            'minecraft:iron_boots' => true, 'minecraft:leather_boots' => true,
            'minecraft:netherite_boots' => true,
        ],
        'bow' => ['minecraft:bow' => true],
        'chestplate' => [
            'minecraft:chainmail_chestplate' => true, 'minecraft:copper_chestplate' => true,
            'minecraft:diamond_chestplate' => true, 'minecraft:golden_chestplate' => true,
            'minecraft:iron_chestplate' => true, 'minecraft:leather_chestplate' => true,
            'minecraft:netherite_chestplate' => true,
        ],
        'crossbow' => ['minecraft:crossbow' => true],
        'food' => [
            'minecraft:apple' => true, 'minecraft:baked_potato' => true,
            'minecraft:beef' => true, 'minecraft:beetroot' => true,
            'minecraft:beetroot_soup' => true, 'minecraft:bread' => true,
            'minecraft:carrot' => true, 'minecraft:chicken' => true,
            'minecraft:cod' => true, 'minecraft:cooked_beef' => true,
            'minecraft:cooked_chicken' => true, 'minecraft:cooked_cod' => true,
            'minecraft:cooked_mutton' => true, 'minecraft:cooked_porkchop' => true,
            'minecraft:cooked_rabbit' => true, 'minecraft:cooked_salmon' => true,
            'minecraft:cookie' => true, 'minecraft:dried_kelp' => true,
            'minecraft:enchanted_golden_apple' => true, 'minecraft:glow_berries' => true,
            'minecraft:golden_apple' => true, 'minecraft:golden_carrot' => true,
            'minecraft:honey_bottle' => true, 'minecraft:melon_slice' => true,
            'minecraft:mushroom_stew' => true, 'minecraft:mutton' => true,
            'minecraft:porkchop' => true, 'minecraft:potato' => true,
            'minecraft:pumpkin_pie' => true, 'minecraft:rabbit' => true,
            'minecraft:rabbit_stew' => true, 'minecraft:salmon' => true,
            'minecraft:suspicious_stew' => true, 'minecraft:sweet_berries' => true,
            'minecraft:tropical_fish' => true,
        ],
        'helmet' => [
            'minecraft:chainmail_helmet' => true, 'minecraft:copper_helmet' => true,
            'minecraft:diamond_helmet' => true, 'minecraft:golden_helmet' => true,
            'minecraft:iron_helmet' => true, 'minecraft:leather_helmet' => true,
            'minecraft:netherite_helmet' => true, 'minecraft:turtle_helmet' => true,
        ],
        'hoe' => [
            'minecraft:copper_hoe' => true, 'minecraft:diamond_hoe' => true,
            'minecraft:golden_hoe' => true, 'minecraft:iron_hoe' => true,
            'minecraft:netherite_hoe' => true, 'minecraft:stone_hoe' => true,
            'minecraft:wooden_hoe' => true,
        ],
        'leggings' => [
            'minecraft:chainmail_leggings' => true, 'minecraft:copper_leggings' => true,
            'minecraft:diamond_leggings' => true, 'minecraft:golden_leggings' => true,
            'minecraft:iron_leggings' => true, 'minecraft:leather_leggings' => true,
            'minecraft:netherite_leggings' => true,
        ],
        'pickaxe' => [
            'minecraft:copper_pickaxe' => true, 'minecraft:diamond_pickaxe' => true,
            'minecraft:golden_pickaxe' => true, 'minecraft:iron_pickaxe' => true,
            'minecraft:netherite_pickaxe' => true, 'minecraft:stone_pickaxe' => true,
            'minecraft:wooden_pickaxe' => true,
        ],
        'potion' => [
            'minecraft:lingering_potion' => true, 'minecraft:potion' => true,
            'minecraft:splash_potion' => true,
        ],
        'shovel' => [
            'minecraft:copper_shovel' => true, 'minecraft:diamond_shovel' => true,
            'minecraft:golden_shovel' => true, 'minecraft:iron_shovel' => true,
            'minecraft:netherite_shovel' => true, 'minecraft:stone_shovel' => true,
            'minecraft:wooden_shovel' => true,
        ],
        'spawn_egg' => [
            'minecraft:agent_spawn_egg' => true, 'minecraft:allay_spawn_egg' => true,
            'minecraft:armadillo_spawn_egg' => true, 'minecraft:axolotl_spawn_egg' => true,
            'minecraft:bat_spawn_egg' => true, 'minecraft:bee_spawn_egg' => true,
            'minecraft:blaze_spawn_egg' => true, 'minecraft:bogged_spawn_egg' => true,
            'minecraft:breeze_spawn_egg' => true, 'minecraft:camel_husk_spawn_egg' => true,
            'minecraft:camel_spawn_egg' => true, 'minecraft:cat_spawn_egg' => true,
            'minecraft:cave_spider_spawn_egg' => true, 'minecraft:chicken_spawn_egg' => true,
            'minecraft:cod_spawn_egg' => true, 'minecraft:copper_golem_spawn_egg' => true,
            'minecraft:cow_spawn_egg' => true, 'minecraft:creaking_spawn_egg' => true,
            'minecraft:creeper_spawn_egg' => true, 'minecraft:dolphin_spawn_egg' => true,
            'minecraft:donkey_spawn_egg' => true, 'minecraft:drowned_spawn_egg' => true,
            'minecraft:elder_guardian_spawn_egg' => true, 'minecraft:ender_dragon_spawn_egg' => true,
            'minecraft:enderman_spawn_egg' => true, 'minecraft:endermite_spawn_egg' => true,
            'minecraft:evoker_spawn_egg' => true, 'minecraft:fox_spawn_egg' => true,
            'minecraft:frog_spawn_egg' => true, 'minecraft:ghast_spawn_egg' => true,
            'minecraft:glow_squid_spawn_egg' => true, 'minecraft:goat_spawn_egg' => true,
            'minecraft:guardian_spawn_egg' => true, 'minecraft:happy_ghast_spawn_egg' => true,
            'minecraft:hoglin_spawn_egg' => true, 'minecraft:horse_spawn_egg' => true,
            'minecraft:husk_spawn_egg' => true, 'minecraft:iron_golem_spawn_egg' => true,
            'minecraft:llama_spawn_egg' => true, 'minecraft:magma_cube_spawn_egg' => true,
            'minecraft:mooshroom_spawn_egg' => true, 'minecraft:mule_spawn_egg' => true,
            'minecraft:nautilus_spawn_egg' => true, 'minecraft:npc_spawn_egg' => true,
            'minecraft:ocelot_spawn_egg' => true, 'minecraft:panda_spawn_egg' => true,
            'minecraft:parched_spawn_egg' => true, 'minecraft:parrot_spawn_egg' => true,
            'minecraft:phantom_spawn_egg' => true, 'minecraft:pig_spawn_egg' => true,
            'minecraft:piglin_brute_spawn_egg' => true, 'minecraft:piglin_spawn_egg' => true,
            'minecraft:pillager_spawn_egg' => true, 'minecraft:polar_bear_spawn_egg' => true,
            'minecraft:pufferfish_spawn_egg' => true, 'minecraft:rabbit_spawn_egg' => true,
            'minecraft:ravager_spawn_egg' => true, 'minecraft:salmon_spawn_egg' => true,
            'minecraft:sheep_spawn_egg' => true, 'minecraft:shulker_spawn_egg' => true,
            'minecraft:silverfish_spawn_egg' => true, 'minecraft:skeleton_horse_spawn_egg' => true,
            'minecraft:skeleton_spawn_egg' => true, 'minecraft:slime_spawn_egg' => true,
            'minecraft:sniffer_spawn_egg' => true, 'minecraft:snow_golem_spawn_egg' => true,
            'minecraft:spider_spawn_egg' => true, 'minecraft:squid_spawn_egg' => true,
            'minecraft:stray_spawn_egg' => true, 'minecraft:strider_spawn_egg' => true,
            'minecraft:sulfur_cube_spawn_egg' => true, 'minecraft:tadpole_spawn_egg' => true,
            'minecraft:trader_llama_spawn_egg' => true, 'minecraft:tropical_fish_spawn_egg' => true,
            'minecraft:turtle_spawn_egg' => true, 'minecraft:vex_spawn_egg' => true,
            'minecraft:villager_spawn_egg' => true, 'minecraft:vindicator_spawn_egg' => true,
            'minecraft:wandering_trader_spawn_egg' => true, 'minecraft:warden_spawn_egg' => true,
            'minecraft:witch_spawn_egg' => true, 'minecraft:wither_skeleton_spawn_egg' => true,
            'minecraft:wither_spawn_egg' => true, 'minecraft:wolf_spawn_egg' => true,
            'minecraft:zoglin_spawn_egg' => true, 'minecraft:zombie_horse_spawn_egg' => true,
            'minecraft:zombie_nautilus_spawn_egg' => true, 'minecraft:zombie_pigman_spawn_egg' => true,
            'minecraft:zombie_spawn_egg' => true, 'minecraft:zombie_villager_spawn_egg' => true,
        ],
        'sword' => [
            'minecraft:copper_sword' => true, 'minecraft:diamond_sword' => true,
            'minecraft:golden_sword' => true, 'minecraft:iron_sword' => true,
            'minecraft:netherite_sword' => true, 'minecraft:stone_sword' => true,
            'minecraft:wooden_sword' => true,
        ],
    ];

    private function __construct() {}

    public static function contains(ItemTag $tag, string $identifier): bool
    {
        self::assertIdentifier($identifier);

        return isset(self::MEMBERS[$tag->value][$identifier])
            || isset(self::EXTENDED_MEMBERS[$tag->value][$identifier]);
    }

    /** @return list<ItemTag> */
    public static function tagsFor(string $identifier): array
    {
        self::assertIdentifier($identifier);
        $tags = [];
        foreach (ItemTag::cases() as $tag) {
            if (isset(self::MEMBERS[$tag->value][$identifier])
                || isset(self::EXTENDED_MEMBERS[$tag->value][$identifier])) {
                $tags[] = $tag;
            }
        }

        return $tags;
    }

    /** @return list<string> */
    public static function members(ItemTag $tag): array
    {
        $members = self::MEMBERS[$tag->value] ?? self::EXTENDED_MEMBERS[$tag->value] ?? null;
        if ($members === null) {
            throw new LogicException('Item tag does not have an authoritative membership definition.');
        }

        return array_keys($members);
    }

    private static function assertIdentifier(string $identifier): void
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1) {
            throw new InvalidArgumentException('Item identifier must be canonical and namespaced.');
        }
    }
}
