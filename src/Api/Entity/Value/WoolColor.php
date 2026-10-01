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

namespace Bedriox\Api\Entity\Value;

enum WoolColor: string
{
    case WHITE = 'white';
    case ORANGE = 'orange';
    case MAGENTA = 'magenta';
    case LIGHT_BLUE = 'light_blue';
    case YELLOW = 'yellow';
    case LIME = 'lime';
    case PINK = 'pink';
    case GRAY = 'gray';
    case LIGHT_GRAY = 'light_gray';
    case CYAN = 'cyan';
    case PURPLE = 'purple';
    case BLUE = 'blue';
    case BROWN = 'brown';
    case GREEN = 'green';
    case RED = 'red';
    case BLACK = 'black';

    public function woolIdentifier(): string
    {
        return 'minecraft:' . $this->value . '_wool';
    }
}
