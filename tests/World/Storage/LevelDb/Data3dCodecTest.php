<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Server\World\Storage\LevelDb\Data3dCodec;
use Bedriox\Server\World\Storage\LevelDb\Data3dRecord;
use Bedriox\Server\World\Storage\LevelDb\LevelDbStorageException;
use Bedriox\Server\World\Storage\LevelDb\PersistentBiomeStorage;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockStorage;
use PHPUnit\Framework\TestCase;

final class Data3dCodecTest extends TestCase
{
    public function testUniformData3dHasTheMojang632ByteShape(): void
    {
        $codec = new Data3dCodec();
        $record = new Data3dRecord(
            str_repeat("\x00", Data3dRecord::HEIGHTMAP_BYTES),
            array_fill(0, Data3dRecord::BIOME_STORAGE_COUNT, PersistentBiomeStorage::uniform(1)),
        );

        $encoded = $codec->encode($record);
        self::assertSame(632, strlen($encoded));
        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
        self::assertSame(1, $codec->decode($encoded)->biomes()[23]->biomeIdAt(15, 15, 15));
    }

    public function testBiomeWordsUseDiskXzyAndMemoryYzx(): void
    {
        $indices = array_fill(0, PersistentBlockStorage::ENTRY_COUNT, 0);
        $indices[2 + (5 << 4) + (3 << 8)] = 1;
        $marker = new PersistentBiomeStorage([1, 42], array_values($indices));
        $biomes = array_fill(0, Data3dRecord::BIOME_STORAGE_COUNT, PersistentBiomeStorage::uniform(1));
        $biomes[0] = $marker;
        $codec = new Data3dCodec();

        $decoded = $codec->decode($codec->encode(new Data3dRecord(str_repeat("\x00", 512), $biomes)));
        self::assertSame(42, $decoded->biomes()[0]->biomeIdAt(2, 3, 5));
        self::assertSame(1, $decoded->biomes()[0]->biomeIdAt(3, 2, 5));
    }

    public function testTruncationAndTrailingBytesFailClosed(): void
    {
        $codec = new Data3dCodec();
        foreach ([str_repeat("\x00", 511), str_repeat("\x00", 632) . "\x00"] as $invalid) {
            try {
                $codec->decode($invalid);
                self::fail('Invalid Data3D record was accepted.');
            } catch (LevelDbStorageException) {
                self::addToAssertionCount(1);
            }
        }
    }
}
