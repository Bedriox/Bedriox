<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Runtime\OpenSslConfiguration;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class OpenSslConfigurationTest extends TestCase
{
    public function testExplicitReadableConfigurationTakesPrecedence(): void
    {
        self::assertSame(__FILE__, OpenSslConfiguration::discover('unused-php', __FILE__));
    }

    public function testWindowsStylePhpDistributionConfigurationIsDiscovered(): void
    {
        $phpBinary = dirname(PHP_BINARY) . DIRECTORY_SEPARATOR . 'php.exe';
        $expected = dirname($phpBinary) . DIRECTORY_SEPARATOR . 'extras' . DIRECTORY_SEPARATOR . 'ssl' . DIRECTORY_SEPARATOR . 'openssl.cnf';
        if (!is_file($expected)) {
            self::markTestSkipped('The test environment does not use a PHP distribution with extras/ssl/openssl.cnf.');
        }

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
}
