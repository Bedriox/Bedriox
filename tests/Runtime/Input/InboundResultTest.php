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

namespace Bedriox\Server\Tests\Runtime\Input;

use Bedriox\Server\Runtime\Input\InboundDisposition;
use Bedriox\Server\Runtime\Input\InboundOrigin;
use Bedriox\Server\Runtime\Input\InboundResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class InboundResultTest extends TestCase
{
    #[DataProvider('sessionPolicyProvider')]
    public function testOnlyFatalDispositionsRequireDisconnect(
        InboundDisposition $disposition,
        bool $requiresDisconnect,
    ): void {
        $result = new InboundResult($disposition, InboundOrigin::PACKET_HANDLE, 'test');

        self::assertSame($requiresDisconnect, $result->requiresDisconnect());
        self::assertSame(!$requiresDisconnect, $result->keepsSessionOpen());
    }

    /** @return iterable<string, array{InboundDisposition, bool}> */
    public static function sessionPolicyProvider(): iterable
    {
        yield 'handled' => [InboundDisposition::HANDLED, false];
        yield 'ignored' => [InboundDisposition::IGNORED, false];
        yield 'corrected' => [InboundDisposition::CORRECTED, false];
        yield 'rejected' => [InboundDisposition::REJECTED, false];
        yield 'semantic violation' => [InboundDisposition::SEMANTIC_VIOLATION, false];
        yield 'protocol violation' => [InboundDisposition::PROTOCOL_VIOLATION, true];
        yield 'session fatal' => [InboundDisposition::SESSION_FATAL, true];
    }

    public function testBatchMergePreservesTheStrongestDisposition(): void
    {
        $result = InboundResult::handled()
            ->merge(new InboundResult(InboundDisposition::IGNORED, InboundOrigin::PACKET_DECODE, 'unsupported', 500))
            ->merge(new InboundResult(InboundDisposition::REJECTED, InboundOrigin::PACKET_HANDLE, 'invalid', 30))
            ->merge(new InboundResult(InboundDisposition::CORRECTED, InboundOrigin::PACKET_HANDLE, 'corrected', 144));

        self::assertSame(InboundDisposition::REJECTED, $result->disposition);
        self::assertSame(30, $result->packetId);
    }
}
