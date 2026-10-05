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

use InvalidArgumentException;

/** Immutable snapshot of one loaded world's public state. */
final readonly class WorldInfo
{
    public function __construct(
        public World $world,
        public string $displayName,
        public string $generator,
        public int $seed,
        public WorldDifficulty $difficulty,
        public int $time,
        public Position $spawn,
        public int $playerCount,
        /** @var list<WorldDimension> */
        public array $dimensions = [WorldDimension::OVERWORLD, WorldDimension::NETHER, WorldDimension::END],
        /** @var array<string, Position> Canonical dimension identifier => spawn */
        public array $dimensionSpawns = [],
    ) {
        if ($displayName === '' || strlen($displayName) > 128 || preg_match('//u', $displayName) !== 1) {
            throw new InvalidArgumentException('World display name must be bounded UTF-8.');
        }
        if ($generator === '' || strlen($generator) > 128 || $playerCount < 0) {
            throw new InvalidArgumentException('World information contains invalid bounded values.');
        }
        if ($dimensions === [] || !in_array(WorldDimension::OVERWORLD, $dimensions, true)) {
            throw new InvalidArgumentException('World information must include the Overworld dimension.');
        }
        $spawn->validateResolved();
        if (!$spawn->world?->isSameLoad($world)) {
            throw new InvalidArgumentException('World spawn must resolve to the described world generation.');
        }
        foreach ($dimensionSpawns as $identifier => $dimensionSpawn) {
            $dimension = WorldDimension::tryFrom($identifier);
            if ($dimension === null || !in_array($dimension, $dimensions, true)) {
                throw new InvalidArgumentException('Dimension spawn key is not declared by this world.');
            }
            $dimensionSpawn->validateResolved();
            if (!$dimensionSpawn->world?->isSameLoad($world) || $dimensionSpawn->dimension !== $dimension) {
                throw new InvalidArgumentException('Dimension spawn must resolve to its declared world dimension.');
            }
        }
    }

    public function spawnFor(WorldDimension $dimension): Position
    {
        if ($dimension === WorldDimension::OVERWORLD) {
            return $this->dimensionSpawns[$dimension->value] ?? $this->spawn;
        }

        return $this->dimensionSpawns[$dimension->value]
            ?? throw new InvalidArgumentException('World does not expose a spawn for the requested dimension.');
    }
}
