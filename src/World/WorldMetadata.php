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

namespace Bedriox\Server\World;

use InvalidArgumentException;

final readonly class WorldMetadata
{
    public function __construct(
        public string $name,
        public int $seed,
    ) {
        if ($name === '' || strlen($name) > 64 || preg_match('/^[A-Za-z0-9._ -]+$/D', $name) !== 1) {
            throw new InvalidArgumentException('World name must contain 1-64 safe display-name characters.');
        }
    }
}
