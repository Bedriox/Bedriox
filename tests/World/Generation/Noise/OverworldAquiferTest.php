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

namespace Bedriox\Server\Tests\World\Generation\Noise;

use Bedriox\Server\World\Generation\Noise\OverworldAquifer;
use Bedriox\Server\World\Generation\Noise\OverworldBaseDensitySampler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class OverworldAquiferTest extends TestCase
{
    #[DataProvider('preliminarySurfaces')]
    public function testPreliminarySurfaceMatchesNativeReference(int $x, int $z, int $level): void
    {
        self::assertSame($level, (new OverworldBaseDensitySampler(0))->preliminarySurfaceLevel($x, $z, -64, 320));
    }

    public function testNearestCellsPreserveNativeTieOrdering(): void
    {
        $nearest = $this->aquifer()->nearest(-47, 46, -1);

        self::assertSame([
            [-43, 43, -7, 61],
            [-44, 40, 3, 61],
            [-39, 54, 0, 129],
            [-55, 54, -9, 192],
        ], array_map(
            static fn(array $entry): array => [
                $entry['center']['x'],
                $entry['center']['y'],
                $entry['center']['z'],
                $entry['distance'],
            ],
            $nearest,
        ));
    }

    #[DataProvider('fluidCells')]
    public function testFluidCellLevelsMatchNativeReference(int $x, int $y, int $z, int $level): void
    {
        self::assertSame($level, $this->aquifer()->fluidLevel(['x' => $x, 'y' => $y, 'z' => $z]));
    }

    /** @return iterable<string, array{int, int, int}> */
    public static function preliminarySurfaces(): iterable
    {
        yield 'tight threshold below' => [80, -44, 48];
        yield 'tight threshold above' => [40, 8, 48];
        yield 'western sample' => [-48, -32, 40];
        yield 'northern sample' => [-16, -32, 40];
        yield 'positive quadrant' => [96, 96, 56];
    }

    /** @return iterable<string, array{int, int, int, int}> */
    public static function fluidCells(): iterable
    {
        yield 'global sea' => [-14, 60, -30, 63];
        yield 'spread level' => [-42, 36, -32, 17];
        yield 'dry cell' => [-44, 63, 37, -4096];
    }

    private function aquifer(): OverworldAquifer
    {
        return new OverworldAquifer(0, new OverworldBaseDensitySampler(0), -48, -32);
    }
}
