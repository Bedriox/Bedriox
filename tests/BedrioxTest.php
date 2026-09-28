<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests;

use Bedriox\Server\Bedriox;
use Bedriox\Server\Runtime\ProcessMemoryLimit;
use PHPUnit\Framework\TestCase;

final class BedrioxTest extends TestCase
{
    public function testDisplayNameContainsProductAndVersion(): void
    {
        $displayName = (new Bedriox())->displayName();

        self::assertStringContainsString('Bedriox', $displayName);
        self::assertStringContainsString(Bedriox::VERSION, $displayName);
        self::assertSame('Bedriox 0.3.0-alpha.1', $displayName);
    }

    public function testInvalidServeConfigurationFailsClosedWithoutEchoingInput(): void
    {
        $originalDirectory = getcwd();
        self::assertIsString($originalDirectory);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-startup-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $stdout = '';
        $stderr = '';
        try {
            self::assertTrue(chdir($directory));
            $exitCode = (new Bedriox())->run(
                ['serve', '--auth=secret-value'],
                static function (string $message) use (&$stdout): void {
                    $stdout .= $message;
                },
                static function (string $message) use (&$stderr): void {
                    $stderr .= $message;
                },
            );
        } finally {
            self::assertTrue(chdir($originalDirectory));
            @unlink($directory . DIRECTORY_SEPARATOR . 'server.properties');
            @unlink($directory . DIRECTORY_SEPARATOR . 'bedriox.settings');
            @rmdir($directory);
        }

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('startup failed closed', $stderr);
        self::assertStringNotContainsString('secret-value', $stderr);
    }

    public function testServeAppliesDefaultMemoryLimitBeforeStartingServices(): void
    {
        $originalDirectory = getcwd();
        self::assertIsString($originalDirectory);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-memory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $applied = [];
        $memoryLimit = new ProcessMemoryLimit(
            static function (string $name, string $value) use (&$applied): false {
                $applied[] = [$name, $value];

                return false;
            },
            static fn(string $name): string => '-1',
        );
        try {
            self::assertTrue(chdir($directory));
            $exitCode = (new Bedriox(processMemoryLimit: $memoryLimit))->run(
                ['serve'],
                static function (string $message): void {},
                static function (string $message): void {},
            );
        } finally {
            self::assertTrue(chdir($originalDirectory));
            @unlink($directory . DIRECTORY_SEPARATOR . 'server.properties');
            @unlink($directory . DIRECTORY_SEPARATOR . 'bedriox.settings');
            @rmdir($directory);
        }

        self::assertSame(1, $exitCode);
        self::assertSame([['memory_limit', '500000000']], $applied);
    }
}
