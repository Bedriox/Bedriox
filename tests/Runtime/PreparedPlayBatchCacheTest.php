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

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Runtime\PreparedPlayBatchCache;
use Bedriox\Server\Runtime\ReusablePlayPacket;
use PHPUnit\Framework\TestCase;

final class PreparedPlayBatchCacheTest extends TestCase
{
    public function testDisposableBatchesCanBeMeasuredAndReleased(): void
    {
        $cache = new PreparedPlayBatchCache();
        $encoded = $cache->getOrEncode('initialization', static fn(): string => 'prepared-batch');

        self::assertSame('prepared-batch', $encoded);
        self::assertSame(14, $cache->retainedBytes());
        self::assertSame(14, $cache->trim());
        self::assertSame(0, $cache->retainedBytes());
        self::assertSame(0, $cache->trim());
    }

    public function testPreparedPacketUsesTheBootScopedEncodedBatch(): void
    {
        $cache = new PreparedPlayBatchCache();
        $limits = new BatchLimits();
        $first = $cache->preparePacket(
            new ReusablePlayPacket('initialization.time', new SetTimePacket(100)),
            ProtocolVersion::CURRENT,
            $limits,
        );
        $retainedBytes = $cache->retainedBytes();
        $second = $cache->preparePacket(
            new ReusablePlayPacket('initialization.time', new SetTimePacket(200)),
            ProtocolVersion::CURRENT,
            $limits,
        );

        self::assertSame($first, $second);
        self::assertGreaterThan(0, $retainedBytes);
        self::assertSame($retainedBytes, $cache->retainedBytes());
    }
}
