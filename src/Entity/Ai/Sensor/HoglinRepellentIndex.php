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

namespace Bedriox\Server\Entity\Ai\Sensor;

use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;
use OverflowException;

/** Bounded placement-driven index which avoids repeated block-volume scans for every hoglin. */
final class HoglinRepellentIndex
{
    public const int DEFAULT_CAPACITY = 65_536;
    public const float DETECTION_RADIUS = 8.0;

    /** @var array<string, array<string, array<string, BlockPosition>>> world => chunk => block key => position */
    private array $byWorld = [];

    private int $indexed = 0;

    public function __construct(private readonly int $capacity = self::DEFAULT_CAPACITY)
    {
        if ($capacity < 1 || $capacity > 1_000_000) {
            throw new InvalidArgumentException('Hoglin repellent index capacity is outside its supported bounds.');
        }
    }

    public static function isRepellent(string $blockIdentifier): bool
    {
        return in_array($blockIdentifier, [
            'minecraft:warped_fungus',
            'minecraft:potted_warped_fungus',
            'minecraft:nether_portal',
            'minecraft:respawn_anchor',
        ], true);
    }

    public function update(string $worldName, BlockPosition $position, string $blockIdentifier): void
    {
        self::validateWorld($worldName);
        $key = self::key($position);
        $chunkKey = self::chunkKeyForBlock($position);
        if (!self::isRepellent($blockIdentifier)) {
            if (isset($this->byWorld[$worldName][$chunkKey][$key])) {
                unset($this->byWorld[$worldName][$chunkKey][$key]);
                --$this->indexed;
            }
            if (($this->byWorld[$worldName][$chunkKey] ?? []) === []) {
                unset($this->byWorld[$worldName][$chunkKey]);
            }
            if (($this->byWorld[$worldName] ?? []) === []) {
                unset($this->byWorld[$worldName]);
            }
            return;
        }
        if (!isset($this->byWorld[$worldName][$chunkKey][$key]) && $this->indexed >= $this->capacity) {
            throw new OverflowException('Hoglin repellent index capacity is exhausted.');
        }
        if (!isset($this->byWorld[$worldName][$chunkKey][$key])) {
            ++$this->indexed;
        }
        $this->byWorld[$worldName][$chunkKey][$key] = $position;
    }

    public function nearest(string $worldName, Position $position, float $radius = self::DETECTION_RADIUS): ?BlockPosition
    {
        self::validateWorld($worldName);
        if (!is_finite($radius) || $radius <= 0.0 || $radius > 32.0) {
            throw new InvalidArgumentException('Hoglin repellent query radius is outside its supported bounds.');
        }
        $nearest = null;
        $nearestSquared = $radius * $radius;
        $minimumChunkX = (int) floor(($position->x - $radius) / 16.0);
        $maximumChunkX = (int) floor(($position->x + $radius) / 16.0);
        $minimumChunkZ = (int) floor(($position->z - $radius) / 16.0);
        $maximumChunkZ = (int) floor(($position->z + $radius) / 16.0);
        for ($chunkX = $minimumChunkX; $chunkX <= $maximumChunkX; ++$chunkX) {
            for ($chunkZ = $minimumChunkZ; $chunkZ <= $maximumChunkZ; ++$chunkZ) {
                foreach ($this->byWorld[$worldName][$chunkX . ':' . $chunkZ] ?? [] as $candidate) {
                    $dx = ($candidate->x + 0.5) - $position->x;
                    $dy = ($candidate->y + 0.5) - $position->y;
                    $dz = ($candidate->z + 0.5) - $position->z;
                    $distanceSquared = ($dx * $dx) + ($dy * $dy) + ($dz * $dz);
                    if ($distanceSquared > $nearestSquared) {
                        continue;
                    }
                    $nearest = $candidate;
                    $nearestSquared = $distanceSquared;
                }
            }
        }

        return $nearest;
    }

    public function avoidanceMotion(string $worldName, Position $position, float $speed = 0.28): ?EntityMotion
    {
        if (!is_finite($speed) || $speed <= 0.0 || $speed > 1.0) {
            throw new InvalidArgumentException('Hoglin repellent avoidance speed is outside its supported bounds.');
        }
        $repellent = $this->nearest($worldName, $position);
        if ($repellent === null) {
            return null;
        }
        $dx = $position->x - ($repellent->x + 0.5);
        $dz = $position->z - ($repellent->z + 0.5);
        $length = hypot($dx, $dz);
        if ($length < 0.000_001) {
            $dx = 1.0;
            $dz = 0.0;
            $length = 1.0;
        }

        return new EntityMotion($dx / $length * $speed, 0.0, $dz / $length * $speed);
    }

    public function count(): int
    {
        return $this->indexed;
    }

    public function removeChunk(string $worldName, ChunkPosition $chunk): void
    {
        self::validateWorld($worldName);
        $chunkKey = $chunk->key();
        $this->indexed -= count($this->byWorld[$worldName][$chunkKey] ?? []);
        unset($this->byWorld[$worldName][$chunkKey]);
        if (($this->byWorld[$worldName] ?? []) === []) {
            unset($this->byWorld[$worldName]);
        }
    }

    private static function validateWorld(string $worldName): void
    {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('Hoglin repellent world identity is invalid.');
        }
    }

    private static function key(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }

    private static function chunkKeyForBlock(BlockPosition $position): string
    {
        return (int) floor($position->x / 16.0) . ':' . (int) floor($position->z / 16.0);
    }
}
