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

/** Gradient-noise octave used by the Overworld multinoise generator. */
final readonly class GradientNoise
{
    /** @var list<int> */
    private array $permutation;
    private float $originX;
    private float $originY;
    private float $originZ;

    public function __construct(Xoroshiro128PlusPlus $random)
    {
        $this->originX = $random->nextDouble() * 256.0;
        $this->originY = $random->nextDouble() * 256.0;
        $this->originZ = $random->nextDouble() * 256.0;
        $permutation = range(0, 255);
        for ($index = 0; $index < 256; ++$index) {
            $swap = $index + $random->nextInt(256 - $index);
            [$permutation[$index], $permutation[$swap]] = [$permutation[$swap], $permutation[$index]];
        }
        $this->permutation = array_values($permutation);
    }

    public function sample(float $x, float $y, float $z): float
    {
        $x += $this->originX;
        $y += $this->originY;
        $z += $this->originZ;
        $floorX = (int) floor($x);
        $floorY = (int) floor($y);
        $floorZ = (int) floor($z);
        $localX = $x - $floorX;
        $localY = $y - $floorY;
        $localZ = $z - $floorZ;
        $fadeX = self::fade($localX);
        $fadeY = self::fade($localY);
        $fadeZ = self::fade($localZ);

        $a = $this->permutation[$floorX & 255] + $floorY;
        $aa = $this->permutation[$a & 255] + $floorZ;
        $ab = $this->permutation[($a + 1) & 255] + $floorZ;
        $b = $this->permutation[($floorX + 1) & 255] + $floorY;
        $ba = $this->permutation[$b & 255] + $floorZ;
        $bb = $this->permutation[($b + 1) & 255] + $floorZ;

        $x00 = self::lerp(
            $fadeX,
            self::gradient($this->permutation[$aa & 255], $localX, $localY, $localZ),
            self::gradient($this->permutation[$ba & 255], $localX - 1.0, $localY, $localZ),
        );
        $x10 = self::lerp(
            $fadeX,
            self::gradient($this->permutation[$ab & 255], $localX, $localY - 1.0, $localZ),
            self::gradient($this->permutation[$bb & 255], $localX - 1.0, $localY - 1.0, $localZ),
        );
        $x01 = self::lerp(
            $fadeX,
            self::gradient($this->permutation[($aa + 1) & 255], $localX, $localY, $localZ - 1.0),
            self::gradient($this->permutation[($ba + 1) & 255], $localX - 1.0, $localY, $localZ - 1.0),
        );
        $x11 = self::lerp(
            $fadeX,
            self::gradient($this->permutation[($ab + 1) & 255], $localX, $localY - 1.0, $localZ - 1.0),
            self::gradient($this->permutation[($bb + 1) & 255], $localX - 1.0, $localY - 1.0, $localZ - 1.0),
        );

        return self::lerp($fadeZ, self::lerp($fadeY, $x00, $x10), self::lerp($fadeY, $x01, $x11));
    }

    private static function gradient(int $hash, float $x, float $y, float $z): float
    {
        return match ($hash & 15) {
            0 => $x + $y,
            1 => -$x + $y,
            2 => $x - $y,
            3 => -$x - $y,
            4 => $x + $z,
            5 => -$x + $z,
            6 => $x - $z,
            7 => -$x - $z,
            8 => $y + $z,
            9 => -$y + $z,
            10 => $y - $z,
            11 => -$y - $z,
            12 => $x + $y,
            13 => -$y + $z,
            14 => -$x + $y,
            default => -$y - $z,
        };
    }

    private static function fade(float $value): float
    {
        return $value * $value * $value * ($value * ($value * 6.0 - 15.0) + 10.0);
    }

    private static function lerp(float $amount, float $start, float $end): float
    {
        return $start + $amount * ($end - $start);
    }
}
