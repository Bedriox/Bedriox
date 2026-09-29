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

namespace Bedriox\Server\Tests\Gameplay\Potion;

use Bedriox\Api\Potion\PotionType;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloud;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloudRegistry;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AreaEffectCloud::class)]
#[CoversClass(AreaEffectCloudRegistry::class)]
final class AreaEffectCloudRegistryTest extends TestCase
{
    public function testCloudAppliesOnIntervalAndEnforcesVictimCooldown(): void
    {
        $registry = new AreaEffectCloudRegistry(firstEntityId: 1_200);
        $cloud = $registry->spawn('owner', PotionType::POISON, new Position(1.0, 64.0, 1.0));
        self::assertSame(1_200, $cloud->runtimeEntityId);

        $applications = [];
        for ($tick = 0; $tick < AreaEffectCloud::APPLICATION_INTERVAL_TICKS; ++$tick) {
            $applications = $registry->tick();
        }
        self::assertCount(1, $applications);
        self::assertTrue($applications[0]->canAffect('victim'));

        $updated = $registry->affected($cloud->runtimeEntityId, 'victim');
        self::assertNotNull($updated);
        self::assertFalse($updated->canAffect('victim'));
        self::assertLessThan($applications[0]->radius, $updated->radius);
    }

    public function testCloudExpiresByDuration(): void
    {
        $registry = new AreaEffectCloudRegistry();
        $cloud = $registry->spawn('owner', PotionType::WATER, new Position(0.0, 64.0, 0.0));
        for ($tick = 0; $tick <= AreaEffectCloud::DEFAULT_DURATION_TICKS; ++$tick) {
            $registry->tick();
        }

        self::assertNull($registry->get($cloud->runtimeEntityId));
    }
}
