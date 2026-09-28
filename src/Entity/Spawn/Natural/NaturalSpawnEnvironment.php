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

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;

interface NaturalSpawnEnvironment
{
    public function isChunkLoaded(string $worldName, ChunkPosition $chunk): bool;

    public function isChunkStable(string $worldName, ChunkPosition $chunk): bool;

    public function dimension(string $worldName): string;

    public function biome(string $worldName, Position $position): string;

    public function heightAt(string $worldName, float $x, float $z): ?float;

    public function medium(string $worldName, Position $position): NaturalSpawnMedium;

    public function lightLevel(string $worldName, Position $position): int;

    public function isCollisionFree(string $worldName, EntityType $type, Position $position): bool;

    public function nearestPlayerDistanceSquared(string $worldName, Position $position): ?float;

    public function worldSpawnDistanceSquared(string $worldName, Position $position): float;

    public function categoryCount(string $worldName, EntityCategory $category): int;

    public function localCategoryDensity(
        string $worldName,
        ChunkPosition $chunk,
        EntityCategory $category,
    ): int;
}
