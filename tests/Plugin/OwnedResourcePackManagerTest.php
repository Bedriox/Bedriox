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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\ResourcePack\ResourcePackDefinition;
use Bedriox\Server\Plugin\OwnedResourcePackManager;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\ResourcePack\ResourcePackCatalog;
use Bedriox\Server\ResourcePack\ResourcePackRegistry;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class OwnedResourcePackManagerTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-pack-api-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o775, true));
    }

    protected function tearDown(): void
    {
        $this->delete($this->directory);
    }

    public function testRegistrationChangesOnlyFutureSnapshotsAndCleansUpWithOwner(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $registry = new ResourcePackRegistry(
            ResourcePackCatalog::empty(),
            $this->directory . DIRECTORY_SEPARATOR . 'cache',
        );
        $manager = new OwnedResourcePackManager('Example', $registry, $ownership);
        $before = $registry->snapshot();
        $info = $manager->register(new ResourcePackDefinition(
            $this->pack('edabf4fc-8e76-4bbf-88d2-ffcad26afe50', '1.2.3'),
            required: true,
        ));

        self::assertTrue($before->isEmpty());
        self::assertSame('edabf4fc-8e76-4bbf-88d2-ffcad26afe50', $info->uuid);
        self::assertSame('Example', $info->owner);
        self::assertTrue($registry->snapshot()->required);
        self::assertCount(1, $manager->all());
        self::assertSame(1, $ownership->count('Example'));

        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertTrue($registry->snapshot()->isEmpty());
    }

    public function testForeignOwnerCannotRemoveRegisteredPack(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $registry = new ResourcePackRegistry(
            ResourcePackCatalog::empty(),
            $this->directory . DIRECTORY_SEPARATOR . 'cache',
        );
        $owner = new OwnedResourcePackManager('Owner', $registry, $ownership);
        $foreign = new OwnedResourcePackManager('Foreign', $registry, $ownership);
        $info = $owner->register(new ResourcePackDefinition(
            $this->pack('6c910ed4-ddf7-4ed4-a54d-820d44d09bd3', '1.0.0'),
        ));

        $this->expectException(PluginException::class);
        $foreign->remove($info->uuid);
    }

    public function testRegistrationIsRejectedAfterStartupSeal(): void
    {
        $ownership = new PluginOwnershipRegistry();
        $registry = new ResourcePackRegistry(
            ResourcePackCatalog::empty(),
            $this->directory . DIRECTORY_SEPARATOR . 'cache',
        );
        $registry->seal();
        $manager = new OwnedResourcePackManager('Example', $registry, $ownership);

        $this->expectException(PluginException::class);
        $this->expectExceptionMessage('only while plugins are starting');
        $manager->register(new ResourcePackDefinition(
            $this->pack('9d8f80e5-127a-4a75-a287-ed0abf775aa9', '1.0.0'),
        ));
    }

    private function pack(string $uuid, string $version): string
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . $uuid . '.mcpack';
        $parts = array_map(intval(...), explode('.', $version));
        $manifest = json_encode([
            'format_version' => 2,
            'header' => [
                'name' => 'Plugin fixture',
                'description' => 'Test',
                'uuid' => $uuid,
                'version' => $parts,
                'min_engine_version' => [1, 26, 60],
            ],
            'modules' => [[
                'type' => 'resources',
                'uuid' => '75e71c8c-df12-4192-85ef-e3b4f7146d63',
                'version' => $parts,
            ]],
        ], JSON_THROW_ON_ERROR);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        self::assertTrue($zip->addFromString('manifest.json', $manifest));
        self::assertTrue($zip->addFromString('textures/test.txt', 'fixture'));
        self::assertTrue($zip->close());

        return $path;
    }

    private function delete(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (!is_array($entries)) {
            return;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($child)) {
                $this->delete($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
