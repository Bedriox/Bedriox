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

use InvalidArgumentException;

/** Sparse octave stack used by normal-noise fields. */
final readonly class OctavePerlinNoise
{
    private const float PRECISION = 33_554_432.0;

    /** @var list<?GradientNoise> */
    private array $levels;
    /** @var list<float> */
    private array $amplitudes;
    private float $inputFactor;
    private float $valueFactor;

    /** @param list<float> $amplitudes */
    public function __construct(Xoroshiro128PlusPlus $random, int $firstOctave, array $amplitudes)
    {
        if ($amplitudes === [] || count($amplitudes) > 32) {
            throw new InvalidArgumentException('Octave amplitudes must be a non-empty bounded list.');
        }
        $factory = $random->forkPositional();
        $levels = [];
        foreach ($amplitudes as $index => $amplitude) {
            $levels[] = (float) $amplitude === 0.0
                ? null
                : new GradientNoise($factory->forString('octave_' . ($firstOctave + $index)));
        }
        $this->levels = $levels;
        $this->amplitudes = array_map(static fn(int|float $value): float => (float) $value, $amplitudes);
        $this->inputFactor = 2.0 ** $firstOctave;
        $this->valueFactor = (2.0 ** (count($amplitudes) - 1)) / ((2.0 ** count($amplitudes)) - 1.0);
    }

    public function sample(float $x, float $y, float $z): float
    {
        $result = 0.0;
        $inputFactor = $this->inputFactor;
        $valueFactor = $this->valueFactor;
        foreach ($this->levels as $index => $level) {
            if ($level instanceof GradientNoise) {
                $result += $this->amplitudes[$index] * $level->sample(
                    self::maintainPrecision($x * $inputFactor),
                    self::maintainPrecision($y * $inputFactor),
                    self::maintainPrecision($z * $inputFactor),
                ) * $valueFactor;
            }
            $inputFactor *= 2.0;
            $valueFactor /= 2.0;
        }

        return $result;
    }

    private static function maintainPrecision(float $value): float
    {
        return $value - floor(($value / self::PRECISION) + 0.5) * self::PRECISION;
    }
}
