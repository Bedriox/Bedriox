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

namespace Bedriox\Server\Tests\Map;

use Bedriox\Api\Map\MapImage;
use Bedriox\Api\Map\MapScale;
use Bedriox\Api\World\DimensionType;
use Bedriox\Protocol\Packet\MapPixel;
use Bedriox\Server\Map\MapRecord;
use Bedriox\Server\Map\MapRegistry;
use Bedriox\Server\Map\MapTerrainSampler;
use PHPUnit\Framework\TestCase;

final class MapRegistryTest extends TestCase
{
    public function testImageMapPersistsAndProjectsAllPixels(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-map-' . bin2hex(random_bytes(8));
        try {
            $rgba = str_repeat("\x11\x22\x33\xff", MapRecord::WIDTH * MapRecord::HEIGHT);
            $record = (new MapRegistry($directory))->createImage(
                DimensionType::OVERWORLD,
                MapImage::fromRgba(MapRecord::WIDTH, MapRecord::HEIGHT, $rgba),
            );
            $writer = new MapRegistry($directory);
            self::assertNull($writer->get($record->id));

            $registry = new MapRegistry($directory);
            $record = $registry->createImage(
                DimensionType::OVERWORLD,
                MapImage::fromRgba(MapRecord::WIDTH, MapRecord::HEIGHT, $rgba),
            );
            $registry->flush();

            $reloaded = new MapRegistry($directory);
            self::assertSame($rgba, $reloaded->get($record->id)?->rgba);
            $packet = $reloaded->packet($record->id);
            self::assertNotNull($packet);
            self::assertSame(MapRecord::WIDTH, $packet->width);
            self::assertNotNull($packet->packedColors);
            self::assertSame(MapRecord::WIDTH * MapRecord::HEIGHT, $packet->packedColors->count);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testTerrainMapRefreshesFromItsDimensionRenderer(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-map-' . bin2hex(random_bytes(8));
        try {
            $registry = new MapRegistry($directory);
            $record = $registry->createTerrain(
                DimensionType::OVERWORLD,
                64,
                -64,
                MapScale::CLOSEST,
            );
            $registry->attachTerrainSampler(
                DimensionType::OVERWORLD,
                new class implements MapTerrainSampler {
                    public function colorAt(int $x, int $z, int $scale = 1): string
                    {
                        return "\xaa\xbb\xcc\xff";
                    }

                    public function invalidateColumn(int $x, int $z): void {}
                },
            );

            self::assertTrue($registry->request('session', $record->id));
            self::assertSame(64, $registry->tick(64, 10_000_000));
            $updated = $registry->get($record->id);
            self::assertNotNull($updated);
            self::assertSame(str_repeat("\xaa\xbb\xcc\xff", 64), substr($updated->rgba, 0, 256));
            self::assertSame(str_repeat("\0", MapRecord::PIXEL_BYTES - 256), substr($updated->rgba, 256));
            $delta = $registry->projection($record->id, $record->revision);
            self::assertNotNull($delta);
            self::assertSame(64, $delta->packet->width);
            self::assertSame(1, $delta->packet->height);
            self::assertSame($delta, $registry->projection($record->id, $record->revision));
            $registry->flush();
            self::assertSame($updated->rgba, (new MapRegistry($directory))->get($record->id)?->rgba);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testClientPixelsAreProvisionalAndCannotModifyLockedImages(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-map-' . bin2hex(random_bytes(8));
        try {
            $registry = new MapRegistry($directory);
            $terrain = $registry->createTerrain(DimensionType::OVERWORLD, 0, 0, MapScale::CLOSEST);
            self::assertTrue($registry->request('session', $terrain->id, [new MapPixel(-16_776_961, 0)]));
            self::assertSame(1, $registry->tick(1, 10_000_000));
            $updatedTerrain = $registry->get($terrain->id);
            self::assertNotNull($updatedTerrain);
            self::assertSame("\xff\0\0\xff", substr($updatedTerrain->rgba, 0, 4));

            $rgba = str_repeat("\x11\x22\x33\xff", MapRecord::WIDTH * MapRecord::HEIGHT);
            $image = $registry->createImage(
                DimensionType::OVERWORLD,
                MapImage::fromRgba(MapRecord::WIDTH, MapRecord::HEIGHT, $rgba),
            );
            self::assertTrue($registry->request('session', $image->id, [new MapPixel(-16_776_961, 0)]));
            $registry->tick(1, 10_000_000);
            self::assertSame($rgba, $registry->get($image->id)?->rgba);
        } finally {
            self::removeDirectory($directory);
        }
    }

    public function testTerrainPersistenceCoalescesIntermediateSlicesAndFlushesTheLatestRevision(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-map-' . bin2hex(random_bytes(8));
        try {
            $registry = new MapRegistry($directory);
            $record = $registry->createTerrain(DimensionType::OVERWORLD, 0, 0, MapScale::CLOSEST);
            $registry->flush();
            $registry->attachTerrainSampler(
                DimensionType::OVERWORLD,
                new class implements MapTerrainSampler {
                    public function colorAt(int $x, int $z, int $scale = 1): string
                    {
                        return "\x44\x88\xcc\xff";
                    }

                    public function invalidateColumn(int $x, int $z): void {}
                },
            );
            self::assertTrue($registry->request('session', $record->id));

            $registry->tick(64, 10_000_000);
            $firstPersisted = (new MapRegistry($directory))->get($record->id);
            self::assertNotNull($firstPersisted);

            $registry->tick(64, 10_000_000);
            $latest = $registry->get($record->id);
            self::assertNotNull($latest);
            self::assertGreaterThan($firstPersisted->revision, $latest->revision);
            self::assertSame($firstPersisted->revision, (new MapRegistry($directory))->get($record->id)?->revision);

            $registry->flush();
            $flushed = (new MapRegistry($directory))->get($record->id);
            self::assertSame($latest->revision, $flushed->revision);
            self::assertSame($latest->rgba, $flushed->rgba);
        } finally {
            self::removeDirectory($directory);
        }
    }

    private static function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                @unlink($directory . DIRECTORY_SEPARATOR . $entry);
            }
        }
        @rmdir($directory);
    }
}
