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
use LogicException;

/** @internal Server-owned authoritative action path attached to one loaded-world handle. */
final readonly class WorldActions
{
    /**
     * @param Closure(World, BlockPosition): Block                         $getBlock
     * @param Closure(World, BlockPosition, string): void                  $setBlock
     * @param Closure(World, Position, Particle, list<Player>|null): void $spawnParticle
     * @param Closure(World): WeatherState                              $getWeather
     * @param Closure(World, WeatherState): bool                        $setWeather
     */
    public function __construct(
        private Closure $getBlock,
        private Closure $setBlock,
        private Closure $spawnParticle,
        private ?Closure $getWeather = null,
        private ?Closure $setWeather = null,
    ) {}

    public static function unavailable(): self
    {
        $unavailable = static function (): never {
            throw new LogicException('This world handle is not attached to an authoritative runtime.');
        };

        return new self($unavailable, $unavailable, $unavailable, $unavailable, $unavailable);
    }

    public function getBlock(World $world, BlockPosition $position): Block
    {
        return ($this->getBlock)($world, $position);
    }

    public function setBlock(World $world, BlockPosition $position, string $identifier): void
    {
        ($this->setBlock)($world, $position, $identifier);
    }

    /** @param list<Player>|null $players */
    public function spawnParticle(World $world, Position $position, Particle $particle, ?array $players): void
    {
        ($this->spawnParticle)($world, $position, $particle, $players);
    }

    public function getWeather(World $world): WeatherState
    {
        $action = $this->getWeather;
        if ($action === null) {
            throw new LogicException('This world handle is not attached to an authoritative runtime.');
        }

        return $action($world);
    }

    public function setWeather(World $world, WeatherState $weather): bool
    {
        $action = $this->setWeather;
        if ($action === null) {
            throw new LogicException('This world handle is not attached to an authoritative runtime.');
        }

        return $action($world, $weather);
    }
}
