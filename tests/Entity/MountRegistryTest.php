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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Mount\MountRegistry;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class MountRegistryTest extends TestCase
{
    public function testSeatsAreUniqueAndCyclesAreRejected(): void
    {
        $registry = new MountRegistry();
        $first = new CowEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $second = new CowEntity(EntityUuid::random(), 2, 'world', new Position(1.0, 64.0, 0.0));
        $third = new CowEntity(EntityUuid::random(), 3, 'world', new Position(2.0, 64.0, 0.0));
        $first->bindMountView(
            fn() => $registry->entityLink($first->getRuntimeId())?->vehicle,
            fn(): array => $registry->passengerViews($first->getRuntimeId()),
        );

        self::assertNotNull($registry->mountEntity($first, $second, MountSeat::DRIVER));
        self::assertNull($registry->mountEntity($third, $second, MountSeat::DRIVER));
        self::assertNull($registry->mountEntity($second, $first, MountSeat::PASSENGER_1));
        self::assertSame($second, $first->getVehicle());
        self::assertSame(1, $registry->count());

        self::assertNotNull($registry->dismountEntity($first->getRuntimeId()));
        self::assertSame(0, $registry->count());
    }
}
