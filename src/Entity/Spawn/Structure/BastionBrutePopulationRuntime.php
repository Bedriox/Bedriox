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

namespace Bedriox\Server\Entity\Spawn\Structure;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureLocator;
use Bedriox\Server\Entity\Spawn\Natural\NetherStructureType;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Closure;

/** Admits each deterministic bastion brute exactly once after its owning chunk is finalized. */
final readonly class BastionBrutePopulationRuntime
{
    /** @var Closure(EntitySpawnRequest): EntitySpawnOutcome */
    private Closure $spawn;

    /** @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn */
    public function __construct(
        private NetherStructureLocator $structures,
        private BastionBrutePopulationState $state,
        Closure $spawn,
        private ?BastionBrutePopulationStateRepository $repository = null,
    ) {
        $this->spawn = $spawn;
    }

    public function populateFinalizedChunk(string $worldName, ChunkPosition $chunk): int
    {
        $spawned = 0;
        foreach ($this->structures->intersectingChunk($chunk) as $structure) {
            if ($structure->type !== NetherStructureType::BASTION) {
                continue;
            }
            foreach ([[0, -2], [2, 2], [-2, 2], [0, -3]] as $slot => [$offsetX, $offsetZ]) {
                $x = $structure->centerX + $offsetX;
                $z = $structure->centerZ + $offsetZ;
                if ((int) floor($x / 16.0) !== $chunk->x || (int) floor($z / 16.0) !== $chunk->z) {
                    continue;
                }
                $claim = $structure->key() . ':' . $slot;
                if ($this->state->contains($claim)) {
                    continue;
                }
                $outcome = ($this->spawn)(new EntitySpawnRequest(
                    VanillaEntityType::PIGLIN_BRUTE,
                    SpawnCause::STRUCTURE,
                    $worldName,
                    new Position($x + 0.5, $structure->baseY + 1.0, $z + 0.5),
                    self::yaw($structure->regionX, $structure->regionZ, $slot),
                ));
                if ($outcome->succeeded()) {
                    $this->state->claim($claim);
                    $this->repository?->save($this->state);
                    ++$spawned;
                }
            }
        }

        return $spawned;
    }

    private static function yaw(int $regionX, int $regionZ, int $slot): float
    {
        $digest = hash('sha256', $regionX . ':' . $regionZ . ':' . $slot, true);
        $hash = (ord($digest[0]) << 24)
            | (ord($digest[1]) << 16)
            | (ord($digest[2]) << 8)
            | ord($digest[3]);

        return (float) (($hash % 4) * 90);
    }
}
