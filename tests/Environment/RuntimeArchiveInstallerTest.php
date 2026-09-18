<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Environment;

use Bedriox\Server\Environment\RuntimeArchiveInstaller;
use Bedriox\Server\Environment\RuntimeArtifactExpectation;
use Bedriox\Server\Environment\RuntimeIdentity;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use ZipArchive;

final class RuntimeArchiveInstallerTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-installer-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root . DIRECTORY_SEPARATOR . 'bin', 0700, true));
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bedriox', 'server-entry');
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'stale.dll', 'old-runtime');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    public function testFirstInstallReplacesUnmanagedEntriesAndPreservesServerEntry(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/php.exe' => 'runtime-executable',
            'bin/php.ini' => 'runtime-configuration',
        ]);
        $expected = $this->expectation($archive, $manifest, 'windows-x86_64', true);

        $this->installer(['curl'])->install($archive, $this->root, $expected);

        self::assertSame('server-entry', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bedriox'));
        self::assertSame('runtime-executable', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php.exe'));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'stale.dll');
        $this->assertNoTransactionDirectories();
    }

    public function testUpgradeValidatesAndReplacesPriorRuntimeInventory(): void
    {
        $this->writePriorManifest(['stale.dll' => 'old-runtime']);
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/php.exe' => 'new-runtime',
        ]);

        $this->installer(['curl'])->install(
            $archive,
            $this->root,
            $this->expectation($archive, $manifest, 'windows-x86_64', true),
        );

        self::assertSame('server-entry', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bedriox'));
        self::assertSame('new-runtime', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php.exe'));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'stale.dll');
        $this->assertNoTransactionDirectories();
    }

    public function testActivationFailureRemovesNewEntriesAndRestoresPriorRuntime(): void
    {
        $priorManifest = $this->writePriorManifest(['stale.dll' => 'old-runtime']);
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/php.exe' => 'new-runtime',
            'bin/php.ini' => 'new-configuration',
        ]);
        $moves = 0;
        $move = static function (string $source, string $destination) use (&$moves): bool {
            ++$moves;
            if ($moves === 4) {
                return false;
            }

            return @rename($source, $destination);
        };

        try {
            $this->installer(['curl'], $move)->install(
                $archive,
                $this->root,
                $this->expectation($archive, $manifest, 'windows-x86_64', true),
            );
            self::fail('The injected activation failure was not reported.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('existing runtime was restored', $exception->getMessage());
        }

        self::assertSame('server-entry', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bedriox'));
        self::assertSame('old-runtime', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'stale.dll'));
        self::assertSame($priorManifest, file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'runtime-manifest.json'));
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php.exe');
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php.ini');
        $this->assertNoTransactionDirectories();
    }

    public function testUpgradeRejectsEntryNotDeclaredByPriorManifest(): void
    {
        $this->writePriorManifest(['stale.dll' => 'old-runtime']);
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'unknown.dll', 'unknown');
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/php.exe' => 'new-runtime',
        ]);

        $this->assertRejected(
            $archive,
            $this->expectation($archive, $manifest, 'windows-x86_64', true),
            'inventory does not match',
        );
        self::assertSame('unknown', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'unknown.dll'));
    }

    public function testTarGzRuntimeIncludesUnixExtensionPolicy(): void
    {
        $manifest = $this->manifest('linux-x86_64', false);
        $archive = $this->tarGz('bedriox-runtime-linux-x86_64.tar.gz', [
            ['bin/runtime-manifest.json', '0', $manifest],
            ['bin/php', '0', 'runtime-executable'],
            ['bin/php.ini', '0', 'runtime-configuration'],
        ]);
        $expected = $this->expectation($archive, $manifest, 'linux-x86_64', false);

        $this->installer(['curl', 'pcntl'])->install($archive, $this->root, $expected);

        self::assertSame('runtime-executable', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'php'));
        self::assertSame(['curl', 'pcntl'], $expected->extensions);
        $this->assertNoTransactionDirectories();
    }

    public function testArchiveTraversalIsRejectedWithoutChangingBin(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/../escape.txt' => 'escape',
        ]);

        $this->assertRejected($archive, $this->expectation($archive, $manifest, 'windows-x86_64', true), 'unsafe on Windows');
        self::assertFileDoesNotExist($this->root . DIRECTORY_SEPARATOR . 'escape.txt');
    }

    public function testZipLinkIsRejectedWithoutChangingBin(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/link' => 'php.exe',
        ], 'bin/link');

        $this->assertRejected($archive, $this->expectation($archive, $manifest, 'windows-x86_64', true), 'link or special');
    }

    public function testCaseCollisionAndUnsafeWindowsNameAreRejected(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $collision = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/Extension.dll' => 'one',
            'bin/extension.dll' => 'two',
        ]);
        $this->assertRejected(
            $collision,
            $this->expectation($collision, $manifest, 'windows-x86_64', true),
            'case-colliding',
        );

        $unsafe = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/CON.txt' => 'unsafe',
        ]);
        $this->assertRejected(
            $unsafe,
            $this->expectation($unsafe, $manifest, 'windows-x86_64', true),
            'unsafe on Windows',
        );
    }

    public function testDuplicateTarPathIsRejected(): void
    {
        $manifest = $this->manifest('linux-x86_64', false);
        $archive = $this->tarGz('bedriox-runtime-linux-x86_64.tar.gz', [
            ['bin/runtime-manifest.json', '0', $manifest],
            ['bin/php', '0', 'first'],
            ['bin/php', '0', 'second'],
        ]);

        $this->assertRejected($archive, $this->expectation($archive, $manifest, 'linux-x86_64', false), 'duplicate');
    }

    public function testManifestOrRuntimeIdentityFailureLeavesExistingBinUntouched(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $wrongManifest = $this->manifest('linux-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $wrongManifest,
            'bin/php.exe' => 'runtime-executable',
        ]);
        $this->assertRejected(
            $archive,
            $this->expectation($archive, $wrongManifest, 'windows-x86_64', true, hash('sha256', $wrongManifest)),
            'manifest identity',
        );

        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
            'bin/php.exe' => 'runtime-executable',
        ]);
        $expected = $this->expectation($archive, $manifest, 'windows-x86_64', true);
        try {
            $this->installer([])->install($archive, $this->root, $expected);
            self::fail('A runtime missing its locked extension was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString('locked extension set', $exception->getMessage());
        }
        $this->assertOriginalBin();
    }

    public function testArtifactHashMismatchFailsBeforeExtraction(): void
    {
        $manifest = $this->manifest('windows-x86_64', true);
        $archive = $this->zip('bedriox-runtime-windows-x86_64.zip', [
            'bin/runtime-manifest.json' => $manifest,
        ]);
        $expected = $this->expectation($archive, $manifest, 'windows-x86_64', true);
        file_put_contents($archive, 'tampered', FILE_APPEND);

        $this->assertRejected($archive, $expected, 'size does not match');
    }

    /** @param list<string> $extensions */
    private function installer(array $extensions, ?\Closure $move = null): RuntimeArchiveInstaller
    {
        return new RuntimeArchiveInstaller(
            static fn(string $runtimeRoot, RuntimeArtifactExpectation $expected): RuntimeIdentity => new RuntimeIdentity(
                $expected->phpVersion,
                $expected->threadSafe,
                $extensions,
            ),
            $move,
        );
    }

    /** @param array<string, string> $files */
    private function writePriorManifest(array $files): string
    {
        $inventory = [];
        foreach ($files as $relative => $contents) {
            $path = $this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR
                . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_dir(dirname($path))) {
                self::assertTrue(mkdir(dirname($path), 0700, true));
            }
            file_put_contents($path, $contents);
            $inventory[$relative] = ['bytes' => strlen($contents), 'sha256' => hash('sha256', $contents)];
        }
        ksort($inventory, SORT_STRING);
        $manifest = json_encode([
            'schemaVersion' => 2,
            'target' => 'windows-x86_64',
            'architecture' => 'AMD64',
            'phpVersion' => '8.4.24',
            'threadSafe' => true,
            'compiler' => 'test',
            'linker' => 'test',
            'buildPolicy' => ['test'],
            'sources' => [],
            'files' => $inventory,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        file_put_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'runtime-manifest.json', $manifest);

        return $manifest;
    }

    private function manifest(string $target, bool $threadSafe): string
    {
        return json_encode([
            'schemaVersion' => 2,
            'target' => $target,
            'phpVersion' => '8.4.25',
            'threadSafe' => $threadSafe,
        ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
    }

    /** @param array<string, string> $entries */
    private function zip(string $name, array $entries, ?string $link = null): string
    {
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        $zip = new ZipArchive();
        self::assertTrue($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        foreach ($entries as $entry => $contents) {
            self::assertTrue($zip->addFromString($entry, $contents));
        }
        if ($link !== null) {
            self::assertTrue($zip->setExternalAttributesName($link, ZipArchive::OPSYS_UNIX, 0120777 << 16));
        }
        self::assertTrue($zip->close());

        return $path;
    }

    /** @param list<array{string, string, string}> $entries */
    private function tarGz(string $name, array $entries): string
    {
        $tar = '';
        foreach ($entries as [$entry, $type, $contents]) {
            $size = $type === '0' ? strlen($contents) : 0;
            $header = str_repeat("\0", 512);
            $header = substr_replace($header, str_pad($entry, 100, "\0"), 0, 100);
            $header = substr_replace($header, $this->tarOctal(0644, 8), 100, 8);
            $header = substr_replace($header, $this->tarOctal(0, 8), 108, 8);
            $header = substr_replace($header, $this->tarOctal(0, 8), 116, 8);
            $header = substr_replace($header, $this->tarOctal($size, 12), 124, 12);
            $header = substr_replace($header, $this->tarOctal(0, 12), 136, 12);
            $header = substr_replace($header, str_repeat(' ', 8), 148, 8);
            $header[156] = $type;
            $header = substr_replace($header, "ustar\0", 257, 6);
            $header = substr_replace($header, '00', 263, 2);
            $checksum = 0;
            for ($index = 0; $index < 512; ++$index) {
                $checksum += ord($header[$index]);
            }
            $header = substr_replace($header, sprintf('%06o', $checksum) . "\0 ", 148, 8);
            $tar .= $header . ($type === '0' ? $contents . str_repeat("\0", (512 - ($size % 512)) % 512) : '');
        }
        $encoded = gzencode($tar . str_repeat("\0", 1024), 9);
        self::assertIsString($encoded);
        $path = $this->root . DIRECTORY_SEPARATOR . $name;
        self::assertSame(strlen($encoded), file_put_contents($path, $encoded));

        return $path;
    }

    private function tarOctal(int $value, int $length): string
    {
        return str_pad(decoct($value), $length - 1, '0', STR_PAD_LEFT) . "\0";
    }

    private function expectation(
        string $archive,
        string $manifest,
        string $target,
        bool $threadSafe,
        ?string $manifestHash = null,
    ): RuntimeArtifactExpectation {
        $lock = [
            'schema' => 2,
            'runtime' => [
                'commit' => str_repeat('a', 40),
                'manifestSchema' => 2,
                'phpVersion' => '8.4.25',
                'extensions' => ['all' => ['curl'], 'unix' => ['pcntl']],
                'artifacts' => [
                    $target => [
                        'filename' => basename($archive),
                        'size' => filesize($archive),
                        'sha256' => hash_file('sha256', $archive),
                        'manifestSha256' => $manifestHash ?? hash('sha256', $manifest),
                        'threadSafe' => $threadSafe,
                    ],
                ],
            ],
        ];
        $path = $this->root . DIRECTORY_SEPARATOR . 'installer.lock.json';
        file_put_contents($path, json_encode($lock, JSON_THROW_ON_ERROR));

        return RuntimeArtifactExpectation::fromLockFile($path, $target);
    }

    private function assertRejected(
        string $archive,
        RuntimeArtifactExpectation $expected,
        string $message,
    ): void {
        try {
            $this->installer($expected->extensions)->install($archive, $this->root, $expected);
            self::fail('The unsafe runtime archive was accepted.');
        } catch (RuntimeException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertOriginalBin();
    }

    private function assertOriginalBin(): void
    {
        self::assertSame('server-entry', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'bedriox'));
        self::assertSame('old-runtime', file_get_contents($this->root . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'stale.dll'));
        $this->assertNoTransactionDirectories();
    }

    private function assertNoTransactionDirectories(): void
    {
        $entries = scandir($this->root);
        self::assertIsArray($entries);
        self::assertSame([], array_values(array_filter(
            $entries,
            static fn(string $entry): bool => str_starts_with($entry, '.runtime-stage-')
                || str_starts_with($entry, '.runtime-backup-'),
        )));
    }

    private function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $entries = scandir($path);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry !== '.' && $entry !== '..') {
                    $this->removeTree($path . DIRECTORY_SEPARATOR . $entry);
                }
            }
        }
        @rmdir($path);
    }
}
