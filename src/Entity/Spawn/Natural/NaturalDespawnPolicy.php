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
use InvalidArgumentException;

final readonly class NaturalDespawnPolicy
{
    /** @var array<string, float> */
    private array $hardDistancesSquared;

    /** @var array<string, float> */
    private array $softDistancesSquared;

    private NaturalDespawnRandom $random;

    /**
     * @param array<string, float|int> $distanceByCategory category value => hard distance
     * @param array<string, float|int> $softDistanceByCategory category value => randomized distance
     */
    public function __construct(
        public int $minimumAgeTicks,
        array $distanceByCategory,
        array $softDistanceByCategory = [],
        private int $softDespawnChance = 40,
        ?NaturalDespawnRandom $random = null,
    ) {
        if ($minimumAgeTicks < 0 || $minimumAgeTicks > 0x7fffffff || $distanceByCategory === []
            || $softDespawnChance < 1 || $softDespawnChance > 1_000_000) {
            throw new InvalidArgumentException('Natural-despawn policy is invalid.');
        }
        $hardDistances = [];
        foreach ($distanceByCategory as $category => $distance) {
            if (EntityCategory::tryFrom($category) === null || isset($hardDistances[$category])
                || !is_finite((float) $distance) || $distance <= 0.0 || $distance > 65_536.0) {
                throw new InvalidArgumentException('Natural-despawn category distance is invalid.');
            }
            $hardDistances[$category] = (float) $distance ** 2;
        }
        $softDistances = [];
        foreach ($softDistanceByCategory as $category => $distance) {
            if (!isset($hardDistances[$category]) || isset($softDistances[$category])
                || !is_finite((float) $distance) || $distance <= 0.0 || $distance >= sqrt($hardDistances[$category])) {
                throw new InvalidArgumentException('Natural soft-despawn category distance is invalid.');
            }
            $softDistances[$category] = (float) $distance ** 2;
        }
        $this->hardDistancesSquared = $hardDistances;
        $this->softDistancesSquared = $softDistances;
        $this->random = $random ?? new SystemNaturalDespawnRandom();
    }

    /** @param array<int, mixed> $players */
    public function decide(NaturalDespawnState $entity, array $players): NaturalDespawnDecision
    {
        if (!array_is_list($players) || count($players) > NaturalSpawnCandidatePlanner::MAXIMUM_PLAYERS) {
            throw new InvalidArgumentException('Natural-despawn player set is invalid or oversized.');
        }
        if ($entity->exempt()) {
            return NaturalDespawnDecision::KEEP_EXEMPT;
        }
        if ($entity->ageTicks < $this->minimumAgeTicks) {
            return NaturalDespawnDecision::KEEP_YOUNG;
        }
        $distanceSquared = $this->hardDistancesSquared[$entity->category->value]
            ?? throw new InvalidArgumentException('Natural-despawn category has no distance policy.');
        $nearest = null;
        foreach ($players as $player) {
            if (!$player instanceof NaturalSpawnPlayer) {
                throw new InvalidArgumentException('Natural-despawn player set contains an invalid player.');
            }
            if ($player->worldName !== $entity->worldName) {
                continue;
            }
            $dx = $player->position->x - $entity->position->x;
            $dy = $player->position->y - $entity->position->y;
            $dz = $player->position->z - $entity->position->z;
            $distance = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
            $nearest = $nearest === null ? $distance : min($nearest, $distance);
        }
        if ($nearest === null) {
            return NaturalDespawnDecision::KEEP_NO_PLAYERS;
        }

        if ($nearest > $distanceSquared) {
            return NaturalDespawnDecision::DESPAWN_DISTANCE;
        }
        $softDistanceSquared = $this->softDistancesSquared[$entity->category->value] ?? null;
        if ($softDistanceSquared !== null && $nearest > $softDistanceSquared
            && $this->random->oneIn($this->softDespawnChance)) {
            return NaturalDespawnDecision::DESPAWN_SOFT_DISTANCE;
        }

        return NaturalDespawnDecision::KEEP_NEAR_PLAYER;
    }
}
