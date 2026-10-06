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

namespace Bedriox\Server\Entity\Spawn\Natural;

final readonly class NetherStructureProvenance
{
    public function __construct(
        public NetherStructureType $type,
        public int $regionX,
        public int $regionZ,
        public int $centerX,
        public int $baseY,
        public int $centerZ,
    ) {}

    public function key(): string
    {
        return $this->type->value . ':' . $this->regionX . ':' . $this->regionZ;
    }

    public function occupiesFloor(int $x, int $y, int $z): bool
    {
        if ($y !== $this->baseY) {
            return false;
        }
        $dx = abs($x - $this->centerX);
        $dz = abs($z - $this->centerZ);

        return ($dx <= 3 && $dz <= 34)
            || ($dz <= 3 && $dx <= 34)
            || ($dx >= 25 && $dx <= 34 && $dz <= 8)
            || ($dz >= 25 && $dz <= 34 && $dx <= 8)
            || ($dx <= 8 && $dz <= 8);
    }
}
