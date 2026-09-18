<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests;

use Bedriox\Server\Application;
use PHPUnit\Framework\TestCase;

final class ApplicationTest extends TestCase
{
    public function testDisplayNameContainsProductVersionAndCompany(): void
    {
        $displayName = (new Application())->displayName();

        self::assertStringContainsString('Bedriox', $displayName);
        self::assertStringContainsString(Application::VERSION, $displayName);
        self::assertSame('Bedriox 0.1.0-alpha.1', $displayName);
    }

    public function testInvalidServeConfigurationFailsClosedWithoutEchoingInput(): void
    {
        $stdout = '';
        $stderr = '';
        $exitCode = (new Application())->run(
            ['serve', '--auth=secret-value'],
            static function (string $message) use (&$stdout): void {
                $stdout .= $message;
            },
            static function (string $message) use (&$stderr): void {
                $stderr .= $message;
            },
        );

        self::assertSame(1, $exitCode);
        self::assertSame('', $stdout);
        self::assertStringContainsString('startup failed closed', $stderr);
        self::assertStringNotContainsString('secret-value', $stderr);
    }
}
