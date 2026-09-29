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

namespace Bedriox\Api\World\Particle;

/** Vanilla particles whose level-event form carries no additional payload. */
enum StandardParticleType: string
{
    case ANGRY_VILLAGER = 'angry_villager';
    case BUBBLE = 'bubble';
    case BUBBLE_MANUAL = 'bubble_manual';
    case EVAPORATION = 'evaporation';
    case CANDLE_FLAME = 'candle_flame';
    case LARGE_SMOKE = 'large_smoke';
    case RISING_RED_DUST = 'rising_red_dust';
    case ENCHANTING_TABLE = 'enchanting_table';
    case ENDERMAN_TELEPORT = 'enderman_teleport';
    case ENTITY_FLAME = 'entity_flame';
    case BREEZE_WIND_EXPLOSION = 'breeze_wind_explosion';
    case EXPLOSION = 'explosion';
    case FLAME = 'flame';
    case HAPPY_VILLAGER = 'happy_villager';
    case HUGE_EXPLOSION = 'huge_explosion';
    case HUGE_EXPLOSION_SEED = 'huge_explosion_seed';
    case LAVA_DRIP = 'lava_drip';
    case LAVA = 'lava';
    case WATER_SPLASH_MANUAL = 'water_splash_manual';
    case HONEY_DRIP = 'honey_drip';
    case STALACTITE_WATER_DRIP = 'stalactite_water_drip';
    case STALACTITE_LAVA_DRIP = 'stalactite_lava_drip';
    case PORTAL = 'portal';
    case RAIN_SPLASH = 'rain_splash';
    case SLIME = 'slime';
    case SNOWBALL_POOF = 'snowball_poof';
    case SONIC_EXPLOSION = 'sonic_explosion';
    case SPORE = 'spore';
    case TRACKING_EMITTER = 'tracking_emitter';
    case NOTE = 'note';
    case WITCH_SPELL = 'witch_spell';
    case CARROT = 'carrot';
    case MOB_APPEARANCE = 'mob_appearance';
    case END_ROD = 'end_rod';
    case DRAGONS_BREATH = 'dragons_breath';
    case SPIT = 'spit';
    case TOTEM = 'totem';
    case FOOD = 'food';
    case FIREWORKS_STARTER = 'fireworks_starter';
    case FIREWORKS_SPARK = 'fireworks_spark';
    case FIREWORKS_OVERLAY = 'fireworks_overlay';
    case BALLOON_GAS = 'balloon_gas';
    case COLORED_FLAME = 'colored_flame';
    case SPARKLER = 'sparkler';
    case CONDUIT = 'conduit';
    case BUBBLE_COLUMN_UP = 'bubble_column_up';
    case BUBBLE_COLUMN_DOWN = 'bubble_column_down';
    case SNEEZE = 'sneeze';
    case SHULKER_BULLET = 'shulker_bullet';
    case BLEACH = 'bleach';
    case DRAGON_DESTROY_BLOCK = 'dragon_destroy_block';
    case MYCELIUM_DUST = 'mycelium_dust';
    case FALLING_RED_DUST = 'falling_red_dust';
    case CAMPFIRE_SMOKE = 'campfire_smoke';
    case TALL_CAMPFIRE_SMOKE = 'tall_campfire_smoke';
    case DRAGON_BREATH_FIRE = 'dragon_breath_fire';
    case DRAGON_BREATH_TRAIL = 'dragon_breath_trail';
    case BLUE_FLAME = 'blue_flame';
    case SOUL = 'soul';
    case OBSIDIAN_TEAR = 'obsidian_tear';
    case PORTAL_REVERSE = 'portal_reverse';
    case SNOWFLAKE = 'snowflake';
    case VIBRATION_SIGNAL = 'vibration_signal';
    case SCULK_SENSOR_REDSTONE = 'sculk_sensor_redstone';
    case SPORE_BLOSSOM_SHOWER = 'spore_blossom_shower';
    case SPORE_BLOSSOM_AMBIENT = 'spore_blossom_ambient';
    case WAX = 'wax';
    case ELECTRIC_SPARK = 'electric_spark';
    case SHRIEK = 'shriek';
    case SCULK_SOUL = 'sculk_soul';
    case BRUSH_DUST = 'brush_dust';
    case CHERRY_LEAVES = 'cherry_leaves';
    case DUST_PLUME = 'dust_plume';
    case WHITE_SMOKE = 'white_smoke';
    case VAULT_CONNECTION = 'vault_connection';
    case WIND_EXPLOSION = 'wind_explosion';
    case WOLF_ARMOR_CRACK = 'wolf_armor_crack';
    case OMINOUS_ITEM_SPAWNER = 'ominous_item_spawner';
    case CREAKING_CRUMBLE = 'creaking_crumble';
    case PALE_OAK_LEAVES = 'pale_oak_leaves';
    case EYEBLOSSOM_OPEN = 'eyeblossom_open';
    case EYEBLOSSOM_CLOSE = 'eyeblossom_close';
    case GREEN_FLAME = 'green_flame';
    case PAUSE_MOB_GROWTH = 'pause_mob_growth';
    case RESET_MOB_GROWTH = 'reset_mob_growth';
    case WATER_DRIP = 'water_drip';
    case WATER_SPLASH = 'water_splash';
    case WATER_WAKE = 'water_wake';
}
