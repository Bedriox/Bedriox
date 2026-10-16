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

/** Bedrock dye metadata shared by bed items and Bed block actors. */
enum BedColor: int
{
    case WHITE = 0;
    case ORANGE = 1;
    case MAGENTA = 2;
    case LIGHT_BLUE = 3;
    case YELLOW = 4;
    case LIME = 5;
    case PINK = 6;
    case GRAY = 7;
    case LIGHT_GRAY = 8;
    case CYAN = 9;
    case PURPLE = 10;
    case BLUE = 11;
    case BROWN = 12;
    case GREEN = 13;
    case RED = 14;
    case BLACK = 15;
}
