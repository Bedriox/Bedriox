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

namespace Bedriox\Server\Tests\Entity\Experience;

use Bedriox\Server\Entity\Experience\ExperienceOrbCollisionResolver;
use Bedriox\Server\Entity\Experience\ExperienceOrbMotion;
use Bedriox\Server\Entity\Experience\ExperienceOrbRegistry;
use Bedriox\Server\Entity\Experience\ExperienceOrbTarget;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\CollisionBoxQuery;
use InvalidArgumentException;
use OverflowException;
use PHPUnit\Framework\TestCase;

final class ExperienceOrbRegistryTest extends TestCase
{
    public function testSplitsExperienceIntoNormalOrbValuesAndAdmitsBatchAtomically(): void
    {
        self::assertSame([2477, 17, 7, 3, 1], ExperienceOrbRegistry::splitValues(2505));

        $registry = new ExperienceOrbRegistry(5, 100);
        $spawned = $registry->spawnSplit(2505, new Position(1.0, 70.0, 2.0));
        self::assertSame([2477, 17, 7, 3, 1], array_column($spawned, 'value'));
        self::assertSame([100, 101, 102, 103, 104], array_column($spawned, 'runtimeEntityId'));

        $limited = new ExperienceOrbRegistry(1);
        try {
            $limited->spawnSplit(4, new Position(0.0, 64.0, 0.0));
            self::fail('Oversized batch was accepted.');
        } catch (OverflowException) {
            self::assertSame(0, $limited->count());
        }
    }

    public function testOrbFallsAttractsAndAwardsOneAggregateMemberAtATime(): void
    {
        $registry = new ExperienceOrbRegistry();
        $orb = $registry->spawn(7, new Position(0.0, 64.0, 0.0), new ExperienceOrbMotion(), 1);
        $target = new ExperienceOrbTarget('player', 22, new Position(2.0, 64.0, 0.0));

        $first = $registry->tick([$target]);
        $moved = $registry->get($orb->runtimeEntityId);
        self::assertNotNull($moved);
        self::assertGreaterThan(0.0, $moved->motion->x);
        self::assertLessThan(0.0, $moved->motion->y);
        self::assertSame([], $first->pickups);

        $nearTarget = new ExperienceOrbTarget('player', 22, $moved->position);
        $pickup = $registry->tick([$nearTarget])->pickups;
        self::assertCount(1, $pickup);
        self::assertSame(7, $pickup[0]->awardedExperience);
        self::assertSame('player', $pickup[0]->collectorSessionId);
        self::assertTrue($pickup[0]->removed);
        self::assertNull($registry->get($orb->runtimeEntityId));
    }

    public function testNearbyEqualValueOrbsMergeOnBoundedInterval(): void
    {
        $registry = new ExperienceOrbRegistry();
        $first = $registry->spawn(3, new Position(0.0, 70.0, 0.0), despawnAfterTicks: null);
        $second = $registry->spawn(3, new Position(0.2, 70.0, 0.0), despawnAfterTicks: null);
        $different = $registry->spawn(7, new Position(0.1, 70.0, 0.0), despawnAfterTicks: null);

        $result = $registry->tick(ticks: ExperienceOrbRegistry::MERGE_INTERVAL_TICKS);
        self::assertSame(2, $registry->count());
        self::assertSame(2, $registry->get($first->runtimeEntityId)?->orbCount);
        self::assertNull($registry->get($second->runtimeEntityId));
        self::assertNotNull($registry->get($different->runtimeEntityId));
        self::assertContains($second->runtimeEntityId, array_column($result->removed, 'runtimeEntityId'));
    }

    public function testAggregatePickupLeavesActorUntilItsCountIsConsumed(): void
    {
        $registry = new ExperienceOrbRegistry();
        $first = $registry->spawn(1, new Position(0.0, 64.0, 0.0), despawnAfterTicks: null);
        $registry->spawn(1, new Position(0.0, 64.0, 0.0), despawnAfterTicks: null);
        $registry->tick(ticks: ExperienceOrbRegistry::MERGE_INTERVAL_TICKS);
        $merged = $registry->get($first->runtimeEntityId);
        self::assertNotNull($merged);
        self::assertSame(2, $merged->orbCount);

        $target = new ExperienceOrbTarget('player', 5, $merged->position);
        $pickup = $registry->tick([$target])->pickups[0];
        self::assertFalse($pickup->removed);
        self::assertSame(1, $pickup->remaining?->orbCount);
        self::assertNotNull($registry->get($first->runtimeEntityId));
    }

    public function testCollisionResolverSettlesOrbOnGround(): void
    {
        $registry = new ExperienceOrbRegistry();
        $orb = $registry->spawn(1, new Position(0.5, 64.2, 0.5), despawnAfterTicks: null);
        $resolver = new ExperienceOrbCollisionResolver(self::ground());

        for ($tick = 0; $tick < 40; ++$tick) {
            $registry->tick(collisions: $resolver);
            $orb = $registry->get($orb->runtimeEntityId) ?? throw new \RuntimeException('Orb disappeared.');
            self::assertGreaterThanOrEqual(64.0, $orb->position->y);
        }
        self::assertSame(64.0, $orb->position->y);
        self::assertSame(0.0, $orb->motion->y);
    }

    public function testDespawnAndInputBoundsAreDeterministic(): void
    {
        $registry = new ExperienceOrbRegistry();
        $orb = $registry->spawn(1, new Position(0.0, 0.0, 0.0), despawnAfterTicks: 2);
        self::assertSame([], $registry->tick()->removed);
        self::assertSame([$orb->runtimeEntityId], array_column($registry->tick()->removed, 'runtimeEntityId'));

        $rejections = 0;
        foreach ([0, ExperienceOrbRegistry::MAX_TICK_ADVANCE + 1] as $ticks) {
            try {
                $registry->tick(ticks: $ticks);
            } catch (InvalidArgumentException) {
                ++$rejections;
            }
        }
        try {
            ExperienceOrbRegistry::splitValues(0);
        } catch (InvalidArgumentException) {
            ++$rejections;
        }
        self::assertSame(3, $rejections);
    }

    private static function ground(): CollisionBoxQuery
    {
        $ground = AxisAlignedBox::unitAt(0, 63, 0);

        return new class ($ground) implements CollisionBoxQuery {
            public function __construct(private AxisAlignedBox $ground) {}

            public function boxesIntersecting(AxisAlignedBox $area): array
            {
                return $this->ground->intersects($area) ? [$this->ground] : [];
            }

            public function hasCollision(AxisAlignedBox $area): bool
            {
                return $this->ground->intersects($area);
            }
        };
    }
}
