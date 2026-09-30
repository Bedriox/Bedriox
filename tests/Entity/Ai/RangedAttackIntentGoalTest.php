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

namespace Bedriox\Server\Tests\Entity\Ai;

use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiRangedIntent;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\Goal\RangedAttackIntentGoal;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\SkeletonEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class RangedAttackIntentGoalTest extends TestCase
{
    public function testSkeletonRangedGoalBacksAwayFiresAndHonorsCooldown(): void
    {
        $skeleton = $this->skeleton();
        $memory = new AiMemoryStore();
        $goal = $this->goal();
        $world = new RangedEmptyAiWorldView();
        $memory->put(
            VanillaAiMemories::nearestPlayer(),
            new AiPlayerSnapshot('target', 'world', new Position(3.0, 64.0, 0.0)),
            100,
        );

        $goal->start($skeleton, $memory, new AiTickContext(10, $world));
        $intent = $memory->take(VanillaAiMemories::rangedIntent(), 10);

        self::assertInstanceOf(AiRangedIntent::class, $intent);
        self::assertSame('target', $intent->targetPlayerId);
        self::assertSame(10, $intent->createdAtTick);
        self::assertSame(15.0, $intent->maximumRange);
        self::assertSame(1.6, $intent->projectileSpeed);
        self::assertLessThan(0.0, $skeleton->getMotion()->x);

        $goal->tick($skeleton, $memory, new AiTickContext(69, $world));
        self::assertNull($memory->take(VanillaAiMemories::rangedIntent(), 69));

        $goal->tick($skeleton, $memory, new AiTickContext(70, $world));
        self::assertNotNull($memory->take(VanillaAiMemories::rangedIntent(), 70));
    }

    public function testSkeletonRangedGoalApproachesButDoesNotFireBeyondRange(): void
    {
        $skeleton = $this->skeleton();
        $memory = new AiMemoryStore();
        $goal = $this->goal();
        $memory->put(
            VanillaAiMemories::nearestPlayer(),
            new AiPlayerSnapshot('target', 'world', new Position(16.0, 64.0, 0.0)),
            100,
        );

        $goal->start($skeleton, $memory, new AiTickContext(1, new RangedEmptyAiWorldView()));

        self::assertGreaterThan(0.0, $skeleton->getMotion()->x);
        self::assertNull($memory->take(VanillaAiMemories::rangedIntent(), 1));
        self::assertFalse($memory->contains(VanillaAiMemories::rangedCooldown(), 1));
    }

    private function skeleton(): SkeletonEntity
    {
        return new SkeletonEntity(
            EntityUuid::random(),
            20,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }

    private function goal(): RangedAttackIntentGoal
    {
        return new RangedAttackIntentGoal(
            'bedriox:test_ranged',
            100,
            5.0,
            15.0,
            60,
            1.6,
            0.1,
        );
    }
}

final class RangedEmptyAiWorldView implements AiWorldView
{
    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return null;
    }
}
