<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage;

use Bedriox\Server\World\Storage\AtomicFileWriter;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\LevelDatStore;
use Bedriox\Server\World\Storage\LocalAtomicFileWriter;
use Bedriox\Server\World\Storage\Nbt\LevelDatCodec;
use Bedriox\Server\World\Storage\Nbt\LevelDatMetadata;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use PHPUnit\Framework\TestCase;

final class LevelDatStoreTest extends TestCase
{
    public function testSaveDelegatesCompleteEncodingToAtomicWriter(): void
    {
        $writer = new class implements AtomicFileWriter {
            public string $path = '';
            public string $contents = '';

            public function write(string $path, string $contents): void
            {
                $this->path = $path;
                $this->contents = $contents;
            }
        };
        $metadata = self::metadata();

        (new LevelDatStore(writer: $writer))->save('worlds/main/level.dat', $metadata);

        self::assertSame('worlds/main/level.dat', $writer->path);
        self::assertEquals($metadata->root, (new LevelDatCodec())->decode($writer->contents)->root);
    }

    public function testLocalWriterAtomicallyCreatesAndReplacesLevelDatWithoutTemporaryLeaks(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-level-dat-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'level.dat';
        try {
            $writer = new LocalAtomicFileWriter();
            $writer->write($path, 'first');
            $writer->write($path, 'second');

            self::assertSame('second', file_get_contents($path));
            self::assertSame([], glob($path . '.tmp-*') ?: []);
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                @unlink($file);
            }
            @rmdir($directory);
        }
    }

    public function testLoadBoundsFileBeforeDecoding(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-level-dat-');
        self::assertIsString($path);
        try {
            self::assertSame(
                LevelDatCodec::HEADER_BYTES + LevelDatCodec::MAX_NBT_BYTES + 1,
                file_put_contents($path, str_repeat("\0", LevelDatCodec::HEADER_BYTES + LevelDatCodec::MAX_NBT_BYTES + 1)),
            );

            $this->expectException(CorruptWorldDataException::class);
            $this->expectExceptionMessage('size limit');
            (new LevelDatStore())->load($path);
        } finally {
            @unlink($path);
        }
    }

    private static function metadata(): LevelDatMetadata
    {
        return new LevelDatMetadata(10, [
            'StorageVersion' => LittleEndianNbtTag::int(10),
            'NetworkVersion' => LittleEndianNbtTag::int(2193),
            'LevelName' => LittleEndianNbtTag::string('World'),
            'RandomSeed' => LittleEndianNbtTag::long(1),
            'generatorName' => LittleEndianNbtTag::string('flat'),
            'generatorOptions' => LittleEndianNbtTag::string(''),
            'SpawnX' => LittleEndianNbtTag::int(0),
            'SpawnY' => LittleEndianNbtTag::int(64),
            'SpawnZ' => LittleEndianNbtTag::int(0),
            'Time' => LittleEndianNbtTag::long(0),
        ]);
    }
}
