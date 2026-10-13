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

namespace Bedriox\Server\World\Generation\Structure\End;

final readonly class EndInfrastructureLandmark
{
    public function __construct(
        public string $id,
        public string $kind,
        public string $variant,
        public int $x,
        public int $y,
        public int $z,
        public int $radius,
        public int $height,
    ) {
        if ($id === '' || $kind === '' || $variant === '' || $radius < 0 || $height < 0) {
            throw new \InvalidArgumentException('End infrastructure landmark contains invalid values.');
        }
    }
}
