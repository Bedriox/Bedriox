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

enum InventoryActionType: string
{
    case MOVE = 'move';
    case SWAP = 'swap';
    case SPLIT = 'split';
    case MERGE = 'merge';
    case DROP = 'drop';
    case DESTROY = 'destroy';
    case CREATIVE_CREATE = 'creative_create';
    case SLOT_CHANGE = 'slot_change';
}
