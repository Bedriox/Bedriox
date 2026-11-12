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

namespace Bedriox\Server\Map;

final readonly class MapPixelDelta
{
    public function __construct(
        public int $fromRevision,
        public int $toRevision,
        public int $x,
        public int $y,
        public int $width,
        public int $height,
    ) {
        if ($fromRevision < 1 || $toRevision !== $fromRevision + 1
            || $x < 0 || $y < 0 || $width < 1 || $height < 1
            || $x + $width > MapRecord::WIDTH || $y + $height > MapRecord::HEIGHT) {
            throw new \InvalidArgumentException('Map pixel delta is invalid.');
        }
    }
}
