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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\ServerLogger;
use Bedriox\Server\Plugin\ServerPluginLogger;
use PHPUnit\Framework\TestCase;

final class ServerPluginLoggerTest extends TestCase
{
    public function testExposesEveryServerLevelWithManifestOwnedAttribution(): void
    {
        $server = new ServerLogger(static function (string $line): void {}, LogLevel::DEBUG, false, false, null);
        $logger = new ServerPluginLogger('ExamplePlugin', $server);

        $logger->debug('debug');
        $logger->info('info');
        $logger->notice('notice');
        $logger->warning('warning');
        $logger->error('error');
        $logger->critical('critical');

        self::assertSame(
            ['DEBUG', 'INFO', 'NOTICE', 'WARNING', 'ERROR', 'CRITICAL'],
            array_map(static function (string $line): string {
                if (preg_match('/ Bedriox ([A-Z]+) >/', $line, $matches) !== 1) {
                    self::fail('The server log line did not contain a level.');
                }
                return $matches[1];
            }, $server->recentLines()),
        );
        foreach ($server->recentLines() as $line) {
            self::assertStringContainsString('[ExamplePlugin]', $line);
            self::assertStringNotContainsString('[Plugin/ExamplePlugin]', $line);
        }
    }
}
