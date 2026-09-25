<?php

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
