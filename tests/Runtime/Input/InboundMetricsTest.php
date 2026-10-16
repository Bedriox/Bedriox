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
use Bedriox\Server\Runtime\Input\InboundMetrics;
use Bedriox\Server\Runtime\Input\InboundOrigin;
use Bedriox\Server\Runtime\Input\InboundResult;
use PHPUnit\Framework\TestCase;

final class InboundMetricsTest extends TestCase
{
    public function testRecordsDispositionAndOriginTotals(): void
    {
        $metrics = new InboundMetrics();
        $metrics->record(InboundResult::handled(1));
        $metrics->record(new InboundResult(
            InboundDisposition::REJECTED,
            InboundOrigin::ADMISSION,
            'queue_saturated',
            2,
        ));
        $metrics->record(new InboundResult(
            InboundDisposition::SEMANTIC_VIOLATION,
            InboundOrigin::PACKET_HANDLE,
            'actor_identity_mismatch',
            4,
        ));
        $metrics->record(new InboundResult(
            InboundDisposition::PROTOCOL_VIOLATION,
            InboundOrigin::PACKET_DECODE,
            'malformed_packet',
            3,
        ));

        $snapshot = $metrics->snapshot();
        self::assertSame(4, $snapshot->total());
        self::assertSame(1, $snapshot->handled);
        self::assertSame(1, $snapshot->rejected);
        self::assertSame(1, $snapshot->semanticViolations);
        self::assertSame(1, $snapshot->protocolViolations);
        self::assertSame(2, $snapshot->packetHandleOrigins);
        self::assertSame(1, $snapshot->admissionOrigins);
        self::assertSame(1, $snapshot->packetDecodeOrigins);
        self::assertSame(0, $snapshot->sessionFatals);
    }
}
