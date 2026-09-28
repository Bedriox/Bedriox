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

namespace Bedriox\Server\Entity\Ai;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntitySpatialIndex;
use Bedriox\Server\Simulation\Position;
use Closure;
use InvalidArgumentException;

/** Spatial-index-backed AI view with a compact player-position supplier. */
final readonly class IndexedAiWorldView implements TargetAwareAiWorldView
{
    /** @param Closure(): array<array-key, mixed> $playerPositions */
    public function __construct(
        private EntityRegistry $entities,
        private Closure $playerPositions,
        /** @var null|Closure(AbstractMobEntity, AiPlayerSnapshot): bool */
        private ?Closure $hostileLineOfSight = null,
    ) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->entities->nearby(
                $entity->getWorldName(),
                $entity->internalPosition(),
                $radius,
                min(EntitySpatialIndex::MAX_QUERY_RESULTS, $limit + 1),
            ),
            static fn(AbstractEntity $candidate): bool => $candidate !== $entity,
        )), 0, $limit);
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        $origin = $entity->internalPosition();
        $nearestDistance = null;
        foreach ($this->players($entity) as $player) {
            if ($player->worldName !== $entity->getWorldName()
                || ($entity->getCategory() === EntityCategory::MONSTER && !$player->damageable)) {
                continue;
            }
            $distance = $player->distanceSquaredTo($origin);
            if ($distance <= 256.0 ** 2 && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearestDistance = $distance;
            }
        }

        return $nearestDistance;
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        if (!is_finite($radius) || $radius <= 0.0 || $radius > 256.0) {
            throw new InvalidArgumentException('AI player query radius is outside its supported bounds.');
        }
        $origin = $entity->internalPosition();
        $radiusSquared = $radius * $radius;
        $nearest = null;
        $nearestDistance = null;
        foreach ($this->players($entity) as $player) {
            if ($player->worldName !== $entity->getWorldName()) {
                continue;
            }
            if ($entity->getCategory() === EntityCategory::MONSTER && !$player->damageable) {
                continue;
            }
            $distance = $player->distanceSquaredTo($origin);
            if ($distance > $radiusSquared
                || ($nearestDistance !== null && ($distance > $nearestDistance
                    || ($distance === $nearestDistance && $nearest !== null
                        && strcmp($player->playerId, $nearest->playerId) >= 0)))) {
                continue;
            }
            if ($entity->getCategory() === EntityCategory::MONSTER
                && $this->hostileLineOfSight !== null
                && !($this->hostileLineOfSight)($entity, $player)) {
                continue;
            }
            $nearest = $player;
            $nearestDistance = $distance;
        }

        return $nearest;
    }

    /** @return list<AiPlayerSnapshot> */
    private function players(AbstractMobEntity $entity): array
    {
        $values = ($this->playerPositions)();
        if (count($values) > 1_024) {
            throw new InvalidArgumentException('AI player-position snapshot exceeds its supported bound.');
        }
        $players = [];
        foreach ($values as $identifier => $value) {
            $players[] = match (true) {
                $value instanceof AiPlayerSnapshot => $value,
                $value instanceof Position => new AiPlayerSnapshot((string) $identifier, $entity->getWorldName(), $value),
                default => throw new InvalidArgumentException(
                    'AI player-position snapshot contains an invalid value.',
                ),
            };
        }

        return $players;
    }
}
