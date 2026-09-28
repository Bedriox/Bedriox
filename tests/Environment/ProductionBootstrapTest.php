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
