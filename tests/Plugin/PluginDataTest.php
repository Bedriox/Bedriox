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

use Bedriox\Api\Plugin\Data\PluginDataException;
use Bedriox\Server\Plugin\Data\ArrayPluginResourceProvider;
use Bedriox\Server\Plugin\Data\FilePluginData;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PluginDataTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-plugin-data-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0o775, true);
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testCopiesResourcesWithoutReplacingOperatorFiles(): void
    {
        $data = $this->data([
            'config.yml' => "enabled: true\n",
            'templates/welcome.txt' => 'Welcome',
        ]);

        self::assertSame(2, $data->saveResources());
        self::assertSame(0, $data->saveResources());
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . 'config.yml', "enabled: false\n");
        self::assertFalse($data->saveResource('config.yml'));
        self::assertSame("enabled: false\n", file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'config.yml'));
        self::assertTrue($data->saveResource('config.yml', true));
        self::assertSame("enabled: true\n", file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'config.yml'));
    }

    public function testLoadsTypedYamlAndPersistsNestedChanges(): void
    {
        $data = $this->data(['config.yml' => <<<'YAML'
welcome:
  enabled: true
  message: Welcome!
limits:
  homes: 5
ratio: 1.5
names:
  - Alex
YAML]);
        $config = $data->config();

        self::assertTrue($config->getBool('welcome.enabled'));
        self::assertSame('Welcome!', $config->getString('welcome.message'));
        self::assertSame(5, $config->getInt('limits.homes'));
        self::assertSame(1.5, $config->getFloat('ratio'));
        self::assertSame(['Alex'], $config->getList('names'));
        $config->set('welcome.message', 'Hello');
        $config->set('limits.cooldown', 20);
        $config->save();
        $config->reload();
        self::assertSame('Hello', $config->getString('welcome.message'));
        self::assertSame(20, $config->getInt('limits.cooldown'));
    }

    public function testReplacingAResourceReloadsAnOpenConfiguration(): void
    {
        $data = $this->data(['config.yml' => "enabled: true\n"]);
        $config = $data->config();
        $config->set('enabled', false);
        $config->save();

        self::assertTrue($data->saveResource('config.yml', true));
        self::assertTrue($config->getBool('enabled'));
        self::assertSame($config, $data->config());
    }

    public function testLoadsAndPersistsJson(): void
    {
        $config = $this->data(['storage.json' => "{\"enabled\":true}\n"])->config('storage.json');
        self::assertTrue($config->getBool('enabled'));
        $config->set('count', 3);
        $config->save();
        $decoded = json_decode((string) file_get_contents($this->directory . DIRECTORY_SEPARATOR . 'storage.json'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['enabled' => true, 'count' => 3], $decoded);
    }

    public function testRejectsTraversalUnsupportedFormatsAndMalformedValues(): void
    {
        $data = $this->data(['config.yml' => "enabled: [\n"]);
        foreach (['../secret.yml', '/absolute.yml', 'folder\\..\\secret.yml'] as $path) {
            try {
                $data->config($path);
                self::fail("Expected {$path} to be rejected.");
            } catch (PluginDataException) {
            }
        }
        $this->expectException(PluginDataException::class);
        $data->config('settings.ini');
    }

    public function testMalformedConfigurationIsAttributedAsPluginDataFailure(): void
    {
        $this->expectException(PluginDataException::class);
        $this->data(['config.yml' => "enabled: [\n"])->config();
    }

    public function testTypedGettersRejectWrongTypes(): void
    {
        $config = $this->data(['config.yml' => "enabled: 'true'\n"])->config();
        $this->expectException(PluginDataException::class);
        $config->getBool('enabled');
    }

    /** @param array<string, string> $resources */
    private function data(array $resources): FilePluginData
    {
        return new FilePluginData($this->directory, new ArrayPluginResourceProvider($resources));
    }
}
