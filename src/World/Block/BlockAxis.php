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

namespace Bedriox\Server\World\Block;

use InvalidArgumentException;

/** Canonical axis value used by directional pillar block states. */
enum BlockAxis: string
{
    case X = 'x';
    case Y = 'y';
    case Z = 'z';

    public static function fromBlockFace(int $face): self
    {
        return match ($face) {
            0, 1 => self::Y,
            2, 3 => self::Z,
            4, 5 => self::X,
            default => throw new InvalidArgumentException('Block face must be between zero and five.'),
        };
    }
}
