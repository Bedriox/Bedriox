<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Environment;

use PHPUnit\Framework\TestCase;

final class PackagedLauncherTest extends TestCase
{
    public function testBootstrapValidatesBeforeComposerAutoload(): void
    {
        $bootstrap = $this->read('bootstrap/bedriox.php');
        $validation = strpos($bootstrap, 'RuntimeEnvironmentValidator::validate');
        $autoload = strpos($bootstrap, "vendor/autoload.php");

        self::assertIsInt($validation);
        self::assertIsInt($autoload);
        self::assertLessThan($autoload, $validation);
    }

    public function testWindowsLauncherUsesOnlyAdjacentRuntimeAndForwardsArguments(): void
    {
        $launcher = $this->read('bedriox.cmd');

        self::assertStringContainsString('%~dp0', $launcher);
        self::assertStringContainsString('%BEDRIOX_RUNTIME_ROOT%\\php.exe', $launcher);
        self::assertStringContainsString('-c "%BEDRIOX_RUNTIME_ROOT%\\php.ini"', $launcher);
        self::assertStringContainsString('%*', $launcher);
        self::assertStringNotContainsString('where php', strtolower($launcher));
        self::assertStringNotContainsString(' php ', strtolower($launcher));
    }

    public function testUnixLauncherUsesOnlyAdjacentRuntimePreservesCwdAndForwardsArguments(): void
    {
        $launcher = $this->read('bedriox');

        self::assertStringContainsString('BEDRIOX_ROOT=$(CDPATH= cd -- "$(dirname -- "$0")" && pwd -P)', $launcher);
        self::assertStringContainsString('exec "$BEDRIOX_RUNTIME_ROOT/php"', $launcher);
        self::assertStringContainsString('"$@"', $launcher);
        self::assertStringNotContainsString('cd "$BEDRIOX_ROOT"', $launcher);
        self::assertStringNotContainsString('command -v php', $launcher);
    }

    private function read(string $relative): string
    {
        $contents = file_get_contents(dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative));
        self::assertIsString($contents);

        return $contents;
    }
}
