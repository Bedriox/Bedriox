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

namespace Bedriox\Server\Tests\World;

use Bedriox\Server\World\Biome;
use Bedriox\Server\World\BiomeStorage;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\SubChunkBlockStorage;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class CompactUniformStorageTest extends TestCase
{
    public function testUniformBlockStorageDoesNotRetainCellIndices(): void
    {
        $stone = new InternalBlockStateId(3);
        $dirt = new InternalBlockStateId(4);
        $storage = SubChunkBlockStorage::uniform($stone);

        self::assertSame('', (new ReflectionProperty($storage, 'packedPaletteIndices'))->getValue($storage));
        self::assertSame(str_repeat("\x00", 4_096), $storage->paletteIndices());
        self::assertSame(3, $storage->blockStateAt(15, 15, 15)->value);

        $changed = $storage->withBlockState(1, 2, 3, $dirt);
        self::assertSame(4, $changed->blockStateAt(1, 2, 3)->value);
        self::assertSame(3, $changed->blockStateAt(0, 0, 0)->value);
    }

    public function testUniformBiomeStorageDoesNotRetainCellIndices(): void
    {
        $plains = Biome::plains();
        $desert = Biome::desert();
        $storage = BiomeStorage::uniform($plains);

        self::assertSame('', (new ReflectionProperty($storage, 'packedPaletteIndices'))->getValue($storage));
        self::assertSame(str_repeat("\x00", 4_096), $storage->paletteIndices());
        self::assertSame('minecraft:plains', $storage->biomeAt(15, 15, 15)->identifier);

        $changed = $storage->withBiome(1, 2, 3, $desert);
        self::assertSame('minecraft:desert', $changed->biomeAt(1, 2, 3)->identifier);
        self::assertSame('minecraft:plains', $changed->biomeAt(0, 0, 0)->identifier);
    }
}
