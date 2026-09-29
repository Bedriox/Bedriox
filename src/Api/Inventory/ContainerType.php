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

/** Stable storage-container identities exposed to plugins. */
enum ContainerType: string
{
    /** Plugin-owned inventory with no backing block or implicit persistence. */
    case VIRTUAL = 'virtual';
    case CHEST = 'chest';
    case DOUBLE_CHEST = 'double_chest';
    case TRAPPED_CHEST = 'trapped_chest';
    case DOUBLE_TRAPPED_CHEST = 'double_trapped_chest';
    case BARREL = 'barrel';
    case SHULKER_BOX = 'shulker_box';
    case ENDER_CHEST = 'ender_chest';
    case BREWING_STAND = 'brewing_stand';
}
