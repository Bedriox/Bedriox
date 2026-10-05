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
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldTimeRules;

/** Bounded read-only environment queries used by general-entity lifecycle rules. */
final readonly class WorldEntityEnvironment
{
    public const int DAYLIGHT_CHECK_INTERVAL_TICKS = 20;
    private const int MAXIMUM_WATER_COLUMN_SCAN = 8;

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
            min(Chunk::MAX_Y, (int) floor($position->y + $entity->collisionHeight() - 0.000_001)),
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
            min(Chunk::MAX_Y, (int) floor($position->y + $entity->collisionHeight() - 0.000_001)),
        );

        return $this->isWater($chunk->blockStateAt($x, $feetY, $z))
            || ($headY !== $feetY && $this->isWater($chunk->blockStateAt($x, $headY, $z)));
    }

    public function isWaterAt(string $worldName, Position $position): bool
    {
        if ($worldName !== $this->world->metadata->name) {
            return false;
        }
        $y = (int) floor($position->y);
        if ($y < Chunk::MIN_Y || $y > Chunk::MAX_Y) {
            return false;
        }
        $chunk = $this->loadedChunkAt($position->x, $position->z);
        if ($chunk === null) {
            return false;
        }

        return $this->isWater($chunk->blockStateAt(
            self::localCoordinate((int) floor($position->x)),
            $y,
            self::localCoordinate((int) floor($position->z)),
        ));
    }

    public function isWaterSupporting(AbstractEntity $entity): bool
    {
        return $this->waterSurfaceY($entity) !== null;
    }

    public function waterSurfaceY(AbstractEntity $entity): ?float
    {
        return $this->fluidSurfaceY($entity, FluidType::WATER);
    }

    public function lavaSurfaceY(AbstractEntity $entity): ?float
    {
        return $this->fluidSurfaceY($entity, FluidType::LAVA);
    }

    private function fluidSurfaceY(AbstractEntity $entity, FluidType $type): ?float
    {
        if ($entity->getWorldName() !== $this->world->metadata->name) {
            return null;
        }
        $position = $entity->internalPosition();
        $offset = max(0.0, ($entity->collisionWidth() / 2.0) - 0.05);
        $minimumY = max(Chunk::MIN_Y, (int) floor($position->y - 0.2));
        $maximumY = min(Chunk::MAX_Y, (int) floor($position->y + $entity->collisionHeight()));
        $surface = null;
        $sampledColumns = [];
        foreach ([
            [$position->x, $position->z],
            [$position->x - $offset, $position->z - $offset],
            [$position->x - $offset, $position->z + $offset],
            [$position->x + $offset, $position->z - $offset],
            [$position->x + $offset, $position->z + $offset],
        ] as [$x, $z]) {
            $blockX = (int) floor($x);
            $blockZ = (int) floor($z);
            $columnKey = $blockX . ':' . $blockZ;
            if (isset($sampledColumns[$columnKey])) {
                continue;
            }
            $sampledColumns[$columnKey] = true;
            for ($y = $minimumY; $y <= $maximumY; ++$y) {
                $state = $this->world->loadedBlockStateAt($blockX, $y, $blockZ);
                if ($state === null) {
                    continue;
                }
                $fluid = FluidState::fromCanonical($this->states->state($state));
                if ($fluid?->type === $type) {
                    $surface = max(
                        $surface ?? -INF,
                        $this->connectedFluidSurfaceY($blockX, $y, $blockZ, $fluid, $type),
                    );
                    break;
                }
            }
        }

        return $surface;
    }

    private function connectedFluidSurfaceY(
        int $x,
        int $fluidY,
        int $z,
        FluidState $fluidState,
        FluidType $type,
    ): float {
        $surface = $fluidY + $fluidState->height();
        $maximumY = min(Chunk::MAX_Y, $fluidY + self::MAXIMUM_WATER_COLUMN_SCAN);
        for ($y = $fluidY + 1; $y <= $maximumY; ++$y) {
            $state = $this->world->loadedBlockStateAt($x, $y, $z);
            if ($state === null) {
                break;
            }
            $fluid = FluidState::fromCanonical($this->states->state($state));
            if ($fluid?->type !== $type) {
                break;
            }
            $surface = $y + $fluid->height();
        }

        return $surface;
    }

    public function isSubmerged(AbstractLivingEntity $entity): bool
    {
        if ($entity->getWorldName() !== $this->world->metadata->name) {
            return false;
        }
        $position = $entity->internalPosition();
        $headY = (int) floor($position->y + ($entity->collisionHeight() * 0.85));
        if ($headY < Chunk::MIN_Y || $headY > Chunk::MAX_Y) {
            return false;
        }
        $chunk = $this->loadedChunkAt($position->x, $position->z);
        if ($chunk === null) {
            return false;
        }

        return $this->isWater($chunk->blockStateAt(
            self::localCoordinate((int) floor($position->x)),
            $headY,
            self::localCoordinate((int) floor($position->z)),
        ));
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
