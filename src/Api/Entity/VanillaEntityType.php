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

namespace Bedriox\Api\Entity;

/**
 * Built-in entity identities. The complete current-version catalog is admitted
 * by Data; cases are added here only when Bedriox implements their gameplay.
 */
enum VanillaEntityType: string implements VanillaEntityIdentity
{
    case BOAT = 'minecraft:boat';
    case CHEST_BOAT = 'minecraft:chest_boat';
    case COW = 'minecraft:cow';
    case CHICKEN = 'minecraft:chicken';
    case PIG = 'minecraft:pig';
    case RABBIT = 'minecraft:rabbit';
    case SHEEP = 'minecraft:sheep';
    case SKELETON = 'minecraft:skeleton';
    case ZOMBIE = 'minecraft:zombie';
    case HUSK = 'minecraft:husk';
    case ZOMBIE_VILLAGER = 'minecraft:zombie_villager_v2';
    case STRAY = 'minecraft:stray';
    case BOGGED = 'minecraft:bogged';
    case PARCHED = 'minecraft:parched';
    case WITHER_SKELETON = 'minecraft:wither_skeleton';
    case SPIDER = 'minecraft:spider';
    case CAVE_SPIDER = 'minecraft:cave_spider';
    case CREEPER = 'minecraft:creeper';
    case SLIME = 'minecraft:slime';
    case MAGMA_CUBE = 'minecraft:magma_cube';
    case BLAZE = 'minecraft:blaze';
    case GHAST = 'minecraft:ghast';
    case HAPPY_GHAST = 'minecraft:happy_ghast';
    case HOGLIN = 'minecraft:hoglin';
    case PIGLIN = 'minecraft:piglin';
    case PIGLIN_BRUTE = 'minecraft:piglin_brute';
    case STRIDER = 'minecraft:strider';
    case ZOGLIN = 'minecraft:zoglin';
    case ZOMBIFIED_PIGLIN = 'minecraft:zombie_pigman';
    case ENDERMAN = 'minecraft:enderman';
    case ENDERMITE = 'minecraft:endermite';
    case SILVERFISH = 'minecraft:silverfish';
    case WITCH = 'minecraft:witch';
    case COD = 'minecraft:cod';
    case SALMON = 'minecraft:salmon';
    case TROPICAL_FISH = 'minecraft:tropicalfish';
    case PUFFERFISH = 'minecraft:pufferfish';
    case SQUID = 'minecraft:squid';
    case GLOW_SQUID = 'minecraft:glow_squid';
    case DOLPHIN = 'minecraft:dolphin';
    case TURTLE = 'minecraft:turtle';
    case AXOLOTL = 'minecraft:axolotl';
    case DROWNED = 'minecraft:drowned';
    case GUARDIAN = 'minecraft:guardian';
    case WOLF = 'minecraft:wolf';
    case CAT = 'minecraft:cat';
    case OCELOT = 'minecraft:ocelot';
    case HORSE = 'minecraft:horse';
    case DONKEY = 'minecraft:donkey';
    case MULE = 'minecraft:mule';
    case CAMEL = 'minecraft:camel';
    case LLAMA = 'minecraft:llama';
    case TRADER_LLAMA = 'minecraft:trader_llama';
    case SKELETON_HORSE = 'minecraft:skeleton_horse';
    case ZOMBIE_HORSE = 'minecraft:zombie_horse';
    case FOX = 'minecraft:fox';
    case GOAT = 'minecraft:goat';
    case PANDA = 'minecraft:panda';
    case POLAR_BEAR = 'minecraft:polar_bear';
    case ARMADILLO = 'minecraft:armadillo';
    case MOOSHROOM = 'minecraft:mooshroom';
    case SNIFFER = 'minecraft:sniffer';

    public function identifier(): string
    {
        return $this->value;
    }
}
