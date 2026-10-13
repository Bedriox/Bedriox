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

namespace Bedriox\Server\Tests\World\Generation\Biome;

use Bedriox\Server\World\Biome;
use Bedriox\Server\World\Generation\Biome\BiomeChunkVolume;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class BiomeChunkVolumeTest extends TestCase
{
    public function testSampleBufferUsesNativeCellOriginDimensionsAndXzyOrder(): void
    {
        $positions = [];
        $volume = new BiomeChunkVolume(0);
        $samples = $volume->sampleBuffer(-3, -2, static function (int $x, int $y, int $z) use (&$positions): Biome {
            $positions[] = [$x, $y, $z];

            return Biome::plains();
        });

        self::assertCount(800, $samples);
        self::assertSame([-56, -72, -40], $positions[0]);
        self::assertSame([-56, -64, -40], $positions[1]);
        self::assertSame([-56, -72, -32], $positions[50]);
        self::assertSame([-48, -72, -40], $positions[200]);
        self::assertSame([-32, 320, -16], $positions[799]);
    }

    public function testNativeSelectedSampleIndicesDriveExactCells(): void
    {
        $default = Biome::plains();
        $samples = array_fill(0, BiomeChunkVolume::BUFFER_LENGTH, $default);
        $samples[251] = Biome::forest();
        $samples[452] = Biome::desert();
        $samples[252] = Biome::river();

        $storages = (new BiomeChunkVolume(0))->storagesFromSampleBuffer(-3, -2, array_values($samples));

        self::assertSame('minecraft:forest', $storages[-4]->biomeAt(0, 0, 0)->identifier);
        self::assertSame('minecraft:desert', $storages[-4]->biomeAt(0, 4, 0)->identifier);
        self::assertSame('minecraft:river', $storages[-4]->biomeAt(0, 5, 0)->identifier);
    }

    public function testNegativeChunkGenerationIsDeterministicAcrossCalls(): void
    {
        $samples = [];
        for ($index = 0; $index < BiomeChunkVolume::BUFFER_LENGTH; ++$index) {
            $samples[] = ($index & 1) === 0 ? Biome::forest() : Biome::river();
        }
        $volume = new BiomeChunkVolume(0x1234_5678);

        $first = $volume->storagesFromSampleBuffer(-17, -9, $samples);
        $second = $volume->storagesFromSampleBuffer(-17, -9, $samples);

        foreach ($first as $sectionY => $storage) {
            self::assertSame($storage->paletteIndices(), $second[$sectionY]->paletteIndices());
        }
    }

    public function testRejectsIncompleteSampleBuffer(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new BiomeChunkVolume(0))->storagesFromSampleBuffer(0, 0, [Biome::plains()]);
    }
}
