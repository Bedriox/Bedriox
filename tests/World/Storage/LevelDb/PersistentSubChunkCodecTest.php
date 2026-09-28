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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\LittleEndianBlockStateNbtCodec;
use Bedriox\Data\PersistentBlockState;
use Bedriox\Server\World\Storage\LevelDb\LevelDbStorageException;
use Bedriox\Server\World\Storage\LevelDb\PersistentBlockStorage;
use Bedriox\Server\World\Storage\LevelDb\PersistentSubChunkCodec;
use Bedriox\Server\World\Storage\LevelDb\StoredSubChunk;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PersistentSubChunkCodecTest extends TestCase
{
    private PersistentSubChunkCodec $codec;
    private LittleEndianBlockStateNbtCodec $stateCodec;
    private PersistentBlockState $air;
    private PersistentBlockState $grass;

    protected function setUp(): void
    {
        $registry = BedrockDataSet::bundled()->persistentBlockStateRegistry();
        $this->stateCodec = new LittleEndianBlockStateNbtCodec($registry);
        $this->codec = new PersistentSubChunkCodec($this->stateCodec);
        $this->air = $registry->knownState(CanonicalBlockState::from('minecraft:air'));
        $this->grass = $registry->knownState(CanonicalBlockState::from('minecraft:grass_block'));
    }

    public function testUniformV8RecordRoundTripsWithoutAZeroBitPaletteLength(): void
    {
        $encoded = $this->codec->encode(new StoredSubChunk(-4, [PersistentBlockStorage::uniform($this->air)]));

        self::assertSame(53, strlen($encoded));
        self::assertSame(8, ord($encoded[0]));
        self::assertSame(1, ord($encoded[1]));
        self::assertSame(0, ord($encoded[2]));
        $decoded = $this->codec->decode($encoded, -4);
        self::assertSame(8, $decoded->sourceVersion);
        self::assertSame(-4, $decoded->sectionY);
        self::assertSame(
            $this->stateCodec->encode($this->air),
            $this->stateCodec->encode($decoded->storages()[0]->stateAt(15, 15, 15)),
        );
        self::assertSame($encoded, $this->codec->encode($decoded));
    }

    public function testV9RecordReadsItsSignedPayloadYAndRewritesAsV8(): void
    {
        $v8 = $this->codec->encode(new StoredSubChunk(-4, [PersistentBlockStorage::uniform($this->air)]));
        $v9 = "\x09" . $v8[1] . "\xfc" . substr($v8, 2);

        self::assertSame(54, strlen($v9));
        $decoded = $this->codec->decode($v9, -4);
        self::assertSame(9, $decoded->sourceVersion);
        self::assertSame(-4, $decoded->sectionY);
        self::assertSame($v8, $this->codec->encode($decoded));
    }

    public function testDiskXzyWordsTransposeToMemoryYzx(): void
    {
        $indices = array_fill(0, PersistentBlockStorage::ENTRY_COUNT, 0);
        $indices[2 + (5 << 4) + (3 << 8)] = 1;
        $storage = new PersistentBlockStorage([$this->air, $this->grass], array_values($indices));

        $encoded = $this->codec->encode(new StoredSubChunk(0, [$storage]));
        // Disk offset x*256 + z*16 + y = 595; one-bit word 18, bit 19.
        $markerWord = unpack('Vvalue', substr($encoded, 3 + (18 * 4), 4));
        self::assertIsArray($markerWord);
        self::assertSame(0x0008_0000, $markerWord['value']);

        $decoded = $this->codec->decode($encoded, 0)->storages()[0];
        self::assertSame(1, $decoded->indexAt(2, 3, 5));
        self::assertSame(0, $decoded->indexAt(3, 2, 5));
        self::assertSame(
            $this->stateCodec->encode($this->grass),
            $this->stateCodec->encode($decoded->stateAt(2, 3, 5)),
        );
    }

    public function testMultipleStorageLayersRoundTrip(): void
    {
        $encoded = $this->codec->encode(new StoredSubChunk(7, [
            PersistentBlockStorage::uniform($this->grass),
            PersistentBlockStorage::uniform($this->air),
        ]));
        $decoded = $this->codec->decode($encoded, 7);

        self::assertCount(2, $decoded->storages());
        self::assertSame(
            $this->stateCodec->encode($this->grass),
            $this->stateCodec->encode($decoded->storages()[0]->stateAt(0, 0, 0)),
        );
        self::assertSame(
            $this->stateCodec->encode($this->air),
            $this->stateCodec->encode($decoded->storages()[1]->stateAt(0, 0, 0)),
        );
    }

    /** @return iterable<string, array{string, int}> */
    public static function malformedRecords(): iterable
    {
        yield 'empty' => ['', 0];
        yield 'unsupported version' => ["\x07\x01", 0];
        yield 'zero storages' => ["\x08\x00", 0];
        yield 'network palette flag' => ["\x08\x01\x01", 0];
        yield 'unsupported bit width' => ["\x08\x01\x0e", 0];
        yield 'v9 mismatched y' => ["\x09\x01\x01", 0];
    }

    #[DataProvider('malformedRecords')]
    public function testMalformedRecordsFailClosed(string $bytes, int $sectionY): void
    {
        $this->expectException(LevelDbStorageException::class);
        $this->codec->decode($bytes, $sectionY);
    }

    public function testTrailingBytesFailClosed(): void
    {
        $encoded = $this->codec->encode(new StoredSubChunk(0, [PersistentBlockStorage::uniform($this->air)]));
        $this->expectException(LevelDbStorageException::class);
        $this->codec->decode($encoded . "\x00", 0);
    }
}
