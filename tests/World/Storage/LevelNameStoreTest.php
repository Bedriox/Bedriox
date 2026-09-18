<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage;

use Bedriox\Server\World\Storage\AtomicFileWriter;
use Bedriox\Server\World\Storage\Exception\CorruptWorldDataException;
use Bedriox\Server\World\Storage\LevelNameStore;
use PHPUnit\Framework\TestCase;

final class LevelNameStoreTest extends TestCase
{
    public function testSynchronizesMissingAndStaleMirrorWithoutRewritingMatch(): void
    {
        $writer = new class implements AtomicFileWriter {
            public int $writes = 0;
            public function write(string $path, string $contents): void
            {
                ++$this->writes;
                file_put_contents($path, $contents);
            }
        };
        $path = tempnam(sys_get_temp_dir(), 'bedriox-levelname-');
        self::assertIsString($path);
        unlink($path);
        try {
            $store = new LevelNameStore($writer);
            $store->synchronize($path, 'First World');
            self::assertSame('First World', $store->load($path));
            $store->synchronize($path, 'First World');
            self::assertSame(1, $writer->writes);
            $store->synchronize($path, 'Renamed World');
            self::assertSame('Renamed World', file_get_contents($path));
            self::assertSame(2, $writer->writes);
        } finally {
            @unlink($path);
        }
    }

    public function testInvalidDiskMirrorIsClassifiedAsCorrupt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-levelname-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "bad\0name");
            $this->expectException(CorruptWorldDataException::class);
            (new LevelNameStore())->load($path);
        } finally {
            @unlink($path);
        }
    }
}
