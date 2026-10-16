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
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Simulation\Position;
use Closure;
use InvalidArgumentException;

/** Spatial-index-backed AI view with a compact, explicitly refreshed player snapshot. */
final class IndexedAiWorldView implements AquaticAiWorldView, EndermanAwareAiWorldView, NavigatingAiWorldView
{
    private const int MAXIMUM_PLAYERS = 1_024;

    private const int PLAYER_CELL_SIZE = 64;

    /** @var array<string, array<int, array<int, list<AiPlayerSnapshot>>>> */
    private array $playersByWorldCell = [];

    /** @var array<string, AiPlayerSnapshot> */
    private array $playersByIdentity = [];

    /** @var array<array-key, Position> */
    private array $legacyPositions = [];

    private bool $playerSnapshotLoaded = false;

    /** @param Closure(): array<array-key, mixed> $playerPositions */
    public function __construct(
        private readonly EntityRegistry $entities,
        private readonly Closure $playerPositions,
        /** @var null|Closure(AbstractMobEntity, AiPlayerSnapshot): bool */
        private readonly ?Closure $hostileLineOfSight = null,
        /** @var null|Closure(string, Position): bool */
        private readonly ?Closure $waterAt = null,
        /** @var null|Closure(AbstractMobEntity): bool */
        private readonly ?Closure $touchingWater = null,
        /** @var null|Closure(AbstractMobEntity, Position, int): Position */
        private readonly ?Closure $navigationWaypoint = null,
    ) {}

    /** Rebuilds the bounded immutable player view once after authoritative player state changes for a tick. */
    public function refreshPlayerSnapshot(): void
    {
        $values = ($this->playerPositions)();
        if (count($values) > self::MAXIMUM_PLAYERS) {
            throw new InvalidArgumentException('AI player-position snapshot exceeds its supported bound.');
        }

        $playersByWorldCell = [];
        $playersByIdentity = [];
        $legacyPositions = [];
        foreach ($values as $identifier => $value) {
            if ($value instanceof Position) {
                $legacyPositions[$identifier] = $value;
                continue;
            }
            if (!$value instanceof AiPlayerSnapshot) {
                throw new InvalidArgumentException('AI player-position snapshot contains an invalid value.');
            }

            $cellX = self::cellCoordinate($value->position->x);
            $cellZ = self::cellCoordinate($value->position->z);
            $playersByWorldCell[$value->worldName][$cellX][$cellZ][] = $value;
            $playersByIdentity[$value->playerId] ??= $value;
        }

        $this->playersByWorldCell = $playersByWorldCell;
        $this->playersByIdentity = $playersByIdentity;
        $this->legacyPositions = $legacyPositions;
        $this->playerSnapshotLoaded = true;
    }

    public function navigationWaypoint(AbstractMobEntity $entity, Position $target, int $tick): Position
    {
        return $this->navigationWaypoint === null
            ? $target
            : ($this->navigationWaypoint)($entity, $target, $tick);
    }

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
        foreach ($this->playersNear($entity, 256.0) as $player) {
            if ($entity->getCategory() === EntityCategory::MONSTER && !$player->damageable) {
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
        $this->loadPlayerSnapshotIfNeeded();
        if ($this->legacyPositions !== [] && !isset($this->playersByIdentity[$playerId])) {
            throw new InvalidArgumentException('Identity lookup requires complete AI player snapshots.');
        }

        return $this->playersByIdentity[$playerId] ?? null;
    }

    public function nearestPlayerProvokingEnderman(
        EndermanEntity $enderman,
        float $radius,
    ): ?AiPlayerSnapshot {
        return $this->nearestPlayerMatching(
            $enderman,
            $radius,
            static fn(AiPlayerSnapshot $player): bool => !$player->wearingEndermanProtectiveHeadwear
                && self::playerIsLookingAtEnderman($player, $enderman),
        );
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
        $requiresLineOfSight = $entity->getCategory() === EntityCategory::MONSTER
            && $this->hostileLineOfSight !== null;
        $nearest = null;
        $nearestDistance = null;
        $candidates = [];
        foreach ($this->playersNear($entity, $radius) as $player) {
            if ($filter !== null && !$filter($player)) {
                continue;
            }
            if ($entity->getCategory() === EntityCategory::MONSTER && !$player->damageable) {
                continue;
            }
            $distance = $player->distanceSquaredTo($origin);
            if ($distance > $radiusSquared) {
                continue;
            }
            if (!$requiresLineOfSight) {
                if ($nearestDistance === null || $distance < $nearestDistance
                    || ($distance === $nearestDistance && $nearest !== null
                        && strcmp($player->playerId, $nearest->playerId) < 0)) {
                    $nearest = $player;
                    $nearestDistance = $distance;
                }
                continue;
            }
            $candidates[] = [$distance, $player];
        }
        if (!$requiresLineOfSight) {
            return $nearest;
        }
        usort($candidates, static fn(array $left, array $right): int =>
            $left[0] <=> $right[0] ?: strcmp($left[1]->playerId, $right[1]->playerId));

        foreach ($candidates as [, $player]) {
            if (($this->hostileLineOfSight)($entity, $player)) {
                return $player;
            }
        }

        return null;
    }

    /** @return iterable<AiPlayerSnapshot> */
    private function playersNear(AbstractMobEntity $entity, float $radius): iterable
    {
        $this->loadPlayerSnapshotIfNeeded();
        $origin = $entity->internalPosition();
        $worldCells = $this->playersByWorldCell[$entity->getWorldName()] ?? [];
        $minimumCellX = self::cellCoordinate($origin->x - $radius);
        $maximumCellX = self::cellCoordinate($origin->x + $radius);
        $minimumCellZ = self::cellCoordinate($origin->z - $radius);
        $maximumCellZ = self::cellCoordinate($origin->z + $radius);
        for ($cellX = $minimumCellX; $cellX <= $maximumCellX; ++$cellX) {
            $column = $worldCells[$cellX] ?? [];
            for ($cellZ = $minimumCellZ; $cellZ <= $maximumCellZ; ++$cellZ) {
                foreach ($column[$cellZ] ?? [] as $player) {
                    yield $player;
                }
            }
        }
        foreach ($this->legacyPositions as $identifier => $position) {
            yield new AiPlayerSnapshot((string) $identifier, $entity->getWorldName(), $position);
        }
    }

    private function loadPlayerSnapshotIfNeeded(): void
    {
        if (!$this->playerSnapshotLoaded) {
            $this->refreshPlayerSnapshot();
        }
    }

    private static function cellCoordinate(float $coordinate): int
    {
        return (int) floor($coordinate / self::PLAYER_CELL_SIZE);
    }

    private static function playerIsLookingAtEnderman(
        AiPlayerSnapshot $player,
        EndermanEntity $enderman,
    ): bool {
        $fromX = $player->position->x;
        $fromY = $player->position->y + 1.62;
        $fromZ = $player->position->z;
        $target = $enderman->internalPosition();
        $dx = $target->x - $fromX;
        $dy = ($target->y + 2.55) - $fromY;
        $dz = $target->z - $fromZ;
        $distance = hypot(hypot($dx, $dz), $dy);
        if ($distance < 0.001) {
            return true;
        }

        $yaw = deg2rad($player->headYaw);
        $pitch = deg2rad($player->pitch);
        $horizontal = cos($pitch);
        $dot = ((-sin($yaw) * $horizontal) * ($dx / $distance))
            + ((-sin($pitch)) * ($dy / $distance))
            + ((cos($yaw) * $horizontal) * ($dz / $distance));

        return $dot > 1.0 - (0.025 / $distance);
    }
}
