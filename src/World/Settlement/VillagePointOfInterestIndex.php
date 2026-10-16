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

namespace Bedriox\Server\World\Settlement;

use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;

/** Chunk-scoped POI ownership with unique claims and bounded nearest searches. */
final class VillagePointOfInterestIndex
{
    public const int MAXIMUM_POINTS = 8_192;
    public const int MAXIMUM_SEARCH_RADIUS = 128;

    /** @var array<string, VillagePointOfInterest> */
    private array $points = [];

    /** @var array<string, string> point key to villager UUID */
    private array $claims = [];

    /** @var array<string, array<string, string>> villager UUID to POI type and point key */
    private array $claimedPointsByVillager = [];

    public function register(VillagePointOfInterest $point): void
    {
        $key = $point->key();
        if (!isset($this->points[$key]) && count($this->points) >= self::MAXIMUM_POINTS) {
            throw new InvalidArgumentException('Village point-of-interest index is full.');
        }
        $this->points[$key] = $point;
    }

    public function remove(BlockPosition $position): void
    {
        $key = self::key($position);
        $claimant = $this->claims[$key] ?? null;
        if ($claimant !== null) {
            $type = $this->points[$key]->type->value ?? null;
            if ($type !== null) {
                unset($this->claimedPointsByVillager[$claimant][$type]);
                if (($this->claimedPointsByVillager[$claimant] ?? []) === []) {
                    unset($this->claimedPointsByVillager[$claimant]);
                }
            }
        }
        unset($this->points[$key], $this->claims[$key]);
    }

    public function claim(BlockPosition $position, string $villagerUniqueId): bool
    {
        $key = self::key($position);
        EntityUuid::validate($villagerUniqueId);
        if (!isset($this->points[$key])) {
            return false;
        }
        $claim = $this->claims[$key] ?? null;
        if ($claim !== null && $claim !== $villagerUniqueId) {
            return false;
        }
        $type = $this->points[$key]->type->value;
        $existingKey = $this->claimedPointsByVillager[$villagerUniqueId][$type] ?? null;
        if ($existingKey !== null && $existingKey !== $key) {
            unset($this->claims[$existingKey]);
        }
        $this->claims[$key] = $villagerUniqueId;
        $this->claimedPointsByVillager[$villagerUniqueId][$type] = $key;

        return true;
    }

    public function releaseForVillager(string $villagerUniqueId, ?VillagePointOfInterestType $type = null): void
    {
        EntityUuid::validate($villagerUniqueId);
        $claims = $this->claimedPointsByVillager[$villagerUniqueId] ?? [];
        if ($type !== null) {
            $key = $claims[$type->value] ?? null;
            if ($key !== null) {
                unset($this->claims[$key], $this->claimedPointsByVillager[$villagerUniqueId][$type->value]);
            }
            if (($this->claimedPointsByVillager[$villagerUniqueId] ?? []) === []) {
                unset($this->claimedPointsByVillager[$villagerUniqueId]);
            }

            return;
        }
        foreach ($claims as $key) {
            unset($this->claims[$key]);
        }
        unset($this->claimedPointsByVillager[$villagerUniqueId]);
    }

    public function claimant(BlockPosition $position): ?string
    {
        return $this->claims[self::key($position)] ?? null;
    }

    public function workstationForVillager(string $villagerUniqueId): ?VillagePointOfInterest
    {
        EntityUuid::validate($villagerUniqueId);
        $key = $this->claimedPointsByVillager[$villagerUniqueId][VillagePointOfInterestType::WORKSTATION->value] ?? null;

        return $key === null ? null : ($this->points[$key] ?? null);
    }

    public function bedForVillager(string $villagerUniqueId): ?VillagePointOfInterest
    {
        EntityUuid::validate($villagerUniqueId);
        $key = $this->claimedPointsByVillager[$villagerUniqueId][VillagePointOfInterestType::BED->value] ?? null;

        return $key === null ? null : ($this->points[$key] ?? null);
    }

    public function nearestAvailableBed(BlockPosition $origin, int $radius): ?VillagePointOfInterest
    {
        return $this->nearestAvailable($origin, VillagePointOfInterestType::BED, $radius);
    }

    public function nearestAvailableWorkstation(
        BlockPosition $origin,
        VillagerProfession $profession,
        int $radius,
    ): ?VillagePointOfInterest {
        if ($radius < 0 || $radius > self::MAXIMUM_SEARCH_RADIUS) {
            throw new InvalidArgumentException('Village point-of-interest search radius is outside its supported range.');
        }
        $maximumDistanceSquared = $radius ** 2;
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($this->points as $key => $point) {
            if ($point->type !== VillagePointOfInterestType::WORKSTATION || $point->profession !== $profession
                || isset($this->claims[$key])) {
                continue;
            }
            $distance = ($point->position->x - $origin->x) ** 2
                + ($point->position->y - $origin->y) ** 2
                + ($point->position->z - $origin->z) ** 2;
            if ($distance <= $maximumDistanceSquared && $distance < $bestDistance) {
                $best = $point;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    private function nearestAvailable(
        BlockPosition $origin,
        VillagePointOfInterestType $type,
        int $radius,
    ): ?VillagePointOfInterest {
        if ($radius < 0 || $radius > self::MAXIMUM_SEARCH_RADIUS) {
            throw new InvalidArgumentException('Village point-of-interest search radius is outside its supported range.');
        }
        $maximumDistanceSquared = $radius ** 2;
        $best = null;
        $bestDistance = PHP_INT_MAX;
        foreach ($this->points as $key => $point) {
            if ($point->type !== $type || isset($this->claims[$key])) {
                continue;
            }
            $distance = ($point->position->x - $origin->x) ** 2
                + ($point->position->y - $origin->y) ** 2
                + ($point->position->z - $origin->z) ** 2;
            if ($distance <= $maximumDistanceSquared && $distance < $bestDistance) {
                $best = $point;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /** @return list<VillagePointOfInterest> */
    public function points(): array
    {
        return array_values($this->points);
    }

    private static function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }
}
