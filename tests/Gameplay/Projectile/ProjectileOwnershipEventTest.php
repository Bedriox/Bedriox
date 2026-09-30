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

namespace Bedriox\Server\Tests\Gameplay\Projectile;

use Bedriox\Api\Entity\Vector3;
use Bedriox\Api\Event\Entity\ProjectileImpactedEvent;
use Bedriox\Api\Event\Entity\ProjectileImpactEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchedEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchEvent;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ProjectileLaunchEvent::class)]
#[CoversClass(ProjectileLaunchedEvent::class)]
#[CoversClass(ProjectileImpactEvent::class)]
#[CoversClass(ProjectileImpactedEvent::class)]
final class ProjectileOwnershipEventTest extends TestCase
{
    public function testEntityShooterIsRetainedAcrossProjectileLifecycleEvents(): void
    {
        $shooter = new SkeletonEntity(EntityUuid::random(), 73, 'world', new Position(1.0, 64.0, 1.0));
        $position = new ApiPosition(1.0, 65.4, 1.0);
        $motion = new Vector3(0.0, 0.0, 1.0);

        $launch = new ProjectileLaunchEvent($shooter, 100, 'minecraft:arrow', $position, $motion);
        $launched = new ProjectileLaunchedEvent($shooter, 100, 'minecraft:arrow', $position, $motion);
        $impact = new ProjectileImpactEvent($shooter->getRuntimeId(), $shooter, 'arrow', $position, null, null);
        $impacted = new ProjectileImpactedEvent($shooter->getRuntimeId(), $shooter, 'arrow', $position, null, null);

        self::assertSame($shooter, $launch->shooter);
        self::assertSame($shooter, $launched->shooter);
        self::assertSame($shooter, $impact->shooter);
        self::assertSame($shooter, $impacted->shooter);
    }
}
