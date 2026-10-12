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

/** Stable semantic families for canonical block identifiers. */
enum BlockTag: string
{
    case BUTTON = 'button';
    case CARPET = 'carpet';
    case CROP = 'crop';
    case DOUBLE_SLAB = 'double_slab';
    case DOOR = 'door';
    case FARMLAND = 'farmland';
    case FENCE = 'fence';
    case FENCE_GATE = 'fence_gate';
    case GRAVITY_AFFECTED = 'gravity_affected';
    case HANGING_SIGN = 'hanging_sign';
    case LEAVES = 'leaves';
    case LIQUID = 'liquid';
    case LOG = 'log';
    case ORE = 'ore';
    case PRESSURE_PLATE = 'pressure_plate';
    case REPLACEABLE = 'replaceable';
    case SAND = 'sand';
    case SIGN = 'sign';
    case SLAB = 'slab';
    case STAIRS = 'stairs';
    case TRAPDOOR = 'trapdoor';
    case WALL = 'wall';
    case WOOD = 'wood';
    case WOOL = 'wool';
}
