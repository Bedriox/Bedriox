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

use Bedriox\Server\Runtime\ChunkViewManager;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ChunkViewManagerTest extends TestCase
{
    public function testInitialViewIsCompleteAndNearestFirst(): void
    {
        $view = new ChunkViewManager(4);

        self::assertSame([], $view->centerOnBlock(0.0, 0.0));
        self::assertSame(81, $view->pendingCount());
        self::assertSame([['x' => 0, 'z' => 0]], $view->pending(1));

        foreach ($view->pending(81) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
            $view->markSent($chunk['x'], $chunk['z']);
        }
        self::assertSame(81, $view->sentCount());
        self::assertSame(0, $view->pendingCount());
    }

    public function testCompleteSentRadiusAdvancesOnlyAfterAWholeRingIsDelivered(): void
    {
        $view = new ChunkViewManager(2);
        $view->centerOnChunk(0, 0);
        self::assertSame(-1, $view->completeSentRadius());

        $view->markPrepared(0, 0);
        $view->markSent(0, 0);
        self::assertSame(0, $view->completeSentRadius());

        foreach ($view->pending(8) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
            $view->markSent($chunk['x'], $chunk['z']);
        }
        self::assertSame(1, $view->completeSentRadius());

        foreach ($view->pending(16) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
            $view->markSent($chunk['x'], $chunk['z']);
        }
        self::assertSame(2, $view->completeSentRadius());
    }

    public function testCrossingOneChunkLoadsAndReleasesOneEdge(): void
    {
        $view = new ChunkViewManager(4);
        $view->centerOnChunk(0, 0);
        foreach ($view->pending(81) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
            $view->markSent($chunk['x'], $chunk['z']);
        }

        $released = $view->centerOnBlock(16.0, 0.0);

        self::assertCount(9, $released);
        self::assertCount(9, $view->pending(9));
        self::assertSame(72, $view->sentCount());
        foreach ($released as $chunk) {
            self::assertSame(-4, $chunk['x']);
        }
        foreach ($view->pending(9) as $chunk) {
            self::assertSame(5, $chunk['x']);
        }
    }

    #[DataProvider('blockToChunkProvider')]
    public function testBlockCoordinatesUseFloorDivision(float $block, int $chunk): void
    {
        $view = new ChunkViewManager(1);
        $view->centerOnBlock($block, $block);

        self::assertSame(['x' => $chunk, 'z' => $chunk], $view->center());
    }

    /** @return iterable<string, array{float, int}> */
    public static function blockToChunkProvider(): iterable
    {
        yield 'origin' => [0.0, 0];
        yield 'positive edge' => [16.0, 1];
        yield 'negative fraction' => [-0.01, -1];
        yield 'negative edge' => [-16.0, -1];
        yield 'below negative edge' => [-16.01, -2];
    }

    public function testRecenteringBeforeDeliveryDropsStalePendingChunks(): void
    {
        $view = new ChunkViewManager(1);
        $view->centerOnChunk(0, 0);
        $view->markPrepared(0, 0);
        $view->markSent(0, 0);
        self::assertTrue($view->hasSent(0, 0));
        self::assertFalse($view->hasSent(1, 0));

        self::assertSame([['x' => 0, 'z' => 0]], $view->centerOnChunk(3, 0));
        self::assertFalse($view->hasSent(0, 0));
        self::assertSame(9, $view->pendingCount());
        foreach ($view->pending(9) as $chunk) {
            self::assertGreaterThanOrEqual(2, $chunk['x']);
            self::assertLessThanOrEqual(4, $chunk['x']);
        }
    }

    public function testContainmentTracksCurrentViewAfterRecentering(): void
    {
        $view = new ChunkViewManager(1);
        self::assertFalse($view->contains(0, 0));
        $view->centerOnChunk(0, 0);
        self::assertTrue($view->contains(-1, 1));
        self::assertFalse($view->contains(2, 0));

        $view->centerOnChunk(3, -2);
        self::assertFalse($view->contains(-1, 1));
        self::assertTrue($view->contains(4, -3));
    }

    public function testPrefetchRingIsPreparedWithoutBecomingVisibleAndPromotesOnMovement(): void
    {
        $view = new ChunkViewManager(1, 1);
        $view->centerOnChunk(0, 0);

        self::assertSame(9, $view->pendingCount());
        self::assertSame(16, $view->prefetchPendingCount());
        self::assertTrue($view->containsRetained(2, 0));
        self::assertFalse($view->contains(2, 0));

        foreach ($view->pending(9) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
            $view->markSent($chunk['x'], $chunk['z']);
        }
        foreach ($view->pendingPrefetch(16) as $chunk) {
            $view->markPrepared($chunk['x'], $chunk['z']);
        }

        $released = $view->centerOnChunk(1, 0);

        self::assertCount(5, $released);
        self::assertSame(3, $view->pendingCount());
        self::assertSame(0, $view->unpreparedVisibleCount());
        self::assertTrue($view->isPrepared(2, 0));
        self::assertSame(5, $view->prefetchPendingCount());
    }

    public function testInvalidLimitsAndStateFailClosed(): void
    {
        foreach ([0, 33] as $radius) {
            try {
                new ChunkViewManager($radius);
                self::fail('Invalid radius was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $view = new ChunkViewManager(1);
        $view->centerOnChunk(0, 0);
        $this->expectException(InvalidArgumentException::class);
        $view->markSent(10, 10);
    }
}
