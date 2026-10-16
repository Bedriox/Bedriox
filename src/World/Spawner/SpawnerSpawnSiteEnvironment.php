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

namespace Bedriox\Server\World\Spawner;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;

/** Minimal authoritative loaded-world view required to validate a spawner site. */
interface SpawnerSpawnSiteEnvironment
{
    public function isChunkLoaded(string $worldName, ChunkPosition $chunk): bool;

    public function isChunkStable(string $worldName, ChunkPosition $chunk): bool;

    public function isCollisionFree(string $worldName, EntityType $type, Position $position): bool;

    public function medium(string $worldName, Position $position): NaturalSpawnMedium;

    public function lightLevel(string $worldName, Position $position): int;
}
