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

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Server\World\Storage\LevelDb\LevelDbChunkKey;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class LevelDbChunkKeyTest extends TestCase
{
    public function testMojangChunkKeysUseSignedLittleEndianCoordinates(): void
    {
        $prefix = "\xff\xff\xff\xff\xfe\xff\xff\xff";
        self::assertSame($prefix, LevelDbChunkKey::prefix(-1, -2));
        self::assertSame($prefix . "\x2c", LevelDbChunkKey::version(-1, -2));
        self::assertSame($prefix . "\x2b", LevelDbChunkKey::data3d(-1, -2));
        self::assertSame($prefix . "\x2f\xfc", LevelDbChunkKey::subChunk(-1, -2, -4));
        self::assertSame($prefix . "\x31", LevelDbChunkKey::blockEntities(-1, -2));
        self::assertSame($prefix . "\x36", LevelDbChunkKey::finalization(-1, -2));
    }

    public function testSubchunkYMustFitItsKeyByte(): void
    {
        $this->expectException(InvalidArgumentException::class);
        LevelDbChunkKey::subChunk(0, 0, 128);
    }
}
