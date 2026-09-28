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

use Bedriox\Server\Entity\Navigation\NavigationPoint;
use Bedriox\Server\Entity\Navigation\NavigationSnapshot;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class NavigationSnapshotTest extends TestCase
{
    public function testWalkabilityIsStoredInTheBoundedBitset(): void
    {
        $revision = hash('sha256', 'walkability');
        $snapshot = NavigationSnapshot::fromWalkablePoints(
            -2,
            -1,
            4,
            4,
            2,
            3,
            [new NavigationPoint(-2, -1, 4), new NavigationPoint(1, 0, 6)],
            $revision,
        );

        self::assertTrue($snapshot->isWalkable(new NavigationPoint(-2, -1, 4)));
        self::assertTrue($snapshot->isWalkable(new NavigationPoint(1, 0, 6)));
        self::assertFalse($snapshot->isWalkable(new NavigationPoint(-1, -1, 4)));
        self::assertFalse($snapshot->contains(new NavigationPoint(2, 0, 6)));
        self::assertSame($revision, $snapshot->revision);
        self::assertSame(3, strlen($snapshot->bits()));
    }

    public function testDuplicateWalkableCellIsRejected(): void
    {
        $point = new NavigationPoint(0, 0, 0);

        $this->expectException(InvalidArgumentException::class);
        NavigationSnapshot::fromWalkablePoints(0, 0, 0, 1, 1, 1, [$point, $point], hash('sha256', 'duplicate'));
    }

    public function testSnapshotRejectsMoreThanItsMaximumCellCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new NavigationSnapshot(0, 0, 0, 128, 17, 128, '', hash('sha256', 'oversized'));
    }
}
