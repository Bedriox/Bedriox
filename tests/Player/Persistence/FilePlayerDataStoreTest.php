<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player\Persistence;

use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataWriteException;
use Bedriox\Server\Player\Persistence\FilePlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerDataCodec;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Storage\AtomicFileWriter;
use PHPUnit\Framework\TestCase;

final class FilePlayerDataStoreTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-player-data-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testMissingProfileIsDistinctFromCorruptProfile(): void
    {
        $store = new FilePlayerDataStore($this->directory);
        self::assertFalse($store->exists(self::UUID));
        self::assertNull($store->load(self::UUID));

        file_put_contents($this->path(), 'not nbt');
        $this->expectException(CorruptPlayerDataException::class);
        $store->load(self::UUID);
    }

    public function testSavesLoadsAndAtomicallyReplacesProfileWithoutTemporaryLeaks(): void
    {
        $store = new FilePlayerDataStore($this->directory);
        $store->save(self::profile('First'));
        $store->save(self::profile('Second'));

        self::assertTrue($store->exists(self::UUID));
        self::assertSame('Second', $store->load(self::UUID)?->identity->displayName);
        self::assertSame([], glob($this->path() . '.tmp-*') ?: []);
    }

    public function testFailedAtomicPublicationPreservesPreviousProfile(): void
    {
        $store = new FilePlayerDataStore($this->directory);
        $store->save(self::profile('Original'));
        $before = file_get_contents($this->path());
        $failing = new class implements AtomicFileWriter {
            public function write(string $path, string $contents): void
            {
                throw new \RuntimeException('simulated write failure');
            }
        };

        try {
            (new FilePlayerDataStore($this->directory, writer: $failing))->save(self::profile('Replacement'));
            self::fail('Failed atomic write was reported as successful.');
        } catch (PlayerDataWriteException) {
        }
        self::assertSame($before, file_get_contents($this->path()));
    }

    public function testFilenameIdentityMismatchAndUnsafeKeysAreRejectedWithoutChangingFile(): void
    {
        self::assertTrue(mkdir($this->directory));
        $other = new PlayerBootstrap(
            new PlayerIdentity('aaaaaaaa-aaaa-4aaa-8aaa-aaaaaaaaaaaa', 'Other', '1'),
            'world',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            new PlayerInventoryState([], 0),
            1,
            1,
        );
        file_put_contents($this->path(), (new PlayerDataCodec())->encode($other));
        try {
            (new FilePlayerDataStore($this->directory))->load(self::UUID);
            self::fail('A mismatched profile identity was accepted.');
        } catch (CorruptPlayerDataException) {
        }
        self::assertFileExists($this->path());

        $this->expectException(CorruptPlayerDataException::class);
        (new FilePlayerDataStore($this->directory))->load('../escape');
    }

    public function testOversizedProfileIsRejectedBeforeDecodeAndPreserved(): void
    {
        self::assertTrue(mkdir($this->directory));
        $contents = str_repeat('x', PlayerDataCodec::MAX_BYTES + 1);
        file_put_contents($this->path(), $contents);

        try {
            (new FilePlayerDataStore($this->directory))->load(self::UUID);
            self::fail('Oversized profile was accepted.');
        } catch (CorruptPlayerDataException) {
        }
        self::assertSame($contents, file_get_contents($this->path()));
    }

    private const string UUID = '12345678-1234-5678-9abc-123456789abc';

    private function path(): string
    {
        return $this->directory . DIRECTORY_SEPARATOR . self::UUID . '.dat';
    }

    private static function profile(string $name): PlayerBootstrap
    {
        return new PlayerBootstrap(
            new PlayerIdentity(self::UUID, $name, '123'),
            'world',
            new Position(1.0, 65.0, 2.0),
            10.0,
            5.0,
            new PlayerInventoryState([], 0),
            100,
            200,
        );
    }
}
