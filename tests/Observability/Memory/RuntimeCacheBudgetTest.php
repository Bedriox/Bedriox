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

namespace Bedriox\Server\Tests\Observability\Memory;

use Bedriox\Server\Observability\Memory\DisposableByteCache;
use Bedriox\Server\Observability\Memory\DisposableCacheAllocation;
use Bedriox\Server\Observability\Memory\RuntimeCacheBudget;
use PHPUnit\Framework\TestCase;

final class RuntimeCacheBudgetTest extends TestCase
{
    public function testLargestCachesReleaseOnlyAggregateExcess(): void
    {
        $large = self::cache(12_000_000);
        $medium = self::cache(8_000_000);
        $small = self::cache(4_000_000);
        $budget = new RuntimeCacheBudget(16_777_216);

        $result = $budget->enforce([$small, $large, $medium]);

        self::assertSame(24_000_000, $result['retained_before']);
        self::assertSame(16_777_216, $result['retained_after']);
        self::assertSame(7_222_784, $result['released']);
        self::assertSame(4_777_216, $large->retainedBytes());
        self::assertSame(8_000_000, $medium->retainedBytes());
        self::assertSame(4_000_000, $small->retainedBytes());
    }

    public function testInactiveCachesAreTrimmedBeforeActiveCaches(): void
    {
        $inactive = self::cache(8_000_000);
        $active = self::cache(8_000_000);
        $budget = new RuntimeCacheBudget(10_000_000);

        $budget->enforce([
            new DisposableCacheAllocation($active, 300),
            new DisposableCacheAllocation($inactive, 0),
        ]);

        self::assertSame(2_000_000, $inactive->retainedBytes());
        self::assertSame(8_000_000, $active->retainedBytes());
    }

    private static function cache(int $bytes): DisposableByteCache
    {
        return new class ($bytes) implements DisposableByteCache {
            public function __construct(private int $bytes) {}

            public function retainedBytes(): int
            {
                return $this->bytes;
            }

            public function trimToBytes(int $targetBytes): int
            {
                $before = $this->bytes;
                $this->bytes = min($this->bytes, max(0, $targetBytes));

                return $before - $this->bytes;
            }
        };
    }
}
