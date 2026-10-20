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

namespace Bedriox\Server\Tests\Transport;

use Bedriox\RakNet\EndpointAdmissionRejectedEvent;
use Bedriox\RakNet\EndpointAdmissionRejectionBatch;
use Bedriox\Server\Runtime\RuntimeDiagnostics;
use Bedriox\Server\Transport\EndpointAdmissionRejectionReporter;
use PHPUnit\Framework\TestCase;

final class EndpointAdmissionRejectionReporterTest extends TestCase
{
    public function testSamplesAndSummarizesWithoutLoggingPacketsOrCredentials(): void
    {
        $structured = [];
        $console = [];
        $now = 1;
        $diagnostics = new RuntimeDiagnostics(
            static function (string $line) use (&$structured): void {
                $structured[] = $line;
            },
            securityWriter: static function (string $line) use (&$console): void {
                $console[] = $line;
            },
        );
        $reporter = new EndpointAdmissionRejectionReporter($diagnostics, static function () use (&$now): int {
            return $now;
        });
        $events = [];
        for ($port = 50_000; $port < 50_006; ++$port) {
            $events[] = new EndpointAdmissionRejectedEvent('192.0.2.8', $port);
        }

        $reporter->report(new EndpointAdmissionRejectionBatch($events, 2));
        self::assertCount(5, $console);
        self::assertCount(5, $structured);
        self::assertStringNotContainsString('token', implode('', $console));

        $now += 10_000_000_000;
        $reporter->report(new EndpointAdmissionRejectionBatch([], 0));
        self::assertCount(6, $console);
        self::assertSame('Suppressed 3 additional rejected proxy endpoint connections.', $console[5]);
    }
}
