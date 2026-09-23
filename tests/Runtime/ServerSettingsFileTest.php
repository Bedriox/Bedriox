<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\ServerPropertiesFile;
use Bedriox\Server\Runtime\ServerSettingsFile;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ServerSettingsFileTest extends TestCase
{
    public function testParsesCommentsWhitespaceAndEmptyOptionalValues(): void
    {
        $values = (new ServerSettingsFile())->parse(<<<'SETTINGS'
# comment
 server.name = Test Server
level.spawn-x=

SETTINGS);

        self::assertSame(['server.name' => 'Test Server', 'level.spawn-x' => ''], $values);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedFiles(): iterable
    {
        yield 'missing separator' => ['server.name'];
        yield 'empty key' => ['=value'];
        yield 'invalid key' => ['Server_Name=value'];
        yield 'duplicate' => ["server.name=one\nserver.name=two"];
        yield 'nul byte' => ["server.name=one\0two"];
        yield 'too many lines' => [str_repeat("#\n", ServerSettingsFile::MAX_LINES + 1)];
        yield 'too large' => [str_repeat('#', ServerSettingsFile::MAX_BYTES + 1)];
    }

    #[DataProvider('malformedFiles')]
    public function testRejectsMalformedAndUnboundedFiles(string $contents): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ServerSettingsFile())->parse($contents);
    }

    public function testCreatesDocumentedDefaultsWithoutOverwritingExistingFile(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-settings-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'bedriox.settings';
        try {
            $settings = new ServerSettingsFile();
            $values = $settings->loadOrCreate($path);
            self::assertSame('20', $values['runtime.ticks-per-second']);
            self::assertSame('auto', $values['workers.core-count']);
            self::assertSame('4', $values['chunk-sending.spawn-radius']);
            self::assertSame('8', $values['chunk-sending.per-tick']);
            self::assertSame('4', $values['chunk-generation.per-tick']);
            self::assertSame('1024', $values['chunk-generation.queue-size']);
            self::assertSame('1', $values['chunk-loading.prefetch-radius']);
            self::assertSame('auto', $values['chunk-cache.limit']);
            self::assertSame('6000', $values['level.autosave-interval-ticks']);
            self::assertSame('8', $values['chunk-saving.per-tick']);
            self::assertSame('6000', $values['players.autosave-interval-ticks']);
            self::assertSame('8', $values['players.save-per-tick']);
            self::assertSame('40', $values['movement.rewind-history-size']);
            self::assertStringContainsString('Common server settings belong in server.properties.', (string) file_get_contents($path));

            file_put_contents($path, "server.name=Preserved\n");
            self::assertSame(['server.name' => 'Preserved'], $settings->loadOrCreate($path));
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testCreatesUserFriendlyServerProperties(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-properties-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.properties';
        try {
            $values = (new ServerPropertiesFile())->loadOrCreate($path);

            self::assertSame('Bedriox Server', $values['server-name']);
            self::assertSame('0.0.0.0', $values['server-ip']);
            self::assertSame('500MB', $values['memory-limit']);
            self::assertSame('true', $values['xbox-auth']);
            self::assertSame('default', $values['level-type']);
            self::assertSame('4', $values['view-distance']);
            $contents = (string) file_get_contents($path);
            self::assertStringContainsString('# Server identity and network', $contents);
            self::assertStringContainsString('# Main process and access', $contents);
            self::assertStringContainsString('# World and gameplay', $contents);
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testUnknownSettingFailsDuringTypedConfiguration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-settings-');
        self::assertIsString($path);
        try {
            file_put_contents($path, "network.query-port=19133\n");
            $this->expectException(InvalidArgumentException::class);
            $properties = tempnam(sys_get_temp_dir(), 'bedriox-properties-');
            self::assertIsString($properties);
            file_put_contents($properties, '');
            try {
                \Bedriox\Server\Runtime\ServerConfig::fromConfigurationFiles($properties, $path, []);
            } finally {
                @unlink($properties);
            }
        } finally {
            @unlink($path);
        }
    }
}
