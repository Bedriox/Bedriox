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

use Bedriox\Server\Plugin\PluginPackageLoader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

final class PluginPackageLoaderTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-plugin-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->directory, 0o775, true));
    }

    protected function tearDown(): void
    {
        if (!is_dir($this->directory)) {
            return;
        }
        $entries = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->directory, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        /** @var SplFileInfo $entry */
        foreach ($entries as $entry) {
            $entry->isDir() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->directory);
    }

    public function testDiscoversPharWithoutExecutingPluginCodeAndIgnoresDirectories(): void
    {
        $marker = $this->directory . DIRECTORY_SEPARATOR . 'executed.txt';
        $source = '<?php declare(strict_types=1); file_put_contents(' . var_export($marker, true) . ", 'yes'); final class Main {}";
        $this->buildPhar('valid.phar', $this->manifest(), $source);
        mkdir($this->directory . DIRECTORY_SEPARATOR . 'DirectoryPlugin');

        $packages = (new PluginPackageLoader())->discover($this->directory);

        self::assertCount(1, $packages);
        self::assertSame('FixturePlugin', $packages[0]->manifest->name);
        self::assertStringEndsWith('valid.phar', $packages[0]->archive);
        self::assertFileDoesNotExist($marker);
    }

    public function testRejectsUnsupportedApiAndSerializedMetadataIndependently(): void
    {
        $this->buildPhar('unsupported.phar', str_replace('"^0.3"', '"^2.0"', $this->manifest()), '<?php');
        $this->buildPhar('metadata.phar', $this->manifest('MetadataPlugin'), '<?php', true);
        $failures = [];

        $packages = (new PluginPackageLoader())->discover(
            $this->directory,
            static function (string $archive, \Throwable $failure) use (&$failures): void {
                $failures[$archive] = $failure::class;
            },
        );

        self::assertSame([], $packages);
        self::assertSame(['metadata.phar', 'unsupported.phar'], array_keys($failures));
    }

    public function testRejectsWeakArchiveSignature(): void
    {
        $this->buildPhar('weak.phar', $this->manifest(), '<?php', false, \Phar::SHA1);
        $failures = [];

        $packages = (new PluginPackageLoader())->discover(
            $this->directory,
            static function (string $archive, \Throwable $failure) use (&$failures): void {
                $failures[$archive] = $failure->getMessage();
            },
        );

        self::assertSame([], $packages);
        self::assertStringContainsString('signature', $failures['weak.phar']);
    }

    public function testEnforcesArchiveCountBeforeInspection(): void
    {
        $this->buildPhar('first.phar', $this->manifest(), '<?php');
        $this->buildPhar('second.phar', $this->manifest('SecondPlugin'), '<?php');

        $this->expectException(\Bedriox\Server\Plugin\PluginException::class);
        $this->expectExceptionMessage('count');
        (new PluginPackageLoader(1))->discover($this->directory);
    }

    private function manifest(string $name = 'FixturePlugin'): string
    {
        return json_encode([
            'schema' => 1,
            'name' => $name,
            'version' => '1.0.0',
            'api' => '^0.3',
            'main' => "Fixture\\{$name}\\Main",
            'namespace' => "Fixture\\{$name}",
            'authors' => ['Bedriox Team'],
            'dependencies' => [],
            'softDependencies' => [],
            'load' => 'WORLD_READY',
        ], JSON_THROW_ON_ERROR);
    }

    private function buildPhar(
        string $name,
        string $manifest,
        string $source,
        bool $metadata = false,
        int $signature = \Phar::SHA256,
    ): void {
        $code = <<<'PHP'
$phar = new Phar($argv[1]);
$phar->startBuffering();
$phar['plugin.json'] = $argv[2];
$phar['src/Main.php'] = $argv[3];
if ($argv[4] === 'yes') {
    $phar->setMetadata(['unsafe' => new stdClass()]);
}
$phar->setSignatureAlgorithm((int) $argv[5]);
$phar->setStub('<?php __HALT_COMPILER();');
$phar->stopBuffering();
PHP;
        $process = proc_open([
            PHP_BINARY,
            '-d',
            'phar.readonly=0',
            '-r',
            $code,
            $this->directory . DIRECTORY_SEPARATOR . $name,
            $manifest,
            $source,
            $metadata ? 'yes' : 'no',
            (string) $signature,
        ], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        self::assertIsResource($process);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), $stdout . $stderr);
    }
}
