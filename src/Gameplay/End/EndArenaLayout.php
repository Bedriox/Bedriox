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

namespace Bedriox\Server\Gameplay\End;

use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;

final class EndArenaLayout
{
    /** @return list<Position> */
    public static function crystalPositions(): array
    {
        return [
            new Position(42.5, 78.0, 0.5), new Position(34.5, 81.0, 25.5),
            new Position(13.5, 84.0, 40.5), new Position(-12.5, 87.0, 40.5),
            new Position(-33.5, 90.0, 25.5), new Position(-41.5, 93.0, 0.5),
            new Position(-33.5, 96.0, -24.5), new Position(-12.5, 99.0, -39.5),
            new Position(13.5, 102.0, -39.5), new Position(34.5, 105.0, -24.5),
        ];
    }

    /** @return list<ChunkPosition> Central encounter chunks required before initial actor creation. */
    public static function requiredChunks(): array
    {
        $chunks = [new ChunkPosition(0, 0)];
        foreach (self::crystalPositions() as $position) {
            $chunk = new ChunkPosition(
                (int) floor($position->x / 16.0),
                (int) floor($position->z / 16.0),
            );
            $chunks[$chunk->key()] = $chunk;
        }

        return array_values($chunks);
    }
}
