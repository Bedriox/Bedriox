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

namespace Bedriox\Server\Tests\ResourcePack;

use Bedriox\Server\ResourcePack\ResourcePackArchive;
use Bedriox\Server\ResourcePack\ResourcePackCatalogLoader;
use Bedriox\Server\ResourcePack\ResourcePackException;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class ResourcePackCatalogLoaderTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $this->removeDirectory($directory);
        }
    }

    public function testLoadsBoundedArchiveAndPublishesImmutableSnapshot(): void
    {
        $directory = $this->temporaryDirectory();
        $id = 'b8f70970-6f5f-4e58-bdf8-21a6f65ef001';
        $archive = $directory . DIRECTORY_SEPARATOR . 'space.mcpack';
        $payload = random_bytes(ResourcePackArchive::CHUNK_BYTES + 17);
        $this->archive($archive, $id, payload: $payload);
        file_put_contents($archive . '.key', str_repeat('k', 32));
        $this->configuration($directory, false, ['space.mcpack']);

        $catalog = (new ResourcePackCatalogLoader())->load($directory);

        self::assertFalse($catalog->required);
        self::assertSame(1, $catalog->count());
        $pack = $catalog->stack[0];
        self::assertSame($id, $pack->id);
        self::assertSame('1.2.3', $pack->version);
        self::assertSame(str_repeat('k', 32), $pack->contentKey);
        self::assertSame(2, $pack->chunkCount());
        self::assertFileExists($pack->snapshotPath);
        self::assertSame(hash_file('sha256', $archive, true), $pack->sha256);
        $archiveBytes = file_get_contents($archive);
        self::assertIsString($archiveBytes);
        self::assertSame(substr($archiveBytes, 0, ResourcePackArchive::CHUNK_BYTES), $pack->readChunk(0));
        self::assertSame(substr($archiveBytes, ResourcePackArchive::CHUNK_BYTES), $pack->readChunk(1));
    }

    public function testDependenciesAreValidatedAndOrderedBeforeDependants(): void
    {
        $directory = $this->temporaryDirectory();
        $baseId = '11111111-1111-4111-8111-111111111111';
        $overlayId = '22222222-2222-4222-8222-222222222222';
        $this->archive($directory . DIRECTORY_SEPARATOR . 'base.mcpack', $baseId);
        $this->archive(
            $directory . DIRECTORY_SEPARATOR . 'overlay.mcpack',
            $overlayId,
            dependencies: [['uuid' => $baseId, 'version' => [1, 2, 3]]],
        );
        $this->configuration($directory, true, ['overlay.mcpack', 'base.mcpack']);

        $catalog = (new ResourcePackCatalogLoader())->load($directory);

        self::assertTrue($catalog->required);
        self::assertSame([$baseId, $overlayId], array_map(
            static fn(ResourcePackArchive $pack): string => $pack->id,
            $catalog->stack,
        ));
    }

    public function testRejectsPathTraversalBeforeReadingArchive(): void
    {
        $directory = $this->temporaryDirectory();
        $this->configuration($directory, false, ['../outside.mcpack']);

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('relative filenames');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    public function testRejectsArchiveWithoutRootManifest(): void
    {
        $directory = $this->temporaryDirectory();
        $path = $directory . DIRECTORY_SEPARATOR . 'broken.mcpack';
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('nested/manifest.json', '{}'));
        self::assertTrue($zip->close());
        $this->configuration($directory, false, ['broken.mcpack']);

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('exactly one root manifest.json');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    public function testRejectsDuplicatePackIdentity(): void
    {
        $directory = $this->temporaryDirectory();
        $id = '33333333-3333-4333-8333-333333333333';
        $this->archive($directory . DIRECTORY_SEPARATOR . 'first.mcpack', $id);
        $this->archive($directory . DIRECTORY_SEPARATOR . 'second.mcpack', $id);
        $this->configuration($directory, false, ['first.mcpack', 'second.mcpack']);

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('duplicated');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    public function testRejectsMissingExactDependency(): void
    {
        $directory = $this->temporaryDirectory();
        $this->archive(
            $directory . DIRECTORY_SEPARATOR . 'dependent.mcpack',
            '44444444-4444-4444-8444-444444444444',
            dependencies: [[
                'uuid' => '55555555-5555-4555-8555-555555555555',
                'version' => [1, 0, 0],
            ]],
        );
        $this->configuration($directory, false, ['dependent.mcpack']);

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('requires unavailable');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    public function testRejectsWrongContentKeyLength(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = $directory . DIRECTORY_SEPARATOR . 'keyed.mcpack';
        $this->archive($archive, '66666666-6666-4666-8666-666666666666');
        file_put_contents($archive . '.key', str_repeat('k', 31));
        $this->configuration($directory, false, ['keyed.mcpack']);

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('exactly 32 bytes');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    public function testRepairsSameSizeCorruptCachedSnapshot(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = $directory . DIRECTORY_SEPARATOR . 'space.mcpack';
        $this->archive($archive, '77777777-7777-4777-8777-777777777777');
        $this->configuration($directory, false, ['space.mcpack']);
        $loader = new ResourcePackCatalogLoader();
        $first = $loader->load($directory)->stack[0];
        file_put_contents($first->snapshotPath, str_repeat('x', $first->size));

        $second = $loader->load($directory)->stack[0];

        self::assertSame(hash_file('sha256', $archive), hash_file('sha256', $second->snapshotPath));
    }

    public function testCacheCleanupRemovesOnlyOwnedStaleFiles(): void
    {
        $directory = $this->temporaryDirectory();
        $archive = $directory . DIRECTORY_SEPARATOR . 'space.mcpack';
        $this->archive($archive, '88888888-8888-4888-8888-888888888888');
        $this->configuration($directory, false, ['space.mcpack']);
        $loader = new ResourcePackCatalogLoader();
        $catalog = $loader->load($directory);
        $cache = $directory . DIRECTORY_SEPARATOR . '.cache';
        file_put_contents($cache . DIRECTORY_SEPARATOR . str_repeat('a', 64) . '.mcpack', 'stale');
        file_put_contents($cache . DIRECTORY_SEPARATOR . 'operator-note.txt', 'keep');

        $cleanup = $loader->cleanCache($cache, $catalog);

        self::assertSame(1, $cleanup->files);
        self::assertFileExists($catalog->stack[0]->snapshotPath);
        self::assertFileExists($cache . DIRECTORY_SEPARATOR . 'operator-note.txt');
    }

    public function testRejectsUnknownConfigurationKeys(): void
    {
        $directory = $this->temporaryDirectory();
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'resource_packs.yml',
            "required: false\nstack: []\nremote: true\n",
        );

        $this->expectException(ResourcePackException::class);
        $this->expectExceptionMessage('Unknown resource-pack option');
        (new ResourcePackCatalogLoader())->load($directory);
    }

    /**
     * @param list<array{uuid: string, version: list<int>}> $dependencies
     */
    private function archive(string $path, string $id, string $payload = 'fixture', array $dependencies = []): void
    {
        $manifest = json_encode([
            'format_version' => 2,
            'header' => [
                'name' => 'Fixture',
                'description' => 'Fixture',
                'uuid' => $id,
                'version' => [1, 2, 3],
                'min_engine_version' => [1, 21, 0],
            ],
            'modules' => [[
                'type' => 'resources',
                'uuid' => $this->moduleId($id),
                'version' => [1, 2, 3],
            ]],
            'dependencies' => $dependencies,
        ], JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('manifest.json', $manifest));
        self::assertTrue($zip->addFromString('payload.bin', $payload));
        self::assertTrue($zip->close());
    }

    /** @param list<string> $stack */
    private function configuration(string $directory, bool $required, array $stack): void
    {
        $lines = ['required: ' . ($required ? 'true' : 'false'), 'stack:'];
        foreach ($stack as $source) {
            $lines[] = '  - ' . $source;
        }
        file_put_contents(
            $directory . DIRECTORY_SEPARATOR . 'resource_packs.yml',
            implode("\n", $lines) . "\n",
        );
    }

    private function temporaryDirectory(): string
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-resource-pack-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0o775));
        $this->temporaryDirectories[] = $directory;
        return $directory;
    }

    private function moduleId(string $headerId): string
    {
        return substr($headerId, 0, -12) . strrev(substr($headerId, -12));
    }

    private function removeDirectory(string $directory): void
    {
        if (!is_dir($directory)) {
            return;
        }
        $entries = scandir($directory);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
