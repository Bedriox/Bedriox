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
final readonly class OverworldCavifier
{
    /** @var array<string, DoublePerlinNoise> */
    private array $noise;

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
        ];
        $noise = [];
        foreach ($spec as $name => [$octave, $amplitudes]) {
            $noise[$name] = new DoublePerlinNoise($factory->forString('minecraft:' . $name), $octave, $amplitudes);
        }
        $this->noise = $noise;
    }

    public function cavify(int $x, int $y, int $z, float $density): float
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
        $elevation = 8.0 * $this->sample('spaghetti_2d_elevation', $x, 0.0, $z);
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
