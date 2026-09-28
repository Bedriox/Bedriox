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

use Bedriox\Server\Runtime\RuntimeDiagnostics;
use PHPUnit\Framework\TestCase;

final class RuntimeDiagnosticsTest extends TestCase
{
    public function testRecordWritesBoundedStructuredFields(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $diagnostics->record('play.session_closed', [
            'reason' => str_repeat('x', 200),
            'packet_id' => 33,
        ]);

        self::assertCount(1, $lines);
        self::assertStringStartsWith('[bedriox] {', $lines[0]);
        $decoded = json_decode(substr($lines[0], strlen('[bedriox] ')), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($decoded);
        self::assertSame('play.session_closed', $decoded['event']);
        self::assertIsString($decoded['reason']);
        self::assertSame(128, strlen($decoded['reason']));
        self::assertSame(33, $decoded['packet_id']);
    }

    public function testInvalidShapeAndFailingWriterCannotAffectRuntime(): void
    {
        $calls = 0;
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$calls): void {
            ++$calls;
            throw new \RuntimeException('writer failure containing a secret');
        });

        $diagnostics->record("invalid\nevent", ['secret' => 'must-not-be-written']);
        $diagnostics->record('runtime.failure', ['exception' => \RuntimeException::class]);

        self::assertSame(1, $calls);
    }

    public function testProtocolTraceCanBeDisabledWithoutSuppressingOperationalEvents(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        }, false);

        $diagnostics->record('play.protocol_trace', ['detail' => 'packet metadata']);
        $diagnostics->record('transport.protocol_trace', ['kind' => 'handshake_rejected']);
        $diagnostics->record('world.protocol_trace', ['kind' => 'block_placement_corrected']);
        $diagnostics->record('play.session_closed', ['reason' => 'server_close']);

        self::assertCount(1, $lines);
        self::assertStringContainsString('play.session_closed', $lines[0]);
    }

    public function testPerformanceSnapshotsFitWithinTheBoundedFieldBudget(): void
    {
        $lines = [];
        $diagnostics = new RuntimeDiagnostics(static function (string $line) use (&$lines): void {
            $lines[] = $line;
        });

        $diagnostics->record('runtime.performance_summary.protocol_trace', array_fill_keys(
            array_map(static fn(int $index): string => 'metric_' . $index, range(1, 17)),
            1,
        ));
        $diagnostics->record('runtime.too_many_fields', array_fill_keys(
            array_map(static fn(int $index): string => 'metric_' . $index, range(1, 25)),
            1,
        ));

        self::assertCount(1, $lines);
        self::assertStringContainsString('runtime.performance_summary.protocol_trace', $lines[0]);
    }
}
