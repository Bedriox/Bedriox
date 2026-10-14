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

/** Three-dimensional terrain field blended between two limit stacks by a selector stack. */
final readonly class BlendedTerrainNoise
{
    private const float BASE_SCALE = 684.412;
    private const float SELECTOR_DIVISOR = 10.0;
    private const float LIMIT_DIVISOR = 512.0;
    private const float OUTPUT_DIVISOR = 128.0;

    /** @var list<GradientNoiseLevel> */
    private array $lowerLevels;
    /** @var list<GradientNoiseLevel> */
    private array $upperLevels;
    /** @var list<GradientNoiseLevel> */
    private array $selectorLevels;

    public function __construct(
        Xoroshiro128PlusPlus $random,
        private float $horizontalScale = 0.25,
        private float $verticalScale = 0.125,
        private float $horizontalFactor = 80.0,
        private float $verticalFactor = 160.0,
        private float $verticalWrapMultiplier = 8.0,
    ) {
        $this->lowerLevels = self::createLevels($random, 16);
        $this->upperLevels = self::createLevels($random, 16);
        $this->selectorLevels = self::createLevels($random, 8);
    }

    public function sample(float $x, float $y, float $z): float
    {
        $scaledHorizontal = self::BASE_SCALE * $this->horizontalScale;
        $scaledVertical = self::BASE_SCALE * $this->verticalScale;
        $selectorHorizontal = $scaledHorizontal / $this->horizontalFactor;
        $selectorVertical = $scaledVertical / $this->verticalFactor;
        $selectorVerticalWrap = $selectorVertical * $this->verticalWrapMultiplier;
        $limitVerticalWrap = $scaledVertical * $this->verticalWrapMultiplier;

        $selector = 0.0;
        $frequency = 1.0;
        foreach ($this->selectorLevels as $level) {
            $selector += $level->sample(
                self::maintainPrecision($x * $selectorHorizontal * $frequency),
                self::maintainPrecision($y * $selectorVertical * $frequency),
                self::maintainPrecision($z * $selectorHorizontal * $frequency),
                $selectorVerticalWrap * $frequency,
                $y * $selectorVertical * $frequency,
            ) / $frequency;
            $frequency /= 2.0;
        }

        $blend = max(0.0, min(2.0, ($selector / self::SELECTOR_DIVISOR) + 1.0)) * 0.5;
        $lower = 0.0;
        $upper = 0.0;
        $frequency = 1.0;
        foreach ($this->lowerLevels as $index => $lowerLevel) {
            $sampleX = self::maintainPrecision($x * $scaledHorizontal * $frequency);
            $sampleY = self::maintainPrecision($y * $scaledVertical * $frequency);
            $sampleZ = self::maintainPrecision($z * $scaledHorizontal * $frequency);
            $sampleWrap = $limitVerticalWrap * $frequency;
            $minimumY = $y * $scaledVertical * $frequency;
            if ($blend < 1.0) {
                $lower += $lowerLevel->sample($sampleX, $sampleY, $sampleZ, $sampleWrap, $minimumY) / $frequency;
            }
            if ($blend > 0.0) {
                $upper += $this->upperLevels[$index]
                    ->sample($sampleX, $sampleY, $sampleZ, $sampleWrap, $minimumY) / $frequency;
            }
            $frequency /= 2.0;
        }

        $lower /= self::LIMIT_DIVISOR;
        $upper /= self::LIMIT_DIVISOR;

        return ($lower + $blend * ($upper - $lower)) / self::OUTPUT_DIVISOR;
    }

    /** @return list<float> */
    public function sampleColumn(float $x, float $z, int $minimumY, int $maximumY, int $step): array
    {
        if ($minimumY > $maximumY || $step < 1) {
            throw new InvalidArgumentException('Terrain-noise column bounds and step must describe a non-empty column.');
        }

        $scaledHorizontal = self::BASE_SCALE * $this->horizontalScale;
        $scaledVertical = self::BASE_SCALE * $this->verticalScale;
        $selectorHorizontal = $scaledHorizontal / $this->horizontalFactor;
        $selectorVertical = $scaledVertical / $this->verticalFactor;
        $selectorVerticalWrap = $selectorVertical * $this->verticalWrapMultiplier;
        $limitVerticalWrap = $scaledVertical * $this->verticalWrapMultiplier;
        $selectorCoordinates = [];
        $frequency = 1.0;
        foreach ($this->selectorLevels as $_) {
            $selectorCoordinates[] = [
                self::maintainPrecision($x * $selectorHorizontal * $frequency),
                self::maintainPrecision($z * $selectorHorizontal * $frequency),
                $selectorVerticalWrap * $frequency,
                $frequency,
            ];
            $frequency /= 2.0;
        }
        $limitCoordinates = [];
        $frequency = 1.0;
        foreach ($this->lowerLevels as $_) {
            $limitCoordinates[] = [
                self::maintainPrecision($x * $scaledHorizontal * $frequency),
                self::maintainPrecision($z * $scaledHorizontal * $frequency),
                $limitVerticalWrap * $frequency,
                $frequency,
            ];
            $frequency /= 2.0;
        }

        $samples = [];
        for ($y = $minimumY; $y <= $maximumY; $y += $step) {
            $selector = 0.0;
            foreach ($this->selectorLevels as $index => $level) {
                [$sampleX, $sampleZ, $sampleWrap, $frequency] = $selectorCoordinates[$index];
                $minimumSampleY = $y * $selectorVertical * $frequency;
                $selector += $level->sample(
                    $sampleX,
                    self::maintainPrecision($minimumSampleY),
                    $sampleZ,
                    $sampleWrap,
                    $minimumSampleY,
                ) / $frequency;
            }
            $blend = max(0.0, min(2.0, ($selector / self::SELECTOR_DIVISOR) + 1.0)) * 0.5;
            $lower = 0.0;
            $upper = 0.0;
            foreach ($this->lowerLevels as $index => $lowerLevel) {
                [$sampleX, $sampleZ, $sampleWrap, $frequency] = $limitCoordinates[$index];
                $minimumSampleY = $y * $scaledVertical * $frequency;
                $sampleY = self::maintainPrecision($minimumSampleY);
                if ($blend < 1.0) {
                    $lower += $lowerLevel->sample($sampleX, $sampleY, $sampleZ, $sampleWrap, $minimumSampleY)
                        / $frequency;
                }
                if ($blend > 0.0) {
                    $upper += $this->upperLevels[$index]
                        ->sample($sampleX, $sampleY, $sampleZ, $sampleWrap, $minimumSampleY) / $frequency;
                }
            }

            $lower /= self::LIMIT_DIVISOR;
            $upper /= self::LIMIT_DIVISOR;
            $samples[] = ($lower + $blend * ($upper - $lower)) / self::OUTPUT_DIVISOR;
        }

        return $samples;
    }

    /** @return list<GradientNoiseLevel> */
    private static function createLevels(Xoroshiro128PlusPlus $random, int $count): array
    {
        $levels = [];
        for ($index = 0; $index < $count; ++$index) {
            $levels[] = new GradientNoiseLevel($random);
        }

        return $levels;
    }

    private static function maintainPrecision(float $value): float
    {
        return $value - floor(($value / 33_554_432.0) + 0.5) * 33_554_432.0;
    }
}
