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

namespace Bedriox\Server\Player;

enum InventoryContainer
{
    case Main;
    case Cursor;
    case Armor;
    case Offhand;
    /** Ephemeral player-owned 2x2 or crafting-table 3x3 input grid. */
    case CraftingInput;
    /** Ephemeral request-local slot populated by an authoritative craft or creative selection. */
    case CreatedOutput;
    /** The simulation-authorized dynamic storage window currently open for this player. */
    case OpenedContainer;
}
