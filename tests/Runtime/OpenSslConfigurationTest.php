<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Runtime\OpenSslConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpenSslConfigurationTest extends TestCase
{
    private ?string $temporaryDirectory = null;

    public function testExplicitReadableConfigurationTakesPrecedence(): void
    {
        self::assertSame(__FILE__, OpenSslConfiguration::discover('unused-php', __FILE__));
    }

    public function testPackagedRuntimeConfigurationIsDiscoveredAndCanGenerateP384(): void
    {
        $this->temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-openssl-' . bin2hex(random_bytes(8));
        $configurationDirectory = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'config';
        self::assertTrue(mkdir($configurationDirectory, 0o700, true));
        $phpBinary = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'php.exe';
        $expected = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        self::assertTrue(copy(dirname(__DIR__) . '/Fixtures/openssl.cnf', $expected));

        $discovered = OpenSslConfiguration::discover($phpBinary);
        self::assertSame($expected, $discovered);
        (new OpenSslEphemeralKeyFactory($discovered))->generate();
        self::addToAssertionCount(1);
    }

    public function testUnreadableConfiguredPathFailsClosed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OpenSslConfiguration::discover('unused-php', __DIR__ . '/missing-openssl.cnf');
    }

    protected function tearDown(): void
    {
        if ($this->temporaryDirectory === null) {
            return;
        }

        $configuration = $this->temporaryDirectory . DIRECTORY_SEPARATOR . 'config' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (is_file($configuration)) {
            unlink($configuration);
        }
        $configurationDirectory = dirname($configuration);
        if (is_dir($configurationDirectory)) {
            rmdir($configurationDirectory);
        }
        if (is_dir($this->temporaryDirectory)) {
            rmdir($this->temporaryDirectory);
        }
        $this->temporaryDirectory = null;
    }
}
