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

namespace Bedriox\Server\World\Storage\LevelDb;

use Bedriox\Api\World\WorldDimension;

/** Private LevelDB namespace; it deliberately does not replace Mojang's entity-NBT chunk record. */
final class BedrioxEntityKey
{
    private const string PREFIX = "bedriox.entity.v1\x00";

    private function __construct() {}

    public static function chunk(
        int $chunkX,
        int $chunkZ,
        WorldDimension $dimension = WorldDimension::OVERWORLD,
    ): string {
        return self::PREFIX . LevelDbChunkKey::prefix($chunkX, $chunkZ, $dimension);
    }
}
