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

/** Canonical Bedrock particle effects supported by the typed public API. */
enum ParticleType: string
{
    case ARROW_SPELL_EMITTER = 'minecraft:arrow_spell_emitter';
    case BALLOON_GAS_PARTICLE = 'minecraft:balloon_gas_particle';
    case BUBBLE = 'minecraft:basic_bubble_particle';
    case BASIC_BUBBLE_PARTICLE_MANUAL = 'minecraft:basic_bubble_particle_manual';
    case CRITICAL_HIT = 'minecraft:basic_crit_particle';
    case FLAME = 'minecraft:basic_flame_particle';
    case PORTAL = 'minecraft:basic_portal_particle';
    case SMOKE = 'minecraft:basic_smoke_particle';
    case CAMPFIRE_SMOKE = 'minecraft:campfire_smoke_particle';
    case CAMPFIRE_TALL_SMOKE = 'minecraft:campfire_tall_smoke_particle';
    case CONDUIT = 'minecraft:conduit_particle';
    case DRAGON_BREATH = 'minecraft:dragon_breath_fire';
    case ENCHANTING_TABLE = 'minecraft:enchanting_table_particle';
    case END_ROD = 'minecraft:endrod';
    case EXPLOSION = 'minecraft:explosion_particle';
    case HUGE_EXPLOSION = 'minecraft:huge_explosion_emitter';
    case HEART = 'minecraft:heart_particle';
    case HONEY_DRIP = 'minecraft:honey_drip_particle';
    case LAVA_DRIP = 'minecraft:lava_drip_particle';
    case LAVA = 'minecraft:lava_particle';
    case MYCELIUM_DUST = 'minecraft:mycelium_dust_particle';
    case NECTAR_DRIP = 'minecraft:nectar_drip_particle';
    case NOTE = 'minecraft:note_particle';
    case RAIN_SPLASH = 'minecraft:rain_splash_particle';
    case REDSTONE_DUST = 'minecraft:redstone_wire_dust_particle';
    case SPLASH_SPELL = 'minecraft:splash_spell_emitter';
    case TOTEM = 'minecraft:totem_particle';
    case VILLAGER_ANGRY = 'minecraft:villager_angry';
    case VILLAGER_HAPPY = 'minecraft:villager_happy';
    case WATER_DRIP = 'minecraft:water_drip_particle';
    case WATER_SPLASH = 'minecraft:water_splash_particle';
    case WATER_WAKE = 'minecraft:water_wake_particle';
    case BLEACH = 'minecraft:bleach';
    case BLOCK_DESTRUCT = 'minecraft:block_destruct';
    case BLOCK_SLIDE = 'minecraft:block_slide';
    case BREAKING_ITEM_ICON = 'minecraft:breaking_item_icon';
    case BREAKING_ITEM_TERRAIN = 'minecraft:breaking_item_terrain';
    case BUBBLE_COLUMN_BUBBLE = 'minecraft:bubble_column_bubble';
    case BUBBLE_COLUMN_DOWN_PARTICLE = 'minecraft:bubble_column_down_particle';
    case BUBBLE_COLUMN_UP_PARTICLE = 'minecraft:bubble_column_up_particle';
    case CAMERA_SHOOT_EXPLOSION = 'minecraft:camera_shoot_explosion';
    case CAULDRON_SPELL_EMITTER = 'minecraft:cauldron_spell_emitter';
    case CAULDRON_BUBBLE_PARTICLE = 'minecraft:cauldron_bubble_particle';
    case CAULDRON_SPLASH_PARTICLE = 'minecraft:cauldron_splash_particle';
    case COLORED_FLAME_PARTICLE = 'minecraft:colored_flame_particle';
    case CONDUIT_ABSORB_PARTICLE = 'minecraft:conduit_absorb_particle';
    case CONDUIT_ATTACK_EMITTER = 'minecraft:conduit_attack_emitter';
    case CRITICAL_HIT_EMITTER = 'minecraft:critical_hit_emitter';
    case DOLPHIN_MOVE_PARTICLE = 'minecraft:dolphin_move_particle';
    case DRAGON_BREATH_LINGERING = 'minecraft:dragon_breath_lingering';
    case DRAGON_BREATH_TRAIL = 'minecraft:dragon_breath_trail';
    case DRAGON_DEATH_EXPLOSION_EMITTER = 'minecraft:dragon_death_explosion_emitter';
    case DRAGON_DESTROY_BLOCK = 'minecraft:dragon_destroy_block';
    case DRAGON_DYING_EXPLOSION = 'minecraft:dragon_dying_explosion';
    case END_CHEST = 'minecraft:end_chest';
    case ELEPHANT_TOOTH_PASTE_VAPOR_PARTICLE = 'minecraft:elephant_tooth_paste_vapor_particle';
    case EVOCATION_FANG_PARTICLE = 'minecraft:evocation_fang_particle';
    case EVOKER_SPELL = 'minecraft:evoker_spell';
    case CAULDRON_EXPLOSION_EMITTER = 'minecraft:cauldron_explosion_emitter';
    case DEATH_EXPLOSION_EMITTER = 'minecraft:death_explosion_emitter';
    case EGG_DESTROY_EMITTER = 'minecraft:egg_destroy_emitter';
    case EYEOFENDER_DEATH_EXPLODE_PARTICLE = 'minecraft:eyeofender_death_explode_particle';
    case MISC_FIRE_VAPOR_PARTICLE = 'minecraft:misc_fire_vapor_particle';
    case EXPLOSION_MANUAL = 'minecraft:explosion_manual';
    case EYE_OF_ENDER_BUBBLE_PARTICLE = 'minecraft:eye_of_ender_bubble_particle';
    case FALLING_BORDER_DUST_PARTICLE = 'minecraft:falling_border_dust_particle';
    case FALLING_DUST = 'minecraft:falling_dust';
    case FALLING_DUST_CONCRETE_POWDER_PARTICLE = 'minecraft:falling_dust_concrete_powder_particle';
    case FALLING_DUST_DRAGON_EGG_PARTICLE = 'minecraft:falling_dust_dragon_egg_particle';
    case FALLING_DUST_GRAVEL_PARTICLE = 'minecraft:falling_dust_gravel_particle';
    case FALLING_DUST_RED_SAND_PARTICLE = 'minecraft:falling_dust_red_sand_particle';
    case FALLING_DUST_SAND_PARTICLE = 'minecraft:falling_dust_sand_particle';
    case FALLING_DUST_SCAFFOLDING_PARTICLE = 'minecraft:falling_dust_scaffolding_particle';
    case FALLING_DUST_TOP_SNOW_PARTICLE = 'minecraft:falling_dust_top_snow_particle';
    case FISH_HOOK_PARTICLE = 'minecraft:fish_hook_particle';
    case FISH_POS_PARTICLE = 'minecraft:fish_pos_particle';
    case GUARDIAN_ATTACK_PARTICLE = 'minecraft:guardian_attack_particle';
    case GUARDIAN_WATER_MOVE_PARTICLE = 'minecraft:guardian_water_move_particle';
    case HUGE_EXPLOSION_LAB_MISC_EMITTER = 'minecraft:huge_explosion_lab_misc_emitter';
    case ICE_EVAPORATION_EMITTER = 'minecraft:ice_evaporation_emitter';
    case INK_EMITTER = 'minecraft:ink_emitter';
    case KNOCKBACK_ROAR_PARTICLE = 'minecraft:knockback_roar_particle';
    case LAB_TABLE_HEATBLOCK_DUST_PARTICLE = 'minecraft:lab_table_heatblock_dust_particle';
    case LAB_TABLE_MISC_MYSTICAL_PARTICLE = 'minecraft:lab_table_misc_mystical_particle';
    case LARGE_EXPLOSION = 'minecraft:large_explosion';
    case LLAMA_SPIT_SMOKE = 'minecraft:llama_spit_smoke';
    case MAGNESIUM_SALTS_EMITTER = 'minecraft:magnesium_salts_emitter';
    case MOBFLAME_EMITTER = 'minecraft:mobflame_emitter';
    case MOBFLAME_SINGLE = 'minecraft:mobflame_single';
    case MOBSPELL_EMITTER = 'minecraft:mobspell_emitter';
    case MOB_BLOCK_SPAWN_EMITTER = 'minecraft:mob_block_spawn_emitter';
    case MOB_PORTAL = 'minecraft:mob_portal';
    case OBSIDIAN_GLOW_DUST_PARTICLE = 'minecraft:obsidian_glow_dust_particle';
    case PHANTOM_TRAIL_PARTICLE = 'minecraft:phantom_trail_particle';
    case PORTAL_DIRECTIONAL = 'minecraft:portal_directional';
    case PORTAL_EAST_WEST = 'minecraft:portal_east_west';
    case PORTAL_NORTH_SOUTH = 'minecraft:portal_north_south';
    case REDSTONE_ORE_DUST_PARTICLE = 'minecraft:redstone_ore_dust_particle';
    case REDSTONE_REPEATER_DUST_PARTICLE = 'minecraft:redstone_repeater_dust_particle';
    case REDSTONE_TORCH_DUST_PARTICLE = 'minecraft:redstone_torch_dust_particle';
    case RISING_BORDER_DUST_PARTICLE = 'minecraft:rising_border_dust_particle';
    case SHULKER_BULLET = 'minecraft:shulker_bullet';
    case SILVERFISH_GRIEF_EMITTER = 'minecraft:silverfish_grief_emitter';
    case SPARKLER_EMITTER = 'minecraft:sparkler_emitter';
    case SPONGE_ABSORB_WATER_PARTICLE = 'minecraft:sponge_absorb_water_particle';
    case SQUID_FLEE_PARTICLE = 'minecraft:squid_flee_particle';
    case SQUID_INK_BUBBLE = 'minecraft:squid_ink_bubble';
    case SQUID_MOVE_PARTICLE = 'minecraft:squid_move_particle';
    case STUNNED_EMITTER = 'minecraft:stunned_emitter';
    case TOTEM_MANUAL = 'minecraft:totem_manual';
    case UNDERWATER_TORCH_PARTICLE = 'minecraft:underwater_torch_particle';
    case WATER_EVAPORATION_ACTOR_EMITTER = 'minecraft:water_evaporation_actor_emitter';
    case WATER_EVAPORATION_BUCKET_EMITTER = 'minecraft:water_evaporation_bucket_emitter';
    case WATER_EVAPORATION_MANUAL = 'minecraft:water_evaporation_manual';
    case WATER_SPLASH_PARTICLE_MANUAL = 'minecraft:water_splash_particle_manual';
    case WITHER_BOSS_INVULNERABLE = 'minecraft:wither_boss_invulnerable';
}
