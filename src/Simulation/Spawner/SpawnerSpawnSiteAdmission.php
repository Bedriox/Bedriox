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

namespace Bedriox\Server\Simulation\Spawner;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnMedium;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Spawner\SpawnerSpawnSiteEnvironment;

/** Loaded-terrain and species admission which must succeed before spawner plugin events run. */
final readonly class SpawnerSpawnSiteAdmission
{
    public function __construct(
        private SpawnerSpawnSiteEnvironment $environment,
        private EntityDefinitionRegistry $definitions,
    ) {}

    public function allows(string $worldName, string $identifier, Position $position, bool $trial): bool
    {
        $registration = $this->definitions->get($identifier);
        if ($registration === null) {
            return false;
        }
        $chunk = new ChunkPosition(
            (int) floor($position->x / 16.0),
            (int) floor($position->z / 16.0),
        );
        if (!$this->environment->isChunkLoaded($worldName, $chunk)
            || !$this->environment->isChunkStable($worldName, $chunk)
            || !$this->environment->isCollisionFree($worldName, $registration->definition->type, $position)) {
            return false;
        }

        $medium = $this->environment->medium($worldName, $position);
        $type = $registration->definition->type;
        if ($registration->definition->category === EntityCategory::WATER
            || $type === VanillaEntityType::DROWNED
            || $type === VanillaEntityType::GUARDIAN) {
            return $medium === NaturalSpawnMedium::WATER;
        }
        if ($type === VanillaEntityType::STRIDER) {
            return $medium === NaturalSpawnMedium::LAVA;
        }
        if ($medium === NaturalSpawnMedium::WATER || $medium === NaturalSpawnMedium::LAVA) {
            return false;
        }
        if ($registration->definition->gravity > 0.0 && $medium !== NaturalSpawnMedium::GROUND) {
            return false;
        }

        return $trial
            || $registration->definition->category !== EntityCategory::MONSTER
            || $this->environment->lightLevel($worldName, $position) <= 7;
    }
}
