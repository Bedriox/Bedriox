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

namespace Bedriox\Server\Tests\Gameplay\Portal;

use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Gameplay\Portal\PortalAxis;
use Bedriox\Server\Gameplay\Portal\PortalContactTracker;
use Bedriox\Server\Gameplay\Portal\PortalDestinationPlanner;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PortalDestinationPlannerTest extends TestCase
{
    public function testCoordinatesScaleEightToOneAndBackWithVanillaSearchBounds(): void
    {
        $planner = new PortalDestinationPlanner();
        $nether = $planner->plan(WorldDimension::OVERWORLD, new Position(80.0, 70.0, -40.0), PortalAxis::X);
        self::assertSame(WorldDimension::NETHER, $nether->targetDimension);
        self::assertSame([10.0, 70.0, -5.0], [
            $nether->projectedPosition->x,
            $nether->projectedPosition->y,
            $nether->projectedPosition->z,
        ]);
        self::assertSame(16, $nether->searchRadius);

        $overworld = $planner->plan(WorldDimension::NETHER, new Position(10.0, 70.0, -5.0), PortalAxis::Z);
        self::assertSame(WorldDimension::OVERWORLD, $overworld->targetDimension);
        self::assertSame([80.0, 70.0, -40.0], [
            $overworld->projectedPosition->x,
            $overworld->projectedPosition->y,
            $overworld->projectedPosition->z,
        ]);
        self::assertSame(128, $overworld->searchRadius);
    }

    public function testNearestPortalAndBuildOriginAreDeterministic(): void
    {
        $planner = new PortalDestinationPlanner();
        $plan = $planner->plan(WorldDimension::OVERWORLD, new Position(80.0, 70.0, -40.0), PortalAxis::X);

        self::assertEquals(new BlockPosition(9, 60, -5), $planner->nearest($plan, [
            new BlockPosition(11, 80, -5),
            new BlockPosition(9, 60, -5),
            new BlockPosition(25, 20, -5),
        ]));

        $visited = [];
        $origin = $planner->buildOrigin($plan, static function (BlockPosition $position) use (&$visited): bool {
            $visited[] = [$position->x, $position->y, $position->z];

            return count($visited) === 4;
        });
        self::assertSame([
            [10, 70, -5],
            [10, 71, -5],
            [10, 69, -5],
            [10, 72, -5],
        ], $visited);
        self::assertEquals(new BlockPosition(10, 72, -5), $origin);
    }

    public function testEndCannotUseNetherPortalPlanner(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new PortalDestinationPlanner())->plan(
            WorldDimension::END,
            new Position(0.0, 64.0, 0.0),
            PortalAxis::X,
        );
    }

    public function testContactTrackerRequiresSustainedContactAndAppliesCooldown(): void
    {
        $tracker = new PortalContactTracker();
        $entry = new BlockPosition(0, 64, 0);
        for ($tick = 1; $tick < PortalContactTracker::REQUIRED_CONTACT_TICKS; ++$tick) {
            self::assertFalse($tracker->contact('session', $entry, $tick));
        }
        self::assertTrue($tracker->contact('session', $entry, PortalContactTracker::REQUIRED_CONTACT_TICKS));
        self::assertFalse($tracker->contact('session', $entry, PortalContactTracker::REQUIRED_CONTACT_TICKS + 1));
        self::assertTrue($tracker->contact('creative', $entry, 1, true));
    }

    public function testArrivalCooldownPreventsImmediateReverseTransfer(): void
    {
        $tracker = new PortalContactTracker();
        $entry = new BlockPosition(0, 64, 0);
        $arrivalTick = 200;

        $tracker->beginArrivalCooldown('session', $arrivalTick);

        self::assertFalse($tracker->contact('session', $entry, $arrivalTick, true));
        self::assertFalse($tracker->contact(
            'session',
            $entry,
            $arrivalTick + PortalContactTracker::TRANSFER_COOLDOWN_TICKS - 1,
            true,
        ));
        self::assertTrue($tracker->contact(
            'session',
            $entry,
            $arrivalTick + PortalContactTracker::TRANSFER_COOLDOWN_TICKS,
            true,
        ));
    }
}
