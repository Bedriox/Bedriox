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

use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldTimeRules;

/** Bounded read-only environment queries used by general-entity lifecycle rules. */
final readonly class WorldEntityEnvironment
{
    public const int DAYLIGHT_CHECK_INTERVAL_TICKS = 20;

    public function __construct(
        private World $world,
        private BlockStateRegistry $states,
        private BlockCollisionRegistry $shapes,
        private InternalBlockStateId $air,
        private ?InternalBlockStateId $water = null,
    ) {}

    public function shouldCheckDaylight(AbstractLivingEntity $entity, int $tick): bool
    {
        return $entity->definition()->burnsInDaylight
            && ($tick + $entity->getRuntimeId()) % self::DAYLIGHT_CHECK_INTERVAL_TICKS === 0;
    }

    public function hasBurningDaylightExposure(AbstractLivingEntity $entity): bool
    {
        if ($entity->getWorldName() !== $this->world->metadata->name || !$entity->definition()->burnsInDaylight
            || !$this->isDaylight() || $this->isTouchingWater($entity)) {
            return false;
        }
        return $this->hasSkyExposure($entity);
    }

    public function hasSkyExposure(AbstractLivingEntity $entity): bool
    {
        if ($entity->getWorldName() !== $this->world->metadata->name) {
            return false;
        }
        $position = $entity->internalPosition();
        $chunk = $this->loadedChunkAt($position->x, $position->z);
        if ($chunk === null) {
            return false;
        }
        $localX = self::localCoordinate((int) floor($position->x));
        $localZ = self::localCoordinate((int) floor($position->z));
        $headY = max(
            Chunk::MIN_Y,
            min(Chunk::MAX_Y, (int) floor($position->y + $entity->definition()->height - 0.000_001)),
        );
        for ($y = $headY; $y <= Chunk::MAX_Y; ++$y) {
            if ($this->blocksSkyLight($chunk->blockStateAt($localX, $y, $localZ))) {
                return false;
            }
        }

        return true;
    }

    public function isTouchingWater(AbstractLivingEntity $entity): bool
    {
        if ($entity->getWorldName() !== $this->world->metadata->name) {
            return false;
        }
        $position = $entity->internalPosition();
        $chunk = $this->loadedChunkAt($position->x, $position->z);
        if ($chunk === null) {
            return false;
        }
        $x = self::localCoordinate((int) floor($position->x));
        $z = self::localCoordinate((int) floor($position->z));
        $feetY = max(Chunk::MIN_Y, min(Chunk::MAX_Y, (int) floor($position->y + 0.001)));
        $headY = max(
            Chunk::MIN_Y,
            min(Chunk::MAX_Y, (int) floor($position->y + $entity->definition()->height - 0.000_001)),
        );

        return $this->isWater($chunk->blockStateAt($x, $feetY, $z))
            || ($headY !== $feetY && $this->isWater($chunk->blockStateAt($x, $headY, $z)));
    }

    private function isDaylight(): bool
    {
        $time = WorldTimeRules::timeOfDay($this->world->time());

        return $time < 13_000 || $time > 23_000;
    }

    private function loadedChunkAt(float $x, float $z): ?Chunk
    {
        return $this->world->loadedChunk(new ChunkPosition(
            (int) floor($x / 16.0),
            (int) floor($z / 16.0),
        ));
    }

    private function isWater(InternalBlockStateId $state): bool
    {
        return ($this->water !== null && $state->value === $this->water->value)
            || $this->states->state($state)->identifier() === 'minecraft:water';
    }

    private function blocksSkyLight(InternalBlockStateId $state): bool
    {
        if ($state->value === $this->air->value || $this->isWater($state)) {
            return false;
        }
        $shape = $this->shapes->find($state);
        if ($shape !== null) {
            return !$shape->isEmpty();
        }

        return !in_array($this->states->state($state)->identifier(), ['minecraft:air', 'minecraft:water'], true);
    }

    private static function localCoordinate(int $coordinate): int
    {
        return (($coordinate % 16) + 16) % 16;
    }
}
