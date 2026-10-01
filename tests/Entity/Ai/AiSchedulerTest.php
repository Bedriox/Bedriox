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

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\AiClock;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiScheduler;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorkBudget;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\Sensor\NearestPlayerSensor;
use Bedriox\Server\Entity\Ai\TargetAwareAiWorldView;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class AiSchedulerTest extends TestCase
{
    public function testClassifiesPlayerCenteredActivationAndSkipsSleepingMobs(): void
    {
        $active = new ZombieEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $reduced = new CowEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $sleeping = new CowEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 64.0, 0.0));
        $world = new DistanceAiWorldView([1 => 16.0 ** 2, 2 => 48.0 ** 2, 3 => 100.0 ** 2]);
        $scheduler = new AiScheduler(new FixedAiClock());

        $metrics = $scheduler->tick(
            [$sleeping, $active, $reduced],
            new AiTickContext(3, $world),
            true,
        );

        self::assertSame(MobActivationState::ACTIVE, $active->getActivationState());
        self::assertSame(MobActivationState::REDUCED, $reduced->getActivationState());
        self::assertSame(MobActivationState::SLEEPING, $sleeping->getActivationState());
        self::assertSame(1, $metrics->active);
        self::assertSame(1, $metrics->reduced);
        self::assertSame(1, $metrics->sleeping);
        self::assertSame(2, $metrics->ticked);
    }

    public function testMaximumEntityBudgetAdvancesFairCursor(): void
    {
        $mobs = [];
        $distances = [];
        for ($id = 1; $id <= 4; ++$id) {
            $mobs[] = new ZombieEntity(EntityUuid::random(), $id, 'world', new Position(0.0, 64.0, 0.0));
            $distances[$id] = 1.0;
        }
        $world = new DistanceAiWorldView($distances);
        $scheduler = new AiScheduler(new FixedAiClock());
        $budget = new AiWorkBudget(2, 5_000_000);

        $first = $scheduler->tick($mobs, new AiTickContext(1, $world), true, $budget);
        $second = $scheduler->tick($mobs, new AiTickContext(2, $world), true, $budget);

        self::assertSame(2, $first->considered);
        self::assertTrue($first->budgetExhausted);
        self::assertSame(2, $second->considered);
        self::assertSame([1, 2, 3, 4], $world->queriedRuntimeIds);
    }

    public function testBudgetAlsoBoundsTargetSensorQueries(): void
    {
        $behavior = new AiBehaviorDefinition(sensors: [
            new NearestPlayerSensor('bedriox:test_nearest_player', 1, 32.0, 5),
        ]);
        $mobs = [];
        for ($id = 1; $id <= 4; ++$id) {
            $mobs[] = new ZombieEntity(
                EntityUuid::random(),
                $id,
                'world',
                new Position(0.0, 64.0, 0.0),
                $behavior,
            );
        }
        $world = new BudgetTargetAiWorldView();
        $metrics = (new AiScheduler(new FixedAiClock()))->tick(
            $mobs,
            new AiTickContext(1, $world),
            true,
            new AiWorkBudget(2, 5_000_000),
        );

        self::assertSame(2, $metrics->considered);
        self::assertSame(2, $metrics->sensorsRun);
        self::assertSame(2, $world->activationQueries);
        self::assertSame(2, $world->targetQueries);
    }

    public function testScheduledCallbackUsesTheSameActivationCadenceAndBudget(): void
    {
        $active = new ZombieEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $reduced = new CowEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $sleeping = new CowEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 64.0, 0.0));
        $world = new DistanceAiWorldView([1 => 16.0 ** 2, 2 => 48.0 ** 2, 3 => 100.0 ** 2]);
        $scheduled = [];

        (new AiScheduler(new FixedAiClock()))->tick(
            [$sleeping, $active, $reduced],
            new AiTickContext(3, $world),
            true,
            new AiWorkBudget(3, 5_000_000),
            static function (AbstractMobEntity $entity) use (&$scheduled): void {
                $scheduled[] = $entity->getRuntimeId();
            },
        );

        self::assertSame([1, 2], $scheduled);
    }
}

final class FixedAiClock implements AiClock
{
    public function nanoseconds(): int
    {
        return 1_000_000;
    }
}

final class DistanceAiWorldView implements AiWorldView
{
    /** @var list<int> */
    public array $queriedRuntimeIds = [];

    /** @param array<int, float> $distances */
    public function __construct(private readonly array $distances) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        $this->queriedRuntimeIds[] = $entity->getRuntimeId();

        return $this->distances[$entity->getRuntimeId()] ?? null;
    }
}

final class BudgetTargetAiWorldView implements TargetAwareAiWorldView
{
    public int $activationQueries = 0;
    public int $targetQueries = 0;

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): float
    {
        ++$this->activationQueries;

        return 1.0;
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): AiPlayerSnapshot
    {
        ++$this->targetQueries;

        return new AiPlayerSnapshot('player-one', 'world', new Position(1.0, 64.0, 0.0));
    }

    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        ++$this->targetQueries;

        return in_array('minecraft:wheat', $itemIdentifiers, true)
            ? new AiPlayerSnapshot('player-one', 'world', new Position(1.0, 64.0, 0.0), true, 'minecraft:wheat')
            : null;
    }
}
