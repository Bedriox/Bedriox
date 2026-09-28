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

/** Version-independent reason for applying durability damage to an item. */
enum ItemDamageCause: string
{
    case BLOCK_BREAK = 'block_break';
    case ENTITY_ATTACK = 'entity_attack';
    case DAMAGE_ABSORPTION = 'damage_absorption';
    case ITEM_USE = 'item_use';
    case CUSTOM = 'custom';
}
