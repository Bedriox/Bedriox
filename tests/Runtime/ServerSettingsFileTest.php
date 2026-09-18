<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

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
            self::assertSame('Bedriox Server', $values['server.name']);
            self::assertSame('default', $values['level.generator']);
            self::assertSame('4', $values['chunks.view-distance']);
            self::assertSame('6000', $values['level.autosave-interval-ticks']);
            self::assertSame('8', $values['chunks.save-per-tick']);
            self::assertStringContainsString('# level.spawn-x=', (string) file_get_contents($path));

            file_put_contents($path, "server.name=Preserved\n");
            self::assertSame(['server.name' => 'Preserved'], $settings->loadOrCreate($path));
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
            \Bedriox\Server\Runtime\ServerConfig::fromSettingsFile($path, []);
        } finally {
            @unlink($path);
        }
    }
}
