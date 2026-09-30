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

namespace Bedriox\Server\Tests;

use PHPUnit\Framework\TestCase;

final class InstallerScriptTest extends TestCase
{
    public function testUnixInstallerUsesPharRuntimeChecksumsAndSafeStaging(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/install/install.sh');

        self::assertStringContainsString('Bedriox.phar', $script);
        self::assertStringContainsString('phar_sha256', $script);
        self::assertStringContainsString('runtime_sha256', $script);
        self::assertStringContainsString('mktemp -d', $script);
        self::assertStringContainsString('already exists', $script);
        self::assertStringContainsString('--no-start', $script);
        self::assertStringNotContainsString('composer', strtolower($script));
    }

    public function testWindowsInstallerUsesPharRuntimeChecksumsAndSafeStaging(): void
    {
        $script = (string) file_get_contents(dirname(__DIR__) . '/install/install.ps1');

        self::assertStringContainsString('Bedriox.phar', $script);
        self::assertStringContainsString('Get-FileHash', $script);
        self::assertStringContainsString('runtime_sha256', $script);
        self::assertStringContainsString('NewGuid', $script);
        self::assertStringContainsString('already exists', $script);
        self::assertStringContainsString('NoStart', $script);
        self::assertStringNotContainsString('composer', strtolower($script));
    }
}
