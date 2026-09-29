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

namespace Bedriox\Api\Effect;

/** Stable vanilla effect identities; wire IDs remain private to the protocol boundary. */
enum EffectType: string
{
    case SPEED = 'minecraft:speed';
    case SLOWNESS = 'minecraft:slowness';
    case HASTE = 'minecraft:haste';
    case MINING_FATIGUE = 'minecraft:mining_fatigue';
    case STRENGTH = 'minecraft:strength';
    case INSTANT_HEALTH = 'minecraft:instant_health';
    case INSTANT_DAMAGE = 'minecraft:instant_damage';
    case JUMP_BOOST = 'minecraft:jump_boost';
    case NAUSEA = 'minecraft:nausea';
    case REGENERATION = 'minecraft:regeneration';
    case RESISTANCE = 'minecraft:resistance';
    case FIRE_RESISTANCE = 'minecraft:fire_resistance';
    case WATER_BREATHING = 'minecraft:water_breathing';
    case INVISIBILITY = 'minecraft:invisibility';
    case BLINDNESS = 'minecraft:blindness';
    case NIGHT_VISION = 'minecraft:night_vision';
    case HUNGER = 'minecraft:hunger';
    case WEAKNESS = 'minecraft:weakness';
    case POISON = 'minecraft:poison';
    case WITHER = 'minecraft:wither';
    case HEALTH_BOOST = 'minecraft:health_boost';
    case ABSORPTION = 'minecraft:absorption';
    case SATURATION = 'minecraft:saturation';
    case LEVITATION = 'minecraft:levitation';
    case FATAL_POISON = 'minecraft:fatal_poison';
    case CONDUIT_POWER = 'minecraft:conduit_power';
    case SLOW_FALLING = 'minecraft:slow_falling';
    case BAD_OMEN = 'minecraft:bad_omen';
    case VILLAGE_HERO = 'minecraft:village_hero';
    case DARKNESS = 'minecraft:darkness';
    case TRIAL_OMEN = 'minecraft:trial_omen';
    case WIND_CHARGED = 'minecraft:wind_charged';
    case WEAVING = 'minecraft:weaving';
    case OOZING = 'minecraft:oozing';
    case INFESTED = 'minecraft:infested';
}
