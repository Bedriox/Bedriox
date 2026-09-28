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

namespace Bedriox\Server\Tests\Observability;

use Bedriox\Server\Observability\BackgroundLogWriter;
use Bedriox\Server\Observability\LogLevel;
use Bedriox\Server\Observability\RotatingFileLog;
use Bedriox\Server\Observability\ServerLogger;
use PHPUnit\Framework\TestCase;

final class ServerLoggerTest extends TestCase
{
    public function testWritesPlainFileOutputAndRedactsSecrets(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-log-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        $console = '';
        try {
            $logger = new ServerLogger(
                static function (string $line) use (&$console): void {
                    $console .= $line;
                },
                LogLevel::INFO,
                true,
                true,
                new RotatingFileLog($path, 65_536, 2),
            );
            $logger->debug('hidden');
            $logger->info("ready\npassword=private-value");

            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('Bedriox INFO > ready\\n [REDACTED]', $contents);
            self::assertStringNotContainsString("\033", $contents);
            self::assertStringNotContainsString('private-value', $contents);
            self::assertStringContainsString("\033", $console);
            self::assertCount(1, $logger->recentLines());
        } finally {
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testRotatesAndRetainsOnlyTheConfiguredArchiveCount(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-rotate-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        try {
            $file = new RotatingFileLog($path, 40, 1);
            $file->write(str_repeat('a', 30));
            $file->write(str_repeat('b', 30));
            usleep(2_000);
            $file->write(str_repeat('c', 30));

            self::assertCount(1, glob($directory . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . '*.log') ?: []);
            self::assertSame(str_repeat('c', 30) . PHP_EOL, file_get_contents($path));
        } finally {
            foreach (glob($directory . DIRECTORY_SEPARATOR . 'archive' . DIRECTORY_SEPARATOR . '*.log') ?: [] as $archive) {
                @unlink($archive);
            }
            @rmdir($directory . DIRECTORY_SEPARATOR . 'archive');
            @unlink($path);
            @rmdir($directory);
        }
    }

    public function testCanSendAlreadyRedactedPlainLinesToBackgroundWriter(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-background-log-' . bin2hex(random_bytes(6));
        $path = $directory . DIRECTORY_SEPARATOR . 'server.log';
        $writer = null;
        try {
            $writer = BackgroundLogWriter::start('logger-test', $path, 65_536, 1);
            $logger = new ServerLogger(
                static function (string $_line): void {},
                LogLevel::INFO,
                false,
                false,
                null,
                backgroundFile: $writer,
            );
            $logger->info('ready password=private-value');
            self::assertTrue($writer->flush(5_000));

            $contents = file_get_contents($path);
            self::assertIsString($contents);
            self::assertStringContainsString('ready [REDACTED]', $contents);
            self::assertStringNotContainsString('private-value', $contents);
        } finally {
            $writer?->shutdown(1_000);
            @unlink($path);
            @rmdir($directory);
        }
    }
}
