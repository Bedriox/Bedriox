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
use InvalidArgumentException;

final readonly class NetherPortalFrame
{
    public const int MINIMUM_WIDTH = 2;
    public const int MAXIMUM_WIDTH = 21;
    public const int MINIMUM_HEIGHT = 3;
    public const int MAXIMUM_HEIGHT = 21;

    public function __construct(
        public BlockPosition $bottomLeft,
        public int $width,
        public int $height,
        public PortalAxis $axis,
    ) {
        if ($width < self::MINIMUM_WIDTH || $width > self::MAXIMUM_WIDTH
            || $height < self::MINIMUM_HEIGHT || $height > self::MAXIMUM_HEIGHT) {
            throw new InvalidArgumentException('Nether portal frame dimensions are outside the supported vanilla bounds.');
        }
    }

    /** @return list<BlockPosition> */
    public function interior(): array
    {
        $positions = [];
        for ($horizontal = 0; $horizontal < $this->width; ++$horizontal) {
            for ($vertical = 0; $vertical < $this->height; ++$vertical) {
                $positions[] = new BlockPosition(
                    $this->bottomLeft->x + ($this->axis->stepX() * $horizontal),
                    $this->bottomLeft->y + $vertical,
                    $this->bottomLeft->z + ($this->axis->stepZ() * $horizontal),
                );
            }
        }

        return $positions;
    }
}
