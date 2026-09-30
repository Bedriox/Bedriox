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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\SetupWizard;
use PHPUnit\Framework\TestCase;

final class SetupWizardTest extends TestCase
{
    public function testWizardPublishesConfigurationOnlyAfterConfirmation(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-wizard-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $answers = ['yes', 'My Server', 'Welcome', '19140', 'main', 'flat', 'creative', 'easy', '12', '6', 'yes', 'no', 'yes', 'Alice', 'no'];
        $output = '';
        try {
            (new SetupWizard(
                static function (string $message) use (&$output): void {
                    $output .= $message;
                },
                static function () use (&$answers): ?string {
                    return array_shift($answers);
                },
            ))->run($directory . '/server.properties', $directory . '/whitelist.json');
            $properties = file_get_contents($directory . '/server.properties');
            self::assertIsString($properties);
            self::assertStringContainsString('server-name=My Server', $properties);
            self::assertStringContainsString('white-list=true', $properties);
            self::assertStringContainsString('level-type=flat', $properties);
            self::assertStringContainsString('https://bedriox.com', $output);
            self::assertStringContainsString('press Enter to accept the default shown in brackets', $output);
            self::assertStringContainsString('Network port to bind [19132]', $output);
            self::assertStringNotContainsString('Write this configuration?', $output);
            $whitelist = file_get_contents($directory . '/whitelist.json');
            self::assertIsString($whitelist);
            self::assertStringContainsString('Alice', $whitelist);
        } finally {
            @unlink($directory . '/server.properties');
            @unlink($directory . '/whitelist.json');
            @rmdir($directory);
        }
    }

    public function testEndOfInputLeavesNoPartialConfiguration(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-wizard-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        try {
            $wizard = new SetupWizard(static function (string $message): void {}, static fn(): ?string => null);
            try {
                $wizard->run($directory . '/server.properties', $directory . '/whitelist.json');
            } catch (\RuntimeException) {
            }
            self::assertFileDoesNotExist($directory . '/server.properties');
            self::assertFileDoesNotExist($directory . '/whitelist.json');
        } finally {
            @rmdir($directory);
        }
    }

}
