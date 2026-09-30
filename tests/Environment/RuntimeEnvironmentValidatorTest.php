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

namespace Bedriox\Server\Tests\Environment;

use Bedriox\Server\Environment\RuntimeEnvironmentException;
use Bedriox\Server\Environment\RuntimeEnvironmentValidator;
use Bedriox\Server\Environment\RuntimeProcessIdentity;
use PHPUnit\Framework\TestCase;

final class RuntimeEnvironmentValidatorTest extends TestCase
{
    private const string QUALIFIED_PHP_VERSION = '8.4.2';

    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-environment-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . DIRECTORY_SEPARATOR . 'bin', 0700, true));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testQualifiedAdjacentRuntimeIsAccepted(): void
    {
        $process = $this->writeFixture();

        $manifest = RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);

        self::assertSame(self::QUALIFIED_PHP_VERSION, $manifest->phpVersion);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/D', $manifest->sha256);
    }

    public function testExtractedApplicationLockMayValidateAdjacentDistributionRuntime(): void
    {
        $process = $this->writeFixture();
        $lock = $this->root . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR . 'application' . DIRECTORY_SEPARATOR . 'bedriox.lock.json';
        self::assertTrue(mkdir(dirname($lock), 0700, true));
        self::assertTrue(rename($this->root . DIRECTORY_SEPARATOR . 'bedriox.lock.json', $lock));

        $manifest = RuntimeEnvironmentValidator::validate(
            $this->root,
            $this->root . DIRECTORY_SEPARATOR . 'bin',
            $process,
            $lock,
        );

        self::assertSame(self::QUALIFIED_PHP_VERSION, $manifest->phpVersion);
    }

    public function testManifestMustMatchExternalLockHash(): void
    {
        $process = $this->writeFixture();
        $lockPath = $this->root . DIRECTORY_SEPARATOR . 'bedriox.lock.json';
        $lock = file_get_contents($lockPath);
        self::assertIsString($lock);
        $changed = preg_replace('/"manifestSha256":"[0-9a-f]{64}"/D', '"manifestSha256":"' . str_repeat('0', 64) . '"', $lock);
        self::assertIsString($changed);
        file_put_contents($lockPath, $changed);

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('trusted integration lock');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testChangedRuntimeFileIsRejected(): void
    {
        $process = $this->writeFixture();
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php.ini', "changed\n");

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('file inventory');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testUnexpectedRuntimeFileIsRejected(): void
    {
        $process = $this->writeFixture();
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'unexpected.dll', 'untrusted');

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('file inventory');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testAmbientRuntimeRootIsRejected(): void
    {
        $process = $this->writeFixture();

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('BEDRIOX_RUNTIME_ROOT');
        RuntimeEnvironmentValidator::validate($this->root, dirname($this->root), $process);
    }

    public function testAmbientIniScanIsRejected(): void
    {
        $process = $this->writeFixture();
        $process = new RuntimeProcessIdentity(
            $process->binary,
            $process->version,
            $process->threadSafe,
            $process->osFamily,
            $process->machine,
            $process->sapi,
            $process->integerSize,
            $process->loadedIni,
            'ambient.ini',
            $process->extensions,
        );

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('Additional scanned');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testDifferentPhpBinaryIsRejected(): void
    {
        $process = $this->writeFixture();
        $other = $this->root . DIRECTORY_SEPARATOR . 'other-php';
        file_put_contents($other, 'ambient-php');
        $process = new RuntimeProcessIdentity(
            $other,
            $process->version,
            $process->threadSafe,
            $process->osFamily,
            $process->machine,
            $process->sapi,
            $process->integerSize,
            $process->loadedIni,
            $process->scannedIni,
            $process->extensions,
        );

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('adjacent packaged PHP');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testDifferentPhpVersionIsRejected(): void
    {
        $process = $this->writeFixture();
        $process = new RuntimeProcessIdentity(
            $process->binary,
            '8.4.999',
            $process->threadSafe,
            $process->osFamily,
            $process->machine,
            $process->sapi,
            $process->integerSize,
            $process->loadedIni,
            $process->scannedIni,
            $process->extensions,
        );

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('running PHP identity');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    public function testMissingRequiredExtensionIsRejected(): void
    {
        $process = $this->writeFixture();
        $extensions = array_values(array_filter($process->extensions, static fn(string $extension): bool => $extension !== 'leveldb'));
        $process = new RuntimeProcessIdentity(
            $process->binary,
            $process->version,
            $process->threadSafe,
            $process->osFamily,
            $process->machine,
            $process->sapi,
            $process->integerSize,
            $process->loadedIni,
            $process->scannedIni,
            $extensions,
        );

        $this->expectException(RuntimeEnvironmentException::class);
        $this->expectExceptionMessage('extension set');
        RuntimeEnvironmentValidator::validate($this->root, $this->root . DIRECTORY_SEPARATOR . 'bin', $process);
    }

    private function writeFixture(): RuntimeProcessIdentity
    {
        $bin = $this->root . DIRECTORY_SEPARATOR . 'bin';
        $executable = $bin . DIRECTORY_SEPARATOR . (PHP_OS_FAMILY === 'Windows' ? 'php.exe' : 'php');
        $ini = $bin . DIRECTORY_SEPARATOR . 'php.ini';
        file_put_contents($executable, 'qualified-php');
        file_put_contents($ini, "qualified=true\n");
        file_put_contents($bin . DIRECTORY_SEPARATOR . 'bedriox', 'server-entry');

        $files = [];
        foreach ([basename($executable), 'php.ini'] as $relative) {
            $path = $bin . DIRECTORY_SEPARATOR . $relative;
            $files[$relative] = ['bytes' => filesize($path), 'sha256' => hash_file('sha256', $path)];
        }
        ksort($files, SORT_STRING);
        $manifest = [
            'schemaVersion' => 2,
            'target' => $this->target(),
            'architecture' => php_uname('m'),
            'phpVersion' => self::QUALIFIED_PHP_VERSION,
            'threadSafe' => (bool) PHP_ZTS,
            'compiler' => 'test compiler',
            'linker' => 'test linker',
            'buildPolicy' => ['qualified'],
            'sources' => ['php' => ['version' => self::QUALIFIED_PHP_VERSION]],
            'files' => $files,
        ];
        $manifestJson = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($bin . DIRECTORY_SEPARATOR . 'runtime-manifest.json', $manifestJson);
        $extensions = [
            'ctype', 'curl', 'dom', 'fileinfo', 'filter', 'gmp', 'hash', 'iconv', 'json', 'leveldb', 'libxml',
            'mbstring', 'openssl', 'pdo', 'pdo_sqlite', 'phar', 'session', 'simplexml', 'sockets', 'sodium',
            'sqlite3', 'tokenizer', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib', 'Zend OPcache',
        ];
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bedriox.lock.json', json_encode([
            'schema' => 2,
            'runtime' => [
                'commit' => str_repeat('a', 40),
                'manifestSchema' => 2,
                'phpVersion' => self::QUALIFIED_PHP_VERSION,
                'extensions' => ['all' => $extensions, 'unix' => []],
                'artifacts' => [
                    $this->target() => [
                        'filename' => str_starts_with($this->target(), 'windows-') ? 'runtime.zip' : 'runtime.tar.gz',
                        'size' => 1,
                        'sha256' => str_repeat('b', 64),
                        'manifestSha256' => hash('sha256', $manifestJson),
                        'threadSafe' => (bool) PHP_ZTS,
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR));

        return new RuntimeProcessIdentity(
            $executable,
            self::QUALIFIED_PHP_VERSION,
            (bool) PHP_ZTS,
            PHP_OS_FAMILY,
            php_uname('m'),
            'cli',
            8,
            $ini,
            false,
            $extensions,
        );
    }

    private function target(): string
    {
        $operatingSystem = match (PHP_OS_FAMILY) {
            'Windows' => 'windows',
            'Darwin' => 'macos',
            default => 'linux',
        };
        $architecture = in_array(strtolower(php_uname('m')), ['arm64', 'aarch64'], true) ? 'arm64' : 'x86_64';

        return $operatingSystem . '-' . $architecture;
    }

    private function removeTree(string $root): void
    {
        if (!is_dir($root)) {
            return;
        }
        $entries = scandir($root);
        self::assertIsArray($entries);
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $root . DIRECTORY_SEPARATOR . $entry;
            if (is_dir($path) && !is_link($path)) {
                $this->removeTree($path);
            } else {
                self::assertTrue(unlink($path));
            }
        }
        self::assertTrue(rmdir($root));
    }
}
