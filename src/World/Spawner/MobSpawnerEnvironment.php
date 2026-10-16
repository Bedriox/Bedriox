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
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;

/** World-facing admission surface kept outside the spawner's deterministic state transition. */
interface MobSpawnerEnvironment
{
    public function entityType(string $identifier): ?EntityType;

    public function hasEligiblePlayer(string $worldName, BlockPosition $position, int $range): bool;

    public function nearbyEntities(string $worldName, BlockPosition $position, string $identifier, int $range): int;

    public function allowActivation(string $worldName, BlockPosition $position): bool;

    public function allowSpawn(string $worldName, BlockPosition $spawner, string $identifier, Position $position): bool;

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome;
}
