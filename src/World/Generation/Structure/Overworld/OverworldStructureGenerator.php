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
use Bedriox\Server\World\Generation\GenerationBlockPalette;
use Bedriox\Server\World\Generation\MutableChunkBuilder;
use Bedriox\Server\World\Generation\SeededNoise;
use Bedriox\Server\World\Generation\Structure\Overworld\Family\StructureCanvas;
use Bedriox\Server\World\Generation\Structure\Overworld\Stronghold\StrongholdPlanner;
use Bedriox\Server\World\Generation\Structure\Overworld\Stronghold\StrongholdRenderer;
use Bedriox\Server\World\Generation\Structure\Overworld\Underground\UndergroundStructureRenderer;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillagePlanner;
use Bedriox\Server\World\Generation\Structure\Overworld\Village\VillageRenderer;

/** Production orchestration for qualified original Overworld structure families. */
final readonly class OverworldStructureGenerator
{
    private OverworldStructureLocator $locator;
    private VillagePlanner $villagePlanner;
    private VillageRenderer $villageRenderer;
    private StrongholdPlanner $strongholdPlanner;
    private StrongholdRenderer $strongholdRenderer;
    private UndergroundStructureRenderer $undergroundRenderer;

    public function __construct(GenerationBlockPalette $blocks, private SeededNoise $noise)
    {
        $this->locator = new OverworldStructureLocator($noise);
        $canvas = new StructureCanvas($blocks, $noise);
        $this->villagePlanner = new VillagePlanner($noise);
        $this->villageRenderer = new VillageRenderer($canvas);
        $this->strongholdPlanner = new StrongholdPlanner($noise);
        $this->strongholdRenderer = new StrongholdRenderer($canvas);
        $this->undergroundRenderer = new UndergroundStructureRenderer($canvas, $noise);
    }

    /**
     * @param callable(int, int): string $biomeAt
     * @param callable(int, int): int    $surfaceAt
     */
    public function populate(MutableChunkBuilder $builder, ChunkPosition $chunk, callable $biomeAt, callable $surfaceAt): void
    {
        foreach ($this->locator->intersecting($chunk) as $start) {
            $biome = $biomeAt($start->x, $start->z);
            $surface = $this->requiresSurface($start->family) ? $surfaceAt($start->x, $start->z) : 64;
            $start = $this->eligibleStart($start, $biome, $surface, $surfaceAt);
            if ($start !== null) {
                $this->render($builder, $start, $surface, $surfaceAt);
            }
        }
    }

    /** Replays one known qualified start for deterministic validation and internal preview tooling. */
    public function populateStart(MutableChunkBuilder $builder, OverworldStructureStart $start, int $surface, string $biome): void
    {
        OverworldStructureCatalog::get($start->family);
        $this->render(
            $builder,
            $this->biomeVariant($start, $biome),
            $surface,
            static fn(int $x, int $z): int => $surface,
        );
    }

    /**
     * @param callable(int, int): string $biomeAt
     * @param callable(int, int): int    $surfaceAt
     */
    public function locateNaturalVariant(
        OverworldStructureFamily $family,
        string $variant,
        int $originX,
        int $originZ,
        callable $biomeAt,
        callable $surfaceAt,
        int $maximumRegionRadius = 128,
    ): ?OverworldStructureStart {
        self::validateRadius($maximumRegionRadius);
        $definition = OverworldStructureCatalog::get($family);
        if (!in_array($variant, $definition->variants, true)) {
            throw new \InvalidArgumentException("Unknown {$family->value} variant $variant.");
        }
        [$originRegionX, $originRegionZ] = self::originRegion($definition, $originX, $originZ);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        for ($radius = 0; $radius <= $maximumRegionRadius; ++$radius) {
            foreach (self::ring($radius) as [$offsetX, $offsetZ]) {
                $start = $this->locator->start($definition, $originRegionX + $offsetX, $originRegionZ + $offsetZ);
                $biome = $biomeAt($start->x, $start->z);
                if (!$definition->supportsBiome($biome)) {
                    continue;
                }
                $start = $this->biomeVariant($start, $biome);
                if ($start->variant !== $variant) {
                    continue;
                }
                $surface = $this->requiresSurface($family) ? $surfaceAt($start->x, $start->z) : 64;
                if ($this->eligibleStart($start, $biome, $surface, $surfaceAt) === null) {
                    continue;
                }
                $distance = self::distanceSquared($start, $originX, $originZ);
                if ($distance < $bestDistance) {
                    $best = $start;
                    $bestDistance = $distance;
                }
            }
            if ($best !== null && $radius > 1) {
                return $best;
            }
        }

        return $best;
    }

    public function placementY(OverworldStructureStart $start, int $surface): int
    {
        return match ($start->family) {
            OverworldStructureFamily::Village => $surface,
            OverworldStructureFamily::Dungeon => -34 + $this->noise->chance($start->x, 0, $start->z, 20_101, 68),
            OverworldStructureFamily::Mineshaft => -38 + $this->noise->chance($start->x, 0, $start->z, 20_151, 82),
            OverworldStructureFamily::Stronghold => -24 + $this->noise->chance($start->x, 0, $start->z, 20_201, 28),
        };
    }

    /**
     * @param callable(int, int): string $biomeAt
     * @param callable(int, int): int    $surfaceAt
     */
    public function locateNaturalVariantLocation(
        OverworldStructureFamily $family,
        string $variant,
        int $originX,
        int $originZ,
        callable $biomeAt,
        callable $surfaceAt,
        int $maximumRegionRadius = 128,
    ): ?OverworldStructureLocation {
        $start = $this->locateNaturalVariant($family, $variant, $originX, $originZ, $biomeAt, $surfaceAt, $maximumRegionRadius);
        if ($start === null) {
            return null;
        }
        $surface = $this->requiresSurface($family) ? $surfaceAt($start->x, $start->z) : 64;

        return new OverworldStructureLocation($start, $this->placementY($start, $surface));
    }

    /**
     * @param callable(int, int): string $biomeAt
     * @param callable(int, int): int    $surfaceAt
     */
    public function locateNatural(
        OverworldStructureFamily $family,
        int $originX,
        int $originZ,
        callable $biomeAt,
        callable $surfaceAt,
        int $maximumRegionRadius = 128,
    ): ?OverworldStructureLocation {
        self::validateRadius($maximumRegionRadius);
        $definition = OverworldStructureCatalog::get($family);
        [$originRegionX, $originRegionZ] = self::originRegion($definition, $originX, $originZ);
        $best = null;
        $bestDistance = PHP_INT_MAX;
        for ($radius = 0; $radius <= $maximumRegionRadius; ++$radius) {
            foreach (self::ring($radius) as [$offsetX, $offsetZ]) {
                $start = $this->locator->start($definition, $originRegionX + $offsetX, $originRegionZ + $offsetZ);
                $biome = $biomeAt($start->x, $start->z);
                $surface = $this->requiresSurface($family) ? $surfaceAt($start->x, $start->z) : 64;
                $start = $this->eligibleStart($start, $biome, $surface, $surfaceAt);
                if ($start === null) {
                    continue;
                }
                $distance = self::distanceSquared($start, $originX, $originZ);
                if ($distance < $bestDistance) {
                    $best = new OverworldStructureLocation($start, $this->placementY($start, $surface));
                    $bestDistance = $distance;
                }
            }
            if ($best !== null && $radius > 1) {
                return $best;
            }
        }

        return $best;
    }

    /**
     * @param callable(int, int): string $biomeAt
     * @param callable(int, int): int    $surfaceAt
     * @return array<string, OverworldStructureLocation>
     */
    public function locateNaturalVariants(
        OverworldStructureFamily $family,
        int $originX,
        int $originZ,
        callable $biomeAt,
        callable $surfaceAt,
        int $maximumRegionRadius = 32,
    ): array {
        self::validateRadius($maximumRegionRadius);
        $definition = OverworldStructureCatalog::get($family);
        [$originRegionX, $originRegionZ] = self::originRegion($definition, $originX, $originZ);
        $locations = [];
        $distances = [];
        for ($radius = 0; $radius <= $maximumRegionRadius; ++$radius) {
            foreach (self::ring($radius) as [$offsetX, $offsetZ]) {
                $start = $this->locator->start($definition, $originRegionX + $offsetX, $originRegionZ + $offsetZ);
                $biome = $biomeAt($start->x, $start->z);
                $surface = $this->requiresSurface($family) ? $surfaceAt($start->x, $start->z) : 64;
                $start = $this->eligibleStart($start, $biome, $surface, $surfaceAt);
                if ($start === null) {
                    continue;
                }
                $distance = self::distanceSquared($start, $originX, $originZ);
                if (!isset($distances[$start->variant]) || $distance < $distances[$start->variant]) {
                    $locations[$start->variant] = new OverworldStructureLocation($start, $this->placementY($start, $surface));
                    $distances[$start->variant] = $distance;
                }
            }
            if (count($locations) === count($definition->variants)) {
                break;
            }
        }

        return $locations;
    }

    /** @param callable(int, int): int $surfaceAt */
    private function eligibleStart(
        OverworldStructureStart $start,
        string $biome,
        int $surface,
        callable $surfaceAt,
    ): ?OverworldStructureStart {
        $definition = OverworldStructureCatalog::get($start->family);
        if (!$definition->supportsBiome($biome)
            || (!$definition->underground && $surface <= 63)) {
            return null;
        }
        if ($start->family === OverworldStructureFamily::Village) {
            $heights = [$surface];
            foreach ([[-24, 0], [24, 0], [0, -24], [0, 24]] as [$dx, $dz]) {
                $height = $surfaceAt($start->x + $dx, $start->z + $dz);
                if ($height <= 62) {
                    return null;
                }
                $heights[] = $height;
            }
            if (max($heights) - min($heights) > 12) {
                return null;
            }
        }

        return $this->biomeVariant($start, $biome);
    }

    private function requiresSurface(OverworldStructureFamily $family): bool
    {
        return !OverworldStructureCatalog::get($family)->underground;
    }

    /** @param callable(int, int): int $surfaceAt */
    private function render(
        MutableChunkBuilder $builder,
        OverworldStructureStart $start,
        int $surface,
        callable $surfaceAt,
    ): void {
        match ($start->family) {
            OverworldStructureFamily::Village => $this->villageRenderer->render(
                $builder,
                $this->villagePlanner->plan($start, $surfaceAt),
                $surfaceAt,
            ),
            OverworldStructureFamily::Dungeon, OverworldStructureFamily::Mineshaft =>
                $this->undergroundRenderer->render($builder, $start, $this->placementY($start, $surface)),
            OverworldStructureFamily::Stronghold => $this->strongholdRenderer->render(
                $builder,
                $this->strongholdPlanner->plan($start, $this->placementY($start, $surface)),
            ),
        };
    }

    private function biomeVariant(OverworldStructureStart $start, string $biome): OverworldStructureStart
    {
        if ($start->family === OverworldStructureFamily::Mineshaft && str_contains($biome, 'mesa')) {
            return new OverworldStructureStart($start->family, 'badlands', $start->x, $start->z, $start->radius);
        }
        if ($start->family !== OverworldStructureFamily::Village) {
            return $start;
        }
        $variant = match (true) {
            str_contains($biome, 'desert') => 'desert',
            str_contains($biome, 'savanna') => 'savanna',
            str_contains($biome, 'taiga') => 'taiga',
            str_contains($biome, 'ice_'), str_contains($biome, 'snow') => 'snowy',
            default => 'plains',
        };

        return new OverworldStructureStart($start->family, $variant, $start->x, $start->z, $start->radius);
    }

    private static function validateRadius(int $radius): void
    {
        if ($radius < 0 || $radius > 1_024) {
            throw new \InvalidArgumentException('Structure search radius must be between 0 and 1024 regions.');
        }
    }

    /** @return array{int, int} */
    private static function originRegion(OverworldStructureDefinition $definition, int $x, int $z): array
    {
        $size = $definition->spacingChunks * 16;

        return [self::floorDiv($x, $size), self::floorDiv($z, $size)];
    }

    /** @return list<array{int, int}> */
    private static function ring(int $radius): array
    {
        $coordinates = [];
        for ($z = -$radius; $z <= $radius; ++$z) {
            for ($x = -$radius; $x <= $radius; ++$x) {
                if ($radius === 0 || abs($x) === $radius || abs($z) === $radius) {
                    $coordinates[] = [$x, $z];
                }
            }
        }

        return $coordinates;
    }

    private static function distanceSquared(OverworldStructureStart $start, int $x, int $z): int
    {
        return ($start->x - $x) ** 2 + ($start->z - $z) ** 2;
    }

    private static function floorDiv(int $value, int $divisor): int
    {
        $quotient = intdiv($value, $divisor);

        return $value < 0 && $value % $divisor !== 0 ? $quotient - 1 : $quotient;
    }
}
