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

namespace Bedriox\Server\Tests\Entity\Navigation;

use Bedriox\Server\Entity\Navigation\GroundPathfinder;
use Bedriox\Server\Entity\Navigation\NavigationPathStatus;
use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Bedriox\Server\Entity\Navigation\NavigationSnapshot;
use PHPUnit\Framework\TestCase;

final class GroundPathfinderTest extends TestCase
{
    public function testDirectGroundPathReachesItsTarget(): void
    {
        $snapshot = self::snapshot(5, 1, 1, [
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(1, 0, 0),
            new NavigationPoint(2, 0, 0),
            new NavigationPoint(3, 0, 0),
            new NavigationPoint(4, 0, 0),
        ]);

        $path = (new GroundPathfinder())->find(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(4, 0, 0),
        );

        self::assertSame(NavigationPathStatus::REACHED_TARGET, $path->status);
        self::assertTrue($path->reachedTarget());
        self::assertSame(
            ['0:0:0', '1:0:0', '2:0:0', '3:0:0', '4:0:0'],
            array_map(static fn(NavigationPoint $point): string => $point->key(), $path->points),
        );
        self::assertSame($snapshot->revision, $path->snapshotRevision);
    }

    public function testOneBlockStepsAreNavigable(): void
    {
        $snapshot = self::snapshot(4, 2, 1, [
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(1, 1, 0),
            new NavigationPoint(2, 1, 0),
            new NavigationPoint(3, 0, 0),
        ]);

        $path = (new GroundPathfinder())->find(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(3, 0, 0),
        );

        self::assertSame(NavigationPathStatus::REACHED_TARGET, $path->status);
        self::assertSame(
            ['0:0:0', '1:1:0', '2:1:0', '3:0:0'],
            array_map(static fn(NavigationPoint $point): string => $point->key(), $path->points),
        );
    }

    public function testNodeBudgetReturnsABoundedPartialPath(): void
    {
        $points = [];
        for ($z = 0; $z < 5; ++$z) {
            for ($x = 0; $x < 5; ++$x) {
                $points[] = new NavigationPoint($x, 0, $z);
            }
        }
        $snapshot = self::snapshot(5, 1, 5, $points);

        $path = (new GroundPathfinder())->find(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(4, 0, 4),
            1,
        );

        self::assertSame(NavigationPathStatus::NODE_BUDGET_EXHAUSTED, $path->status);
        self::assertSame(1, $path->visitedNodes);
        self::assertSame(['0:0:0'], array_map(static fn(NavigationPoint $point): string => $point->key(), $path->points));
    }

    public function testDisconnectedTargetIsReportedAsUnreachable(): void
    {
        $snapshot = self::snapshot(3, 1, 1, [
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(2, 0, 0),
        ]);

        $path = (new GroundPathfinder())->find(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(2, 0, 0),
        );

        self::assertSame(NavigationPathStatus::UNREACHABLE, $path->status);
        self::assertSame(1, $path->visitedNodes);
    }

    public function testExpiredDeadlineDoesNotBeginSearching(): void
    {
        $snapshot = self::snapshot(2, 1, 1, [
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(1, 0, 0),
        ]);

        $path = (new GroundPathfinder())->find(
            $snapshot,
            new NavigationPoint(0, 0, 0),
            new NavigationPoint(1, 0, 0),
            deadlineNanoseconds: hrtime(true) - 1,
        );

        self::assertSame(NavigationPathStatus::DEADLINE_EXCEEDED, $path->status);
        self::assertSame(0, $path->visitedNodes);
        self::assertSame('0:0:0', $path->points[0]->key());
    }

    /** @param list<NavigationPoint> $points */
    private static function snapshot(int $sizeX, int $sizeY, int $sizeZ, array $points): NavigationSnapshot
    {
        return NavigationSnapshot::fromWalkablePoints(
            0,
            0,
            0,
            $sizeX,
            $sizeY,
            $sizeZ,
            $points,
            hash('sha256', serialize([$sizeX, $sizeY, $sizeZ, count($points)])),
        );
    }
}
