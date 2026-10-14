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

namespace Bedriox\Server\Tests\Tools;

use PHPUnit\Framework\TestCase;

final class GeneratedVegetationReportTest extends TestCase
{
    public function testReportFindsRenderedRootsWithoutConsultingLegacySurfaceHeight(): void
    {
        $process = proc_open([
            PHP_BINARY,
            dirname(__DIR__, 2) . '/tools/generated-vegetation-report.php',
            '9123',
            '0',
            '0',
            '32',
        ], [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes);
        self::assertIsResource($process);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        self::assertSame(0, proc_close($process), is_string($stderr) ? $stderr : '');
        self::assertIsString($stdout);
        /** @var array<string, mixed> $report */
        $report = json_decode($stdout, true, flags: JSON_THROW_ON_ERROR);
        self::assertGreaterThan(0, $report['trees'] ?? 0);
        self::assertGreaterThan(0, $report['unique_rendered_signatures'] ?? 0);
        self::assertGreaterThan(0.0, $report['trees_per_chunk_area'] ?? 0.0);
    }
}
