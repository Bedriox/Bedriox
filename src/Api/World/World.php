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

namespace Bedriox\Api\World;

use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Particle\Particle;
use Closure;
use InvalidArgumentException;
use LogicException;

/**
 * Lightweight identity for one dimension of a loaded world generation.
 *
 * The server reuses one handle instance for a loaded world. This value never
 * owns chunks, entities, storage, generators, or other runtime state, so a
 * retained plugin reference cannot keep an unloaded world alive.
 */
final readonly class World
{
    /** @param Closure(World, DimensionType): ?World|null $dimensionResolver */
    public function __construct(
        private string $id,
        private int $loadGeneration,
        private ?WorldActions $actions = null,
        private DimensionType $dimensionType = DimensionType::OVERWORLD,
        private ?Closure $dimensionResolver = null,
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1 || $id === '.' || $id === '..') {
            throw new InvalidArgumentException('World ID must be a canonical lowercase identifier of 1-64 characters.');
        }
        if ($loadGeneration < 1) {
            throw new InvalidArgumentException('World load generation must be positive.');
        }
    }

    public function id(): string
    {
        return $this->id;
    }

    public function loadGeneration(): int
    {
        return $this->loadGeneration;
    }

    public function getDimensionType(): DimensionType
    {
        return $this->dimensionType;
    }

    public function getDimension(DimensionType $dimension): ?self
    {
        if ($dimension === $this->dimensionType) {
            return $this;
        }

        $resolved = ($this->dimensionResolver) !== null
            ? ($this->dimensionResolver)($this, $dimension)
            : null;
        if ($resolved === null) {
            return null;
        }
        if ($resolved->id !== $this->id
            || $resolved->loadGeneration !== $this->loadGeneration
            || $resolved->dimensionType !== $dimension) {
            throw new LogicException('Dimension resolver returned a world outside the requested loaded world.');
        }

        return $resolved;
    }

    /** True only for the same logical world and the same loaded runtime generation. */
    public function isSameLoad(self $other): bool
    {
        return $this->id === $other->id
            && $this->loadGeneration === $other->loadGeneration
            && $this->dimensionType === $other->dimensionType;
    }

    /** True for the same logical world even if the other handle belongs to an older load. */
    public function isSameWorld(self $other): bool
    {
        return $this->id === $other->id && $this->dimensionType === $other->dimensionType;
    }

    public function getBlock(BlockPosition $position): Block
    {
        return ($this->actions ?? WorldActions::unavailable())->getBlock($this, $position);
    }

    public function setBlock(BlockPosition $position, string $identifier): void
    {
        ($this->actions ?? WorldActions::unavailable())->setBlock($this, $position, $identifier);
    }

    public function getWeather(): WeatherState
    {
        return ($this->actions ?? WorldActions::unavailable())->getWeather($this);
    }

    /** Requests an authoritative plugin-caused weather transition. */
    public function setWeather(WeatherState $weather): bool
    {
        return ($this->actions ?? WorldActions::unavailable())->setWeather($this, $weather);
    }

    /**
     * Requests a presentation-only particle for viewers of the containing chunk.
     *
     * @param array<array-key, mixed>|null $players Optional audience; null selects every eligible world viewer.
     */
    public function spawnParticle(Position $position, Particle $particle, ?array $players = null): void
    {
        $audience = null;
        if ($players !== null) {
            $audience = [];
            foreach ($players as $player) {
                if (!$player instanceof Player) {
                    throw new InvalidArgumentException('Particle audience must contain Player instances.');
                }
                $audience[] = $player;
            }
        }
        ($this->actions ?? WorldActions::unavailable())->spawnParticle($this, $position, $particle, $audience);
    }
}
