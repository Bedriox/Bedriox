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
use Bedriox\Api\Entity\EntityType;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\World;
use InvalidArgumentException;
use RuntimeException;

/** Read-only adapter from the authoritative world/entity models to bounded natural-spawn queries. */
final class WorldNaturalSpawnEnvironment implements NaturalSpawnEnvironment
{
    /** @var list<NaturalSpawnPlayer> */
    private array $players = [];

    private int $worldTime = 0;

    public function __construct(
        private readonly World $world,
        private readonly EntityRegistry $entities,
        private readonly EntityDefinitionRegistry $definitions,
        private readonly BlockStateRegistry $states,
        private readonly BlockCollisionRegistry $shapes,
        private readonly BlockCollisionQuery $collisions,
        private readonly InternalBlockStateId $air,
        private readonly ?InternalBlockStateId $water = null,
        private readonly ?InternalBlockStateId $lava = null,
    ) {}

    /** @param array<int, mixed> $players */
    public function updateContext(array $players): void
    {
        if (!array_is_list($players) || count($players) > NaturalSpawnCandidatePlanner::MAXIMUM_PLAYERS) {
            throw new InvalidArgumentException('Natural-spawn world context is invalid or oversized.');
        }
        foreach ($players as $player) {
            if (!$player instanceof NaturalSpawnPlayer) {
                throw new InvalidArgumentException('Natural-spawn world context contains an invalid player.');
            }
        }
        $this->players = $players;
        $this->worldTime = $this->world->time();
    }

    public function isChunkLoaded(string $worldName, ChunkPosition $chunk): bool
    {
        return $this->isWorld($worldName) && $this->world->hasLoadedChunk($chunk);
    }

    public function isChunkStable(string $worldName, ChunkPosition $chunk): bool
    {
        return $this->isChunkLoaded($worldName, $chunk)
            && $this->world->chunk($chunk)->finalizationState === ChunkFinalizationState::Done;
    }

    public function dimension(string $worldName): string
    {
        $this->requireWorld($worldName);

        return 'minecraft:overworld';
    }

    public function biome(string $worldName, Position $position): string
    {
        $chunk = $this->loadedChunkAt($worldName, $position);
        if ($chunk === null) {
            throw new RuntimeException('Natural-spawn biome was requested from an unloaded chunk.');
        }

        return $chunk->biomeAt(
            self::localCoordinate((int) floor($position->x)),
            max(Chunk::MIN_Y, min(Chunk::MAX_Y, (int) floor($position->y))),
            self::localCoordinate((int) floor($position->z)),
        )->identifier;
    }

    public function heightAt(string $worldName, float $x, float $z): ?float
    {
        $position = new Position($x, 0.0, $z);
        $chunk = $this->loadedChunkAt($worldName, $position);
        if ($chunk === null || $chunk->finalizationState !== ChunkFinalizationState::Done) {
            return null;
        }
        $localX = self::localCoordinate((int) floor($x));
        $localZ = self::localCoordinate((int) floor($z));
        for ($y = Chunk::MAX_Y; $y >= Chunk::MIN_Y; --$y) {
            $state = $chunk->blockStateAt($localX, $y, $localZ);
            $surface = $this->collisionSurface($state);
            if ($surface !== null) {
                return $y + $surface;
            }
        }

        return null;
    }

    public function medium(string $worldName, Position $position): NaturalSpawnMedium
    {
        $chunk = $this->loadedChunkAt($worldName, $position);
        if ($chunk === null) {
            return NaturalSpawnMedium::AIR;
        }
        $x = self::localCoordinate((int) floor($position->x));
        $z = self::localCoordinate((int) floor($position->z));
        $cellY = max(Chunk::MIN_Y, min(Chunk::MAX_Y, (int) floor($position->y)));
        $state = $chunk->blockStateAt($x, $cellY, $z);
        if ($this->water !== null && $state->value === $this->water->value) {
            return NaturalSpawnMedium::WATER;
        }
        if ($this->lava !== null && $state->value === $this->lava->value) {
            return NaturalSpawnMedium::LAVA;
        }
        $supportY = (int) floor($position->y - 0.000_001);
        if ($supportY < Chunk::MIN_Y || $supportY > Chunk::MAX_Y) {
            return NaturalSpawnMedium::AIR;
        }
        $support = $this->collisionSurface($chunk->blockStateAt($x, $supportY, $z));

        return $support !== null && $supportY + $support >= $position->y - 0.000_001
            ? NaturalSpawnMedium::GROUND
            : NaturalSpawnMedium::AIR;
    }

    public function lightLevel(string $worldName, Position $position): int
    {
        $chunk = $this->loadedChunkAt($worldName, $position);
        if ($chunk === null) {
            return 0;
        }
        $x = self::localCoordinate((int) floor($position->x));
        $z = self::localCoordinate((int) floor($position->z));
        for ($y = max(Chunk::MIN_Y, (int) ceil($position->y)); $y <= Chunk::MAX_Y; ++$y) {
            if ($this->collisionSurface($chunk->blockStateAt($x, $y, $z)) !== null) {
                return 0;
            }
        }

        $dayTime = $this->worldTime % 24_000;

        return $dayTime >= 13_000 && $dayTime <= 23_000 ? 4 : 15;
    }

    public function isCollisionFree(string $worldName, EntityType $type, Position $position): bool
    {
        $this->requireWorld($worldName);
        $definition = $this->definitions->require($type)->definition;
        $halfWidth = $definition->width / 2.0;

        return !$this->collisions->hasCollision(new AxisAlignedBox(
            $position->x - $halfWidth,
            $position->y,
            $position->z - $halfWidth,
            $position->x + $halfWidth,
            $position->y + $definition->height,
            $position->z + $halfWidth,
        ));
    }

    public function nearestPlayerDistanceSquared(string $worldName, Position $position): ?float
    {
        $nearest = null;
        foreach ($this->players as $player) {
            if ($player->worldName !== $worldName) {
                continue;
            }
            $distance = self::distanceSquared($position, $player->position);
            $nearest = $nearest === null ? $distance : min($nearest, $distance);
        }

        return $nearest;
    }

    public function worldSpawnDistanceSquared(string $worldName, Position $position): float
    {
        $this->requireWorld($worldName);
        $spawn = $this->world->spawn();

        return self::distanceSquared($position, new Position($spawn->x + 0.5, $spawn->y, $spawn->z + 0.5));
    }

    public function categoryCount(string $worldName, EntityCategory $category): int
    {
        $this->requireWorld($worldName);

        return $this->entities->countByCategory($category);
    }

    public function localCategoryDensity(
        string $worldName,
        ChunkPosition $chunk,
        EntityCategory $category,
    ): int {
        $center = new Position(($chunk->x * 16) + 8.0, 128.0, ($chunk->z * 16) + 8.0);
        $count = 0;
        foreach ($this->entities->nearby($worldName, $center, 256.0, 4_096, $category) as $entity) {
            $position = $entity->internalPosition();
            if ((int) floor($position->x / 16.0) === $chunk->x
                && (int) floor($position->z / 16.0) === $chunk->z) {
                ++$count;
            }
        }

        return $count;
    }

    private function loadedChunkAt(string $worldName, Position $position): ?Chunk
    {
        if (!$this->isWorld($worldName)) {
            return null;
        }
        $chunkPosition = new ChunkPosition(
            (int) floor($position->x / 16.0),
            (int) floor($position->z / 16.0),
        );

        return $this->world->hasLoadedChunk($chunkPosition) ? $this->world->chunk($chunkPosition) : null;
    }

    private function collisionSurface(InternalBlockStateId $state): ?float
    {
        $shape = $this->shapes->find($state);
        if ($shape !== null) {
            return $shape->highestY();
        }
        if ($state->value === $this->air->value
            || ($this->water !== null && $state->value === $this->water->value)
            || ($this->lava !== null && $state->value === $this->lava->value)) {
            return null;
        }

        $identifier = $this->states->state($state)->identifier();

        return in_array($identifier, ['minecraft:air', 'minecraft:water', 'minecraft:lava'], true) ? null : 1.0;
    }

    private function isWorld(string $worldName): bool
    {
        return $worldName === $this->world->metadata->name;
    }

    private function requireWorld(string $worldName): void
    {
        if (!$this->isWorld($worldName)) {
            throw new InvalidArgumentException('Natural-spawn query targets another world.');
        }
    }

    private static function localCoordinate(int $coordinate): int
    {
        return (($coordinate % 16) + 16) % 16;
    }

    private static function distanceSquared(Position $left, Position $right): float
    {
        $dx = $left->x - $right->x;
        $dy = $left->y - $right->y;
        $dz = $left->z - $right->z;

        return ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
    }
}
