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

namespace Bedriox\Api\Event\Inventory;

enum InventoryCloseReason: string
{
    case CLIENT = 'client';
    case PLUGIN = 'plugin';
    case SERVER = 'server';
    case REPLACED = 'replaced';
    case TELEPORT = 'teleport';
    case DEATH = 'death';
    case DISCONNECT = 'disconnect';
    case OUT_OF_RANGE = 'out_of_range';
    case BLOCK_REMOVED = 'block_removed';
    case CHUNK_UNLOAD = 'chunk_unload';
    case WORLD_UNLOAD = 'world_unload';
}
