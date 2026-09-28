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

namespace Bedriox\Server\Entity;

use Bedriox\Server\Simulation\Position;
use Closure;
use InvalidArgumentException;
use LogicException;
use OverflowException;

/**
 * Bounded world/chunk/section index for authoritative entities.
 *
 * The index deliberately owns no entity lifecycle. EntityRegistry is the
 * authoritative owner and uses this class to make spatial queries bounded and
 * deterministic.
 */
final class EntitySpatialIndex
{
    public const int DEFAULT_CAPACITY = 4_096;
    public const int MAX_CAPACITY = 65_536;
    public const int MAX_QUERY_RESULTS = 4_096;
    public const float MAX_QUERY_RADIUS = 256.0;

    private const int CHUNK_SIZE = 16;
    private const int SECTION_HEIGHT = 16;

    /** @var array<int, AbstractEntity> */
    private array $entities = [];

    /** @var array<int, array{world: string, bucket: string}> */
    private array $locations = [];

    /** @var array<string, array<string, array<int, AbstractEntity>>> */
    private array $buckets = [];

    /** @var array<string, array<string, array{chunkX: int, sectionY: int, chunkZ: int}>> */
    private array $bucketCoordinates = [];

    public function __construct(private readonly int $capacity = self::DEFAULT_CAPACITY)
    {
        if ($capacity < 1 || $capacity > self::MAX_CAPACITY) {
            throw new InvalidArgumentException('Entity spatial-index capacity is outside its supported range.');
        }
    }

    public function count(): int
    {
        return count($this->entities);
    }

    public function add(AbstractEntity $entity): void
    {
        if (count($this->entities) >= $this->capacity) {
            throw new OverflowException('Entity spatial-index capacity is exhausted.');
        }
        $runtimeId = $entity->getRuntimeId();
        if (isset($this->entities[$runtimeId])) {
            throw new LogicException('Entity runtime ID is already indexed.');
        }

        $this->entities[$runtimeId] = $entity;
        $this->attach($entity);
    }

    public function replace(AbstractEntity $entity): void
    {
        $runtimeId = $entity->getRuntimeId();
        $existing = $this->entities[$runtimeId] ?? null;
        if ($existing === null) {
            throw new InvalidArgumentException('Cannot replace an entity which is not indexed.');
        }
        if ($existing->getUniqueId() !== $entity->getUniqueId()) {
            throw new LogicException('Entity replacement must preserve its unique identity.');
        }

        $this->detach($runtimeId);
        $this->entities[$runtimeId] = $entity;
        $this->attach($entity);
    }

    public function reindex(AbstractEntity $entity): void
    {
        $runtimeId = $entity->getRuntimeId();
        if (($this->entities[$runtimeId] ?? null) !== $entity) {
            throw new InvalidArgumentException('Only the indexed entity instance can be reindexed.');
        }

        $location = self::location($entity);
        if (($this->locations[$runtimeId] ?? null) === $location) {
            return;
        }

        $this->detach($runtimeId);
        $this->attach($entity);
    }

    public function remove(int $runtimeId): ?AbstractEntity
    {
        $entity = $this->entities[$runtimeId] ?? null;
        if ($entity === null) {
            return null;
        }

        $this->detach($runtimeId);
        unset($this->entities[$runtimeId]);

        return $entity;
    }

    /**
     * @param null|Closure(AbstractEntity): bool $filter
     * @return list<AbstractEntity>
     */
    public function nearby(
        string $worldName,
        Position $center,
        float $radius,
        int $limit = 64,
        ?Closure $filter = null,
    ): array {
        self::validateQuery($worldName, $center, $radius, $limit);
        $worldBuckets = $this->buckets[$worldName] ?? [];
        if ($worldBuckets === []) {
            return [];
        }

        $minimumChunkX = self::chunkCoordinate($center->x - $radius);
        $maximumChunkX = self::chunkCoordinate($center->x + $radius);
        $minimumSectionY = self::sectionCoordinate($center->y - $radius);
        $maximumSectionY = self::sectionCoordinate($center->y + $radius);
        $minimumChunkZ = self::chunkCoordinate($center->z - $radius);
        $maximumChunkZ = self::chunkCoordinate($center->z + $radius);
        $radiusSquared = $radius * $radius;

        /** @var list<array{distance: float, runtimeId: int, entity: AbstractEntity}> $matches */
        $matches = [];
        foreach ($worldBuckets as $bucket => $entities) {
            $coordinates = $this->bucketCoordinates[$worldName][$bucket];
            if ($coordinates['chunkX'] < $minimumChunkX || $coordinates['chunkX'] > $maximumChunkX
                || $coordinates['sectionY'] < $minimumSectionY || $coordinates['sectionY'] > $maximumSectionY
                || $coordinates['chunkZ'] < $minimumChunkZ || $coordinates['chunkZ'] > $maximumChunkZ) {
                continue;
            }
            foreach ($entities as $runtimeId => $entity) {
                if ($filter !== null && !$filter($entity)) {
                    continue;
                }
                $position = $entity->internalPosition();
                $dx = $position->x - $center->x;
                $dy = $position->y - $center->y;
                $dz = $position->z - $center->z;
                $distanceSquared = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
                if ($distanceSquared <= $radiusSquared) {
                    $matches[] = [
                        'distance' => $distanceSquared,
                        'runtimeId' => $runtimeId,
                        'entity' => $entity,
                    ];
                }
            }
        }

        usort($matches, static fn(array $left, array $right): int =>
            ($left['distance'] <=> $right['distance'])
            ?: ($left['runtimeId'] <=> $right['runtimeId']));

        return array_map(
            static fn(array $match): AbstractEntity => $match['entity'],
            array_slice($matches, 0, $limit),
        );
    }

    private function attach(AbstractEntity $entity): void
    {
        $runtimeId = $entity->getRuntimeId();
        $location = self::location($entity);
        $coordinates = self::coordinates($entity->internalPosition());
        $this->locations[$runtimeId] = $location;
        $this->buckets[$location['world']][$location['bucket']][$runtimeId] = $entity;
        $this->bucketCoordinates[$location['world']][$location['bucket']] = $coordinates;
    }

    private function detach(int $runtimeId): void
    {
        $location = $this->locations[$runtimeId] ?? null;
        if ($location === null) {
            throw new LogicException('Indexed entity has no spatial location.');
        }

        unset($this->buckets[$location['world']][$location['bucket']][$runtimeId]);
        if ($this->buckets[$location['world']][$location['bucket']] === []) {
            unset(
                $this->buckets[$location['world']][$location['bucket']],
                $this->bucketCoordinates[$location['world']][$location['bucket']],
            );
        }
        if ($this->buckets[$location['world']] === []) {
            unset($this->buckets[$location['world']], $this->bucketCoordinates[$location['world']]);
        }
        unset($this->locations[$runtimeId]);
    }

    /** @return array{world: string, bucket: string} */
    private static function location(AbstractEntity $entity): array
    {
        $coordinates = self::coordinates($entity->internalPosition());

        return [
            'world' => $entity->getWorldName(),
            'bucket' => self::bucketKey(
                $coordinates['chunkX'],
                $coordinates['sectionY'],
                $coordinates['chunkZ'],
            ),
        ];
    }

    /** @return array{chunkX: int, sectionY: int, chunkZ: int} */
    private static function coordinates(Position $position): array
    {
        return [
            'chunkX' => self::chunkCoordinate($position->x),
            'sectionY' => self::sectionCoordinate($position->y),
            'chunkZ' => self::chunkCoordinate($position->z),
        ];
    }

    private static function chunkCoordinate(float $coordinate): int
    {
        return (int) floor($coordinate / self::CHUNK_SIZE);
    }

    private static function sectionCoordinate(float $coordinate): int
    {
        return (int) floor($coordinate / self::SECTION_HEIGHT);
    }

    private static function bucketKey(int $chunkX, int $sectionY, int $chunkZ): string
    {
        return $chunkX . ':' . $sectionY . ':' . $chunkZ;
    }

    private static function validateQuery(string $worldName, Position $center, float $radius, int $limit): void
    {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('Entity query world name must be valid UTF-8 and bounded.');
        }
        if (!is_finite($center->x) || !is_finite($center->y) || !is_finite($center->z)
            || abs($center->x) > 30_000_000.0 || abs($center->z) > 30_000_000.0
            || abs($center->y) > 2_048.0) {
            throw new InvalidArgumentException('Entity query position must be finite and bounded.');
        }
        if (!is_finite($radius) || $radius < 0.0 || $radius > self::MAX_QUERY_RADIUS
            || $limit < 1 || $limit > self::MAX_QUERY_RESULTS) {
            throw new InvalidArgumentException('Entity spatial query is outside its supported bounds.');
        }
    }
}
