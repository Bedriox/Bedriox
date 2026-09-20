<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Environment;

use PHPUnit\Framework\TestCase;

final class ProductionBootstrapTest extends TestCase
{
    public function testProductionBootstrapUsesTheCurrentCompositionRoot(): void
    {
        $bootstrap = file_get_contents(dirname(__DIR__, 2) . '/bootstrap/bedriox.php');
        self::assertIsString($bootstrap);
        self::assertStringContainsString('use Bedriox\\Server\\Bedriox;', $bootstrap);
        self::assertStringContainsString('new Bedriox()', $bootstrap);
        self::assertStringNotContainsString('Application', $bootstrap);
    }
}
