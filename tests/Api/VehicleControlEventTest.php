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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\Vehicle\VehicleControlEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class VehicleControlEventTest extends TestCase
{
    public function testPluginsCanCancelOrRewriteNormalizedBoatControl(): void
    {
        $event = new VehicleControlEvent(
            self::player(),
            new BoatEntity(EntityUuid::random(), 1, 'world', new Position(0.5, 63.0, 0.5)),
            1.0,
            0.0,
            90.0,
            true,
            false,
        );

        $event->setAxes(0.4, -0.2);
        $event->setYaw(180.0);
        $event->setPaddling(false, true);
        $event->cancel();

        self::assertTrue($event->isCancelled());
        self::assertSame(0.4, $event->forward());
        self::assertSame(-0.2, $event->strafe());
        self::assertSame(180.0, $event->yaw());
        self::assertFalse($event->isPaddlingLeft());
        self::assertTrue($event->isPaddlingRight());
    }

    public function testControlAxesMustRemainNormalized(): void
    {
        $event = new VehicleControlEvent(
            self::player(),
            new BoatEntity(EntityUuid::random(), 1, 'world', new Position(0.5, 63.0, 0.5)),
            0.0,
            0.0,
            0.0,
            false,
            false,
        );

        $this->expectException(InvalidArgumentException::class);
        $event->setAxes(1.01, 0.0);
    }

    private static function player(): Player
    {
        return new Player(
            'Driver',
            '00000000-0000-4000-8000-000000000002',
            new ApiPosition(0.5, 64.0, 0.5),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
