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

namespace Bedriox\Server\World\Generation\Noise;

/** Native-compatible cave density composition for modern Overworld terrain. */
final class OverworldCavifier
{
    private const int MAX_COLUMN_CACHE_ENTRIES = 512;
    /** @var array<string, DoublePerlinNoise> */
    private readonly array $noise;
    /** @var array<string, list<float>> */
    private array $columnCache = [];
    /** @var list<string> */
    private array $columnCacheOrder = [];

    public function __construct(int $seed)
    {
        $factory = XoroshiroPositionalRandomFactory::fromSeed($seed);
        $spec = [
            'pillar' => [-7, [1.0, 1.0]], 'pillar_rareness' => [-8, [1.0]], 'pillar_thickness' => [-8, [1.0]],
            'spaghetti_2d' => [-7, [1.0]], 'spaghetti_2d_elevation' => [-8, [1.0]],
            'spaghetti_2d_modulator' => [-11, [1.0]], 'spaghetti_2d_thickness' => [-11, [1.0]],
            'spaghetti_3d_1' => [-7, [1.0]], 'spaghetti_3d_2' => [-7, [1.0]],
            'spaghetti_3d_rarity' => [-11, [1.0]], 'spaghetti_3d_thickness' => [-8, [1.0]],
            'spaghetti_roughness' => [-5, [1.0]], 'spaghetti_roughness_modulator' => [-8, [1.0]],
            'cave_entrance' => [-7, [0.4, 0.5, 1.0]], 'cave_layer' => [-8, [1.0]],
            'cave_cheese' => [-8, [0.5, 1.0, 2.0, 1.0, 2.0, 1.0, 0.0, 2.0, 0.0]],
            'bedriox_entrance_gate' => [-9, [1.0, 0.5]],
            'bedriox_entrance_path' => [-6, [1.0, 0.5]],
            'bedriox_ravine_gate' => [-10, [1.0]],
            'bedriox_ravine_path' => [-7, [1.0, 0.5]],
            'bedriox_ravine_depth' => [-8, [1.0]],
        ];
        $noise = [];
        foreach ($spec as $name => [$octave, $amplitudes]) {
            $noise[$name] = new DoublePerlinNoise($factory->forString('minecraft:' . $name), $octave, $amplitudes);
        }
        $this->noise = $noise;
    }

    public function cavify(int $x, int $y, int $z, float $density): float
    {
        return $this->cavifyPrepared($x, $y, $z, $density, null);
    }

    private function cavifyPrepared(int $x, int $y, int $z, float $density, ?float $elevation): float
    {
        $roughness = self::map($this->sample('spaghetti_roughness_modulator', $x, $y, $z), 0.0, -0.1)
            * (abs($this->sample('spaghetti_roughness', $x, $y, $z)) - 0.4);
        $rarity = self::rarity3d($this->sample('spaghetti_3d_rarity', $x * 2, $y, $z * 2));
        $spaghetti = self::clamp(max(
            $rarity * abs($this->sample('spaghetti_3d_1', $x / $rarity, $y / $rarity, $z / $rarity)),
            $rarity * abs($this->sample('spaghetti_3d_2', $x / $rarity, $y / $rarity, $z / $rarity)),
        ) + self::map($this->sample('spaghetti_3d_thickness', $x, $y, $z), -0.065, -0.088), -1.0, 1.0);
        $gradient = self::clampedGradient($y, -10, 30, 0.3, 0.0);
        $entrances = min(
            $this->sample('cave_entrance', $x * 0.75, $y * 0.5, $z * 0.75) + 0.37 + $gradient,
            $roughness + $spaghetti,
        );
        if ($density < 1.5625) {
            return min($density, 5.0 * $entrances);
        }
        $thickness = self::map($this->sample('spaghetti_2d_thickness', $x * 2, $y, $z * 2), -0.6, -1.3);
        $rarity2d = self::rarity2d($this->sample('spaghetti_2d_modulator', $x * 2, $y, $z * 2));
        $sample2d = $rarity2d * abs($this->sample('spaghetti_2d', $x / $rarity2d, $y / $rarity2d, $z / $rarity2d));
        $elevation ??= 8.0 * $this->sample('spaghetti_2d_elevation', $x, 0.0, $z);
        $elevationGradient = (abs($elevation + self::clampedGradient($y, -64, 320, 8.0, -40.0)) + $thickness) ** 3;
        $spaghetti2d = self::clamp(max($sample2d + 0.083 * $thickness, $elevationGradient), -1.0, 1.0);
        $layer = 4.0 * $this->sample('cave_layer', $x, $y * 8, $z) ** 2;
        $cheese = self::clamp(0.27 + $this->sample('cave_cheese', $x, $y * (2.0 / 3.0), $z), -1.0, 1.0);
        $passages = min($layer + $cheese, $entrances, $spaghetti2d + $roughness);
        $pillar = (2.0 * $this->sample('pillar', $x * 25, $y * 0.3, $z * 25)
            + self::map($this->sample('pillar_rareness', $x, $y, $z), 0.0, -2.0))
            * self::map($this->sample('pillar_thickness', $x, $y, $z), 0.0, 1.1) ** 3;

        return max($passages, $pillar >= 0.03 ? $pillar : -1_000_000.0);
    }

    /**
     * Applies the cave field while protecting ordinary terrain immediately below
     * the surface. Rare entrance and ravine fields can deliberately cross that
     * protection so caves have discoverable mouths without peppering the surface.
     */
    public function cavifyForSurface(int $x, int $y, int $z, float $density, int $surfaceY): float
    {
        return $this->cavifyForSurfacePrepared($x, $y, $z, $density, $surfaceY, null, null, null);
    }

    private function cavifyForSurfacePrepared(
        int $x,
        int $y,
        int $z,
        float $density,
        int $surfaceY,
        ?float $entranceGate,
        ?float $ravineGate,
        ?float $spaghettiElevation,
    ): float {
        $depth = $surfaceY - $y;
        if ($depth < -1 || $density <= 0.0) {
            return $density;
        }

        $underground = $this->cavifyPrepared($x, $y, $z, $density, $spaghettiElevation);
        if ($depth >= 8) {
            return min(
                $underground,
                $this->entranceDensity($x, $y, $z, $depth, $entranceGate),
                $this->ravineDensity($x, $y, $z, $depth, $ravineGate),
            );
        }

        $entrance = $this->entranceDensity($x, $y, $z, $depth, $entranceGate);
        $ravine = $this->ravineDensity($x, $y, $z, $depth, $ravineGate);
        if ($depth <= 3) {
            return min($density, $entrance, $ravine);
        }

        $blend = ($depth - 3.0) / 5.0;
        $protectedUnderground = $density + ($underground - $density) * $blend;

        return min($protectedUnderground, $entrance, $ravine);
    }

    /**
     * @param list<float> $baseColumn
     * @return list<float>
     */
    public function cavifyColumn(
        int $x,
        int $z,
        int $minimumY,
        int $step,
        array $baseColumn,
        int $surfaceY,
    ): array {
        if ($step < 1 || $baseColumn === []) {
            throw new \InvalidArgumentException('Cave density columns require samples and a positive step.');
        }
        $cacheKey = $x . ':' . $z . ':' . $minimumY . ':' . $step . ':' . count($baseColumn) . ':' . $surfaceY;
        if (isset($this->columnCache[$cacheKey])) {
            return $this->columnCache[$cacheKey];
        }

        $entranceGate = $this->sample('bedriox_entrance_gate', $x, 0.0, $z);
        $ravineGate = $this->sample('bedriox_ravine_gate', $x, 0.0, $z);
        $spaghettiElevation = 8.0 * $this->sample('spaghetti_2d_elevation', $x, 0.0, $z);
        $cavified = [];
        foreach ($baseColumn as $offset => $density) {
            $cavified[] = $this->cavifyForSurfacePrepared(
                $x,
                $minimumY + $offset * $step,
                $z,
                $density,
                $surfaceY,
                $entranceGate,
                $ravineGate,
                $spaghettiElevation,
            );
        }
        if (count($this->columnCacheOrder) === self::MAX_COLUMN_CACHE_ENTRIES) {
            $expired = array_shift($this->columnCacheOrder);
            unset($this->columnCache[$expired]);
        }
        $this->columnCacheOrder[] = $cacheKey;

        return $this->columnCache[$cacheKey] = $cavified;
    }

    private function entranceDensity(int $x, int $y, int $z, int $depth, ?float $gate = null): float
    {
        if ($depth < -1 || $depth > 28) {
            return 1_000_000.0;
        }
        $gate ??= $this->sample('bedriox_entrance_gate', $x, 0.0, $z);
        if ($gate < 0.38) {
            return 1_000_000.0;
        }

        $path = abs($this->sample(
            'bedriox_entrance_path',
            $x * 1.35 + $depth * 0.17,
            0.0,
            $z * 1.35 - $depth * 0.11,
        ));
        $gateStrength = min(1.0, ($gate - 0.38) / 0.18);
        $surfaceTaper = 0.025 + min(1.0, max(0.0, ($depth + 1.0) / 13.0)) * 0.11;
        $width = $surfaceTaper * (0.7 + 0.6 * $gateStrength);

        return ($path - $width) * 8.0;
    }

    private function ravineDensity(int $x, int $y, int $z, int $depth, ?float $gate = null): float
    {
        if ($depth < 0 || $depth > 88) {
            return 1_000_000.0;
        }
        $gate ??= $this->sample('bedriox_ravine_gate', $x, 0.0, $z);
        if ($gate < 0.52) {
            return 1_000_000.0;
        }

        $path = abs($this->sample('bedriox_ravine_path', $x * 0.72, $y * 0.08, $z * 0.72));
        $depthNoise = $this->sample('bedriox_ravine_depth', $x, 0.0, $z);
        $centerDepth = 30.0 + 18.0 * $depthNoise;
        $verticalShape = abs($depth - $centerDepth) / 48.0;
        $surfaceTaper = min(1.0, ($depth + 1.0) / 8.0);
        $gateStrength = min(1.0, ($gate - 0.52) / 0.14);
        $width = (0.035 + 0.075 * $gateStrength) * $surfaceTaper;

        return max(($path - $width) * 7.0, $verticalShape - 0.72);
    }

    private function sample(string $name, float|int $x, float|int $y, float|int $z): float
    {
        return $this->noise[$name]->sample((float) $x, (float) $y, (float) $z);
    }

    private static function map(float $value, float $minimum, float $maximum): float
    {
        return $minimum + ($value + 1.0) * 0.5 * ($maximum - $minimum);
    }
    private static function clamp(float $value, float $minimum, float $maximum): float
    {
        return max($minimum, min($maximum, $value));
    }
    private static function clampedGradient(float $y, float $fromY, float $toY, float $from, float $to): float
    {
        return $y <= $fromY ? $from : ($y >= $toY ? $to : $from + (($y - $fromY) / ($toY - $fromY)) * ($to - $from));
    }
    private static function rarity3d(float $value): float
    {
        return $value < -0.5 ? 0.75 : ($value < 0.0 ? 1.0 : ($value < 0.5 ? 1.5 : 2.0));
    }
    private static function rarity2d(float $value): float
    {
        return $value < -0.75 ? 0.5 : ($value < -0.5 ? 0.75 : ($value < 0.5 ? 1.0 : ($value < 0.75 ? 2.0 : 3.0)));
    }
}
