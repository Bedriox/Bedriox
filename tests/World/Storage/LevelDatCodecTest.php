<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage;

use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\Exception\UnsupportedWorldDataException;
use Bedriox\Server\World\Storage\Nbt\LevelDatCodec;
use Bedriox\Server\World\Storage\Nbt\LevelDatMetadata;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LevelDatCodecTest extends TestCase
{
    private const string GOLDEN_HEX = '0a000000c10000000a0000030e0053746f7261676556657273696f6e0a000000030e004e6574776f726b56657273696f6e910800000809004c6576656c4e616d650500576f726c64040a0052616e646f6d536565642a00000000000000080d0067656e657261746f724e616d650400666c617408100067656e657261746f724f7074696f6e730000030600537061776e58ffffffff030600537061776e5940000000030600537061776e5a0200000004040054696d657b00000000000000010600437573746f6d0700';

    public function testDecodesAndReencodesIndependentGoldenLevelDat(): void
    {
        $contents = hex2bin(self::GOLDEN_HEX);
        self::assertIsString($contents);
        $metadata = (new LevelDatCodec())->decode($contents);

        self::assertSame(10, $metadata->headerVersion);
        self::assertSame(10, $metadata->storageVersion());
        self::assertSame(2193, $metadata->networkVersion());
        self::assertSame('World', $metadata->levelName());
        self::assertSame(42, $metadata->seed());
        self::assertSame('flat', $metadata->generatorName());
        self::assertSame('', $metadata->generatorOptions());
        self::assertSame([-1, 64, 2], [$metadata->spawnX(), $metadata->spawnY(), $metadata->spawnZ()]);
        self::assertSame(123, $metadata->time());
        self::assertSame(2, $metadata->difficulty());
        self::assertSame(7, $metadata->root['Custom']->value);
        self::assertSame($contents, (new LevelDatCodec())->encode($metadata));
    }

    public function testAcceptsLegacyIntegerTimeWithoutChangingItsTagType(): void
    {
        $metadata = self::metadata();
        $root = $metadata->root;
        $root['Time'] = LittleEndianNbtTag::int(321);
        $metadata = new LevelDatMetadata(10, $root);
        $codec = new LevelDatCodec();

        $decoded = $codec->decode($codec->encode($metadata));

        self::assertSame(321, $decoded->time());
        self::assertSame(LittleEndianNbtTag::INT, $decoded->root['Time']->type);
    }

    /** @return iterable<string, array{string}> */
    public static function truncatedHeaderProvider(): iterable
    {
        for ($length = 0; $length < LevelDatCodec::HEADER_BYTES; ++$length) {
            yield "header length $length" => [str_repeat("\0", $length)];
        }
    }

    #[DataProvider('truncatedHeaderProvider')]
    public function testRejectsTruncatedHeader(string $contents): void
    {
        $this->expectException(CorruptWorldDataException::class);
        (new LevelDatCodec())->decode($contents);
    }

    public function testRejectsTruncatedAndTrailingPayloads(): void
    {
        $valid = (new LevelDatCodec())->encode(self::metadata());
        foreach ([substr($valid, 0, -1), $valid . "\0"] as $malformed) {
            try {
                (new LevelDatCodec())->decode($malformed);
                self::fail('A level.dat payload with a mismatched declared length was accepted.');
            } catch (CorruptWorldDataException $failure) {
                self::assertStringContainsString('length', $failure->getMessage());
            }
        }
    }

    public function testRejectsOversizedPayloadBeforeReadingIt(): void
    {
        $contents = pack('V2', 10, LevelDatCodec::MAX_NBT_BYTES + 1);

        $this->expectException(CorruptWorldDataException::class);
        $this->expectExceptionMessage('size limit');
        (new LevelDatCodec())->decode($contents);
    }

    public function testRejectsUnsupportedHeaderAndStorageVersionsSeparately(): void
    {
        $valid = (new LevelDatCodec())->encode(self::metadata());
        try {
            (new LevelDatCodec())->decode(pack('V', 11) . substr($valid, 4));
            self::fail('An unsupported level.dat header version was accepted.');
        } catch (UnsupportedWorldDataException $failure) {
            self::assertStringContainsString('header version 11', $failure->getMessage());
        }

        $root = self::metadata()->root;
        $root['StorageVersion'] = LittleEndianNbtTag::int(11);
        $payload = (new LittleEndianNbtCodec())->encodeRootCompound($root);
        try {
            (new LevelDatCodec())->decode(pack('V2', 10, strlen($payload)) . $payload);
            self::fail('An unsupported storage version was accepted.');
        } catch (UnsupportedWorldDataException $failure) {
            self::assertStringContainsString('storage version 11', $failure->getMessage());
        }
    }

    public function testRejectsFutureNetworkVersion(): void
    {
        $root = self::metadata()->root;
        $root['NetworkVersion'] = LittleEndianNbtTag::int(LevelDatCodec::CURRENT_NETWORK_VERSION + 1);
        $payload = (new LittleEndianNbtCodec())->encodeRootCompound($root);

        $this->expectException(UnsupportedWorldDataException::class);
        $this->expectExceptionMessage('Network version');
        (new LevelDatCodec())->decode(pack('V2', 10, strlen($payload)) . $payload);
    }

    public function testLegacyFlatGeneratorAndDifficultyAreReadWithoutGeneratorName(): void
    {
        $root = self::metadata()->root;
        unset($root['generatorName']);
        unset($root['generatorOptions']);
        $root['Generator'] = LittleEndianNbtTag::int(2);
        $root['Difficulty'] = LittleEndianNbtTag::int(3);
        $metadata = new LevelDatMetadata(10, $root);

        self::assertSame('flat', $metadata->generatorName());
        self::assertSame('', $metadata->generatorOptions());
        self::assertSame(3, $metadata->difficulty());
    }

    public function testRejectsUnsupportedLegacyGeneratorAndInvalidDifficulty(): void
    {
        $root = self::metadata()->root;
        unset($root['generatorName']);
        $root['Generator'] = LittleEndianNbtTag::int(1);
        try {
            (new LevelDatMetadata(10, $root))->generatorName();
            self::fail('Unsupported legacy generator was accepted.');
        } catch (UnsupportedWorldDataException) {
        }

        $root = self::metadata()->root;
        $root['Difficulty'] = LittleEndianNbtTag::int(4);
        $this->expectException(CorruptWorldDataException::class);
        (new LevelDatMetadata(10, $root))->difficulty();
    }

    public function testRejectsMissingRequiredVersionAndWrongRootType(): void
    {
        $root = self::metadata()->root;
        unset($root['StorageVersion']);
        $nbt = new LittleEndianNbtCodec();
        $payload = $nbt->encodeRootCompound($root);
        $rejected = 0;
        foreach ([
            pack('V2', 10, strlen($payload)) . $payload,
            pack('V2', 10, 4) . "\x09\0\0\0",
        ] as $malformed) {
            try {
                (new LevelDatCodec())->decode($malformed);
                self::fail('Malformed required level.dat structure was accepted.');
            } catch (CorruptWorldDataException) {
                ++$rejected;
            }
        }
        self::assertSame(2, $rejected);
    }

    public function testRejectsCollectionLimitAndDeepNestingWithoutAllocation(): void
    {
        $hugeList = "\x0a\0\0\x09\x01\0x\x01" . pack('V', 65_537) . "\x00";
        $nested = "\x0a\0\0";
        for ($depth = 0; $depth < 33; ++$depth) {
            $nested .= "\x0a\x01\0x";
        }
        $nested .= str_repeat("\x00", 34);

        $rejected = 0;
        foreach ([$hugeList, $nested] as $payload) {
            try {
                (new LevelDatCodec())->decode(pack('V2', 10, strlen($payload)) . $payload);
                self::fail('Resource-limit violating NBT was accepted.');
            } catch (CorruptWorldDataException) {
                ++$rejected;
            }
        }
        self::assertSame(2, $rejected);
    }

    public function testPreservesEverySupportedUnknownTagShape(): void
    {
        $root = self::metadata()->root;
        $root['short'] = new LittleEndianNbtTag(LittleEndianNbtTag::SHORT, -123);
        $root['float'] = LittleEndianNbtTag::float(1.25);
        $root['double'] = new LittleEndianNbtTag(LittleEndianNbtTag::DOUBLE, -2.5);
        $root['bytes'] = new LittleEndianNbtTag(LittleEndianNbtTag::BYTE_ARRAY, "\x00\xff");
        $root['list'] = LittleEndianNbtTag::list(LittleEndianNbtTag::INT, [LittleEndianNbtTag::int(1), LittleEndianNbtTag::int(2)]);
        $root['compound'] = LittleEndianNbtTag::compound([]);
        $root['ints'] = new LittleEndianNbtTag(LittleEndianNbtTag::INT_ARRAY, [-2_147_483_648, 2_147_483_647]);
        $root['longs'] = new LittleEndianNbtTag(LittleEndianNbtTag::LONG_ARRAY, [PHP_INT_MIN, PHP_INT_MAX]);
        $metadata = new LevelDatMetadata(10, $root);
        $codec = new LevelDatCodec();

        self::assertEquals($root, $codec->decode($codec->encode($metadata))->root);
    }

    private static function metadata(): LevelDatMetadata
    {
        return new LevelDatMetadata(10, [
            'StorageVersion' => LittleEndianNbtTag::int(10),
            'NetworkVersion' => LittleEndianNbtTag::int(2193),
            'LevelName' => LittleEndianNbtTag::string('World'),
            'RandomSeed' => LittleEndianNbtTag::long(42),
            'generatorName' => LittleEndianNbtTag::string('flat'),
            'generatorOptions' => LittleEndianNbtTag::string(''),
            'SpawnX' => LittleEndianNbtTag::int(-1),
            'SpawnY' => LittleEndianNbtTag::int(64),
            'SpawnZ' => LittleEndianNbtTag::int(2),
            'Time' => LittleEndianNbtTag::long(123),
            'Custom' => LittleEndianNbtTag::byte(7),
        ]);
    }
}
