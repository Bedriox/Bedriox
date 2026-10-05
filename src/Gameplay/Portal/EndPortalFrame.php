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

namespace Bedriox\Server\Gameplay\Portal;

use Bedriox\Server\World\BlockPosition;

final readonly class EndPortalFrame
{
    public function __construct(public BlockPosition $center) {}

    /** @return list<BlockPosition> */
    public function interior(): array
    {
        $positions = [];
        for ($x = -1; $x <= 1; ++$x) {
            for ($z = -1; $z <= 1; ++$z) {
                $positions[] = new BlockPosition($this->center->x + $x, $this->center->y, $this->center->z + $z);
            }
        }

        return $positions;
    }
}
