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

/** Stable semantic families for canonical item identifiers. */
enum ItemTag: string
{
    case ARMOR = 'armor';
    case ARROW = 'arrow';
    case AXE = 'axe';
    case BOAT = 'boat';
    case BOOTS = 'boots';
    case BOW = 'bow';
    case CHESTPLATE = 'chestplate';
    case CROSSBOW = 'crossbow';
    case FOOD = 'food';
    case CONSUMABLE = 'consumable';
    case DOOR = 'door';
    case FUEL = 'fuel';
    case HELMET = 'helmet';
    case HOE = 'hoe';
    case LEGGINGS = 'leggings';
    case PICKAXE = 'pickaxe';
    case POTION = 'potion';
    case PROJECTILE = 'projectile';
    case CARPET = 'carpet';
    case SIGN = 'sign';
    case SHOVEL = 'shovel';
    case SPAWN_EGG = 'spawn_egg';
    case SWORD = 'sword';
}
