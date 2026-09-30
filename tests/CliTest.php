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

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CliTest extends TestCase
{
    public function testVersionWritesIdentityToStandardOutput(): void
    {
        $result = $this->runCli('--version');

        self::assertSame(0, $result['exitCode']);
        self::assertStringContainsString('Bedriox 0.3.0-alpha.1', $result['stdout']);
        self::assertSame('', $result['stderr']);
    }

    #[DataProvider('unsupportedArgumentProvider')]
    public function testUnsupportedInvocationFailsClearly(string $argument): void
    {
        $result = $this->runCli($argument);

        self::assertSame(1, $result['exitCode']);
        self::assertStringContainsString('Minecraft: Bedrock Edition Server Software', $result['stdout']);
        self::assertStringContainsString('Unknown invocation', $result['stderr']);
    }

    /** @return iterable<string, array{string}> */
    public static function unsupportedArgumentProvider(): iterable
    {
        yield 'unknown argument' => ['--unknown'];
    }

    /** @return array{exitCode: int, stdout: string, stderr: string} */
    private function runCli(?string $argument): array
    {
        $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(dirname(__DIR__) . '/bin/bedriox');
        if ($argument !== null) {
            $command .= ' ' . escapeshellarg($argument);
        }

        $pipes = [];
        $process = proc_open($command, [
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
        $exitCode = proc_close($process);

        self::assertIsString($stdout);
        self::assertIsString($stderr);

        return ['exitCode' => $exitCode, 'stdout' => $stdout, 'stderr' => $stderr];
    }
}
