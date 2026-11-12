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

namespace Bedriox\Api\Map;

enum MapScale: int
{
    case CLOSEST = 1;
    case CLOSE = 2;
    case NORMAL = 4;
    case FAR = 8;
    case FARTHEST = 16;

    public function protocolValue(): int
    {
        return match ($this) {
            self::CLOSEST => 0,
            self::CLOSE => 1,
            self::NORMAL => 2,
            self::FAR => 3,
            self::FARTHEST => 4,
        };
    }
}
