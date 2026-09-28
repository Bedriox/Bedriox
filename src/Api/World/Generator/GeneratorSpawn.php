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

namespace Bedriox\Api\World\Generator;

use InvalidArgumentException;

final readonly class GeneratorSpawn
{
    public function __construct(public int $x, public int $y, public int $z)
    {
        if ($x < -0x80000000 || $x > 0x7fffffff || $z < -0x80000000 || $z > 0x7fffffff
            || $y < -64 || $y > 319) {
            throw new InvalidArgumentException('Generator spawn is outside the supported world bounds.');
        }
    }
}
