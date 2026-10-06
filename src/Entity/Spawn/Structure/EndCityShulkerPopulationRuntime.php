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
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use Closure;

/** One-time structure population owner; candidate geometry remains generator-owned. */
final readonly class EndCityShulkerPopulationRuntime
{
    /** @var Closure(EntitySpawnRequest): EntitySpawnOutcome */
    private Closure $spawn;

    /** @var Closure(EndCityPlacement, ChunkPosition): array<int, mixed> */
    private Closure $candidates;

    /**
     * @param Closure(EntitySpawnRequest): EntitySpawnOutcome $spawn
     * @param Closure(EndCityPlacement, ChunkPosition): array<int, mixed> $candidates
     */
    public function __construct(
        private EndCityLocator $cities,
        private EndCityShulkerPopulationState $state,
        Closure $spawn,
        Closure $candidates,
        private ?EndCityShulkerPopulationStateRepository $repository = null,
    ) {
        $this->spawn = $spawn;
        $this->candidates = $candidates;
    }

    public function populateFinalizedChunk(string $worldName, ChunkPosition $chunk): int
    {
        $spawned = 0;
        foreach ($this->cities->intersectingChunk($chunk) as $city) {
            $candidates = ($this->candidates)($city, $chunk);
            if (!array_is_list($candidates) || count($candidates) > 4) {
                throw new \UnexpectedValueException('End City Shulker candidates must be a bounded list.');
            }
            foreach ($candidates as $slot => $position) {
                if (!$position instanceof Position) {
                    throw new \UnexpectedValueException('End City Shulker candidate is invalid.');
                }
                $claim = $city->key() . ':' . $chunk->x . ':' . $chunk->z . ':' . $slot;
                if ($this->state->contains($claim)) {
                    continue;
                }
                $outcome = ($this->spawn)(new EntitySpawnRequest(
                    VanillaEntityType::SHULKER,
                    SpawnCause::STRUCTURE,
                    $worldName,
                    $position,
                    self::yaw($city, $slot),
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

    private static function yaw(EndCityPlacement $city, int $slot): float
    {
        $digest = hash('sha256', $city->key() . ':' . $slot, true);
        $hash = (ord($digest[0]) << 24) | (ord($digest[1]) << 16) | (ord($digest[2]) << 8) | ord($digest[3]);

        return (float) (($hash % 4) * 90);
    }
}
