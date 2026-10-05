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
use InvalidArgumentException;

/**
 * Lightweight identity for one loaded generation of a world.
 *
 * The server reuses one handle instance for a loaded world. This value never
 * owns chunks, entities, storage, generators, or other runtime state, so a
 * retained plugin reference cannot keep an unloaded world alive.
 */
final readonly class World
{
    /** @var list<WorldDimension> */
    private array $dimensions;

    /** @param list<WorldDimension> $dimensions */
    public function __construct(
        private string $id,
        private int $loadGeneration,
        private ?WorldActions $actions = null,
        array $dimensions = [WorldDimension::OVERWORLD, WorldDimension::NETHER, WorldDimension::END],
    ) {
        if (preg_match('/^[a-z0-9][a-z0-9._-]{0,63}$/D', $id) !== 1 || $id === '.' || $id === '..') {
            throw new InvalidArgumentException('World ID must be a canonical lowercase identifier of 1-64 characters.');
        }
        if ($loadGeneration < 1) {
            throw new InvalidArgumentException('World load generation must be positive.');
        }
        if ($dimensions === [] || !in_array(WorldDimension::OVERWORLD, $dimensions, true)) {
            throw new InvalidArgumentException('A world must contain an Overworld dimension.');
        }
        $unique = [];
        foreach ($dimensions as $dimension) {
            if (isset($unique[$dimension->value])) {
                throw new InvalidArgumentException('World dimensions must be unique.');
            }
            $unique[$dimension->value] = $dimension;
        }
        $this->dimensions = array_values($unique);
    }

    public function id(): string
    {
        return $this->id;
    }

    public function loadGeneration(): int
    {
        return $this->loadGeneration;
    }

    /** @return list<WorldDimension> */
    public function getDimensions(): array
    {
        return $this->dimensions;
    }

    public function hasDimension(WorldDimension $dimension): bool
    {
        return in_array($dimension, $this->dimensions, true);
    }

    /** True only for the same logical world and the same loaded runtime generation. */
    public function isSameLoad(self $other): bool
    {
        return $this->id === $other->id && $this->loadGeneration === $other->loadGeneration;
    }

    /** True for the same logical world even if the other handle belongs to an older load. */
    public function isSameWorld(self $other): bool
    {
        return $this->id === $other->id;
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
