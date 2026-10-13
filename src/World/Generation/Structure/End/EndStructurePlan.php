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

final readonly class EndStructurePlan
{
    public function __construct(
        public string $id,
        public EndStructureKind $kind,
        public int $centerX,
        public int $centerZ,
        public bool $alongX,
        public int $variant,
    ) {
        if ($variant < 0 || $variant > 3) {
            throw new \InvalidArgumentException('End structure variant is outside its bounded range.');
        }
    }
}
