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

use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Event\Entity\EntityDismountEvent;
use Bedriox\Api\Event\Entity\EntityMountEvent;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Simulation\Position;
use LogicException;
use PHPUnit\Framework\TestCase;

final class MountEventTest extends TestCase
{
    public function testPlayerRequestedTransitionsAreCancellable(): void
    {
        [$passenger, $vehicle] = self::entities();
        $mount = new EntityMountEvent($passenger, $vehicle, MountSeat::DRIVER, MountReason::INTERACTION);
        $mount->cancel();

        self::assertTrue($mount->isCancelled());
    }

    public function testForcedLifecycleDismountCannotBeCancelled(): void
    {
        [$passenger, $vehicle] = self::entities();
        $event = new EntityDismountEvent($passenger, $vehicle, MountSeat::DRIVER, MountReason::DEATH);

        $this->expectException(LogicException::class);
        $event->cancel();
    }

    /** @return array{CowEntity, PigEntity} */
    private static function entities(): array
    {
        return [
            new CowEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0)),
            new PigEntity(EntityUuid::random(), 2, 'world', new Position(1.0, 64.0, 0.0), saddled: true),
        ];
    }
}
