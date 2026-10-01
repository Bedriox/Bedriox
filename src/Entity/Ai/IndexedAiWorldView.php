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
final readonly class IndexedAiWorldView implements AquaticAiWorldView, PlayerIdentityAiWorldView
{
    /** @param Closure(): array<array-key, mixed> $playerPositions */
    public function __construct(
        private EntityRegistry $entities,
        private Closure $playerPositions,
        /** @var null|Closure(AbstractMobEntity, AiPlayerSnapshot): bool */
        private ?Closure $hostileLineOfSight = null,
        /** @var null|Closure(string, Position): bool */
        private ?Closure $waterAt = null,
        /** @var null|Closure(AbstractMobEntity): bool */
        private ?Closure $touchingWater = null,
    ) {}

    public function isTouchingWater(AbstractMobEntity $entity): bool
    {
        return $this->touchingWater !== null && ($this->touchingWater)($entity);
    }

    public function isWaterAt(string $worldName, Position $position): bool
    {
        return $this->waterAt !== null && ($this->waterAt)($worldName, $position);
    }

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
        return $this->nearestPlayerMatching($entity, $radius);
    }

    public function playerByIdentity(string $playerId): ?AiPlayerSnapshot
    {
        if ($playerId === '' || strlen($playerId) > 128 || preg_match('//u', $playerId) !== 1) {
            throw new InvalidArgumentException('AI player identity must be valid UTF-8 and bounded.');
        }
        foreach ($this->playersWithoutEntityContext() as $player) {
            if ($player->playerId === $playerId) {
                return $player;
            }
        }

        return null;
    }

    /** @param array<mixed> $itemIdentifiers */
    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        if (!array_is_list($itemIdentifiers) || $itemIdentifiers === [] || count($itemIdentifiers) > 16) {
            throw new InvalidArgumentException('AI held-item query identifiers must be a non-empty bounded list.');
        }
        foreach ($itemIdentifiers as $itemIdentifier) {
            if (!is_string($itemIdentifier) || strlen($itemIdentifier) > 128
                || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $itemIdentifier) !== 1) {
                throw new InvalidArgumentException('AI held-item query identifier must be canonical and bounded.');
            }
        }

        return $this->nearestPlayerMatching(
            $entity,
            $radius,
            static fn(AiPlayerSnapshot $player): bool => in_array($player->heldItemIdentifier, $itemIdentifiers, true),
        );
    }

    /** @param null|Closure(AiPlayerSnapshot): bool $filter */
    private function nearestPlayerMatching(AbstractMobEntity $entity, float $radius, ?Closure $filter = null): ?AiPlayerSnapshot
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
            if ($filter !== null && !$filter($player)) {
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

    /** @return list<AiPlayerSnapshot> */
    private function playersWithoutEntityContext(): array
    {
        $values = ($this->playerPositions)();
        if (count($values) > 1_024) {
            throw new InvalidArgumentException('AI player-position snapshot exceeds its supported bound.');
        }
        $players = [];
        foreach ($values as $identifier => $value) {
            $players[] = match (true) {
                $value instanceof AiPlayerSnapshot => $value,
                $value instanceof Position => throw new InvalidArgumentException(
                    'Identity lookup requires complete AI player snapshots.',
                ),
                default => throw new InvalidArgumentException(
                    'AI player-position snapshot contains an invalid value.',
                ),
            };
        }

        return $players;
    }
}
