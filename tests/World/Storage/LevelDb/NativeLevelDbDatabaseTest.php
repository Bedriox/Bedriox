<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\World\Storage\LevelDb;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\LevelDbWorldProvider;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\Storage\LevelDatStore;
use Bedriox\Server\World\Storage\LevelDb\NativeLevelDbDatabase;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class NativeLevelDbDatabaseTest extends TestCase
{
    public function testQualifiedExtensionPersistsAnAtomicBinaryBatchAcrossReopen(): void
    {
        if (!extension_loaded('leveldb')) {
            self::markTestSkipped('Native test requires the packaged Bedriox Runtime.');
        }
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-native-leveldb-' . bin2hex(random_bytes(8));
        try {
            $database = NativeLevelDbDatabase::open($directory, true);
            $database->writeBatch(["binary\0key" => "binary\0value", 'deleted' => 'value'], []);
            $database->writeBatch([], ['deleted']);
            $database->close();
            $database->close();

            $reopened = NativeLevelDbDatabase::open($directory, false);
            self::assertSame("binary\0value", $reopened->get("binary\0key"));
            self::assertNull($reopened->get('deleted'));
            $reopened->close();
        } finally {
            if (is_dir($directory)) {
                foreach (glob($directory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                    @unlink($file);
                }
                @rmdir($directory);
            }
        }
    }

    public function testNativeProviderCreatesAndReopensAProtocol2193World(): void
    {
        if (!extension_loaded('leveldb')) {
            self::markTestSkipped('Native test requires the packaged Bedriox Runtime.');
        }
        $root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-native-provider-' . bin2hex(random_bytes(8));
        $worldPath = $root . DIRECTORY_SEPARATOR . 'world';
        self::assertTrue(mkdir($root));
        try {
            $dataSet = BedrockDataSet::bundled();
            $registry = new BlockStateRegistry($dataSet->blockStateRegistry()->states());
            $provider = LevelDbWorldProvider::create(
                $worldPath,
                new WorldData(new WorldMetadata('Native World', 123), 'flat', new SpawnPosition(0, 64, 0)),
                $registry,
                $dataSet->persistentBlockStateRegistry(),
                createdAt: 1_234_567_890,
            );
            $chunk = (new FlatWorldGenerator(FixedFlatBlockPalette::fromRegistry($registry)))
                ->generate(new ChunkPosition(-1, -2));
            $provider->saveChunk(new ChunkSaveData($chunk));
            $provider->close();

            $metadata = (new LevelDatStore())->load($worldPath . DIRECTORY_SEPARATOR . 'level.dat');
            self::assertSame(2193, $metadata->networkVersion());
            self::assertSame(2, $metadata->root['Generator']->value);
            self::assertSame(2, $metadata->difficulty());
            self::assertSame(1_234_567_890, $metadata->root['LastPlayed']->value);
            $versionTag = $metadata->root['lastOpenedWithVersion'];
            self::assertSame(LittleEndianNbtTag::LIST, $versionTag->type);
            self::assertIsArray($versionTag->value);
            $version = [];
            foreach ($versionTag->value as $tag) {
                self::assertInstanceOf(LittleEndianNbtTag::class, $tag);
                self::assertIsInt($tag->value);
                $version[] = $tag->value;
            }
            self::assertSame([1, 26, 50, 0, 0], $version);
            $reopened = LevelDbWorldProvider::open(
                $worldPath,
                $registry,
                $dataSet->persistentBlockStateRegistry(),
            );
            $loaded = $reopened->loadChunk(new ChunkPosition(-1, -2));
            self::assertNotNull($loaded);
            self::assertSame($chunk->finalizationState, $loaded->chunk->finalizationState);
            self::assertSame(
                $chunk->blockStateAt(4, 63, 9)->value,
                $loaded->chunk->blockStateAt(4, 63, 9)->value,
            );
            $reopened->close();
        } finally {
            self::removeTree($root);
        }
    }

    private static function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof \SplFileInfo) {
                continue;
            }
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
