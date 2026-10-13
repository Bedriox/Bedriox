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

namespace Bedriox\Server\World\Generation\Structure\Overworld;

use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Generation\SeededNoise;

/** Pure world-coordinate structure start selection, independent of generation order. */
final readonly class OverworldStructureLocator
{
    public function __construct(private SeededNoise $noise) {}

    /** @return list<OverworldStructureStart> */
    public function intersecting(ChunkPosition $chunk): array
    {
        $starts = [];
        foreach (OverworldStructureCatalog::all() as $definition) {
            $spacing = $definition->spacingChunks;
            $regionX = self::floorDiv($chunk->x, $spacing);
            $regionZ = self::floorDiv($chunk->z, $spacing);
            for ($rz = $regionZ - 1; $rz <= $regionZ + 1; ++$rz) {
                for ($rx = $regionX - 1; $rx <= $regionX + 1; ++$rx) {
                    $start = $this->start($definition, $rx, $rz);
                    if (self::intersects($chunk, $start)) {
                        $starts[] = $start;
                    }
                }
            }
        }

        return $starts;
    }

    public function start(OverworldStructureDefinition $definition, int $regionX, int $regionZ): OverworldStructureStart
    {
        $range = $definition->spacingChunks - $definition->separationChunks;
        $chunkX = $regionX * $definition->spacingChunks
            + $this->noise->chance($regionX, 0, $regionZ, $definition->salt, $range);
        $chunkZ = $regionZ * $definition->spacingChunks
            + $this->noise->chance($regionX, 1, $regionZ, $definition->salt + 1, $range);
        $variant = $definition->variants[$this->noise->chance(
            $regionX,
            2,
            $regionZ,
            $definition->salt + 2,
            count($definition->variants),
        )];

        return new OverworldStructureStart(
            $definition->family,
            $variant,
            $chunkX * 16 + 8,
            $chunkZ * 16 + 8,
            $definition->radius,
        );
    }

    /**
     * Finds the nearest deterministic start with the requested variant inside a bounded region radius.
     * Biome and terrain qualification remain the caller's responsibility.
     */
    public function nearestVariant(
        OverworldStructureFamily $family,
        string $variant,
        int $originX,
        int $originZ,
        int $maximumRegionRadius = 128,
    ): ?OverworldStructureStart {
        if ($maximumRegionRadius < 0 || $maximumRegionRadius > 1_024) {
            throw new \InvalidArgumentException('Structure search radius must be between 0 and 1024 regions.');
        }
        $definition = OverworldStructureCatalog::get($family);
        if (!in_array($variant, $definition->variants, true)) {
            throw new \InvalidArgumentException("Unknown {$family->value} variant $variant.");
        }
        $regionSize = $definition->spacingChunks * 16;
        $originRegionX = self::floorDiv($originX, $regionSize);
        $originRegionZ = self::floorDiv($originZ, $regionSize);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        for ($radius = 0; $radius <= $maximumRegionRadius; ++$radius) {
            for ($offsetZ = -$radius; $offsetZ <= $radius; ++$offsetZ) {
                for ($offsetX = -$radius; $offsetX <= $radius; ++$offsetX) {
                    if ($radius !== 0 && abs($offsetX) !== $radius && abs($offsetZ) !== $radius) {
                        continue;
                    }
                    $start = $this->start($definition, $originRegionX + $offsetX, $originRegionZ + $offsetZ);
                    if ($start->variant !== $variant) {
                        continue;
                    }
                    $distance = ($start->x - $originX) ** 2 + ($start->z - $originZ) ** 2;
                    if ($distance < $bestDistance) {
                        $best = $start;
                        $bestDistance = $distance;
                    }
                }
            }
            if ($best !== null && $radius > 1) {
                return $best;
            }
        }

        return $best;
    }

    private static function intersects(ChunkPosition $chunk, OverworldStructureStart $start): bool
    {
        $minimumX = $chunk->x * 16;
        $minimumZ = $chunk->z * 16;

        return $start->x + $start->radius >= $minimumX
            && $start->x - $start->radius <= $minimumX + 15
            && $start->z + $start->radius >= $minimumZ
            && $start->z - $start->radius <= $minimumZ + 15;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
