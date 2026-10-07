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
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMeleeIntent;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\PolarBearAttackGoal;
use Bedriox\Server\Entity\Ai\Goal\PolarBearCubFleeGoal;
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use Bedriox\Server\Entity\Ai\Sensor\PolarBearThreatSensor;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\LandAnimalAiBehaviors;
use Bedriox\Server\Entity\Vanilla\PolarBearEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PolarBearAiBehaviorTest extends TestCase
{
    public function testCalmAdultNeverAcquiresOrAttacksAnArbitraryNearbyPlayer(): void
    {
        $nearby = self::player(new Position(1.0, 64.0, 0.0));
        $world = new PolarBearAiWorld([$nearby]);
        $bear = self::adult();
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, $world);

        (new PolarBearThreatSensor())->sense($bear, $memory, $context);

        self::assertNull($memory->get(VanillaAiMemories::nearestPlayer(), 20));
        self::assertFalse((new PolarBearAttackGoal())->canStart($bear, $memory, $context));
        self::assertSame(0.0, $bear->getMotion()->x);
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 20));
    }

    public function testProvokedAdultPursuesOnlyItsExactAngerTarget(): void
    {
        $angerTarget = self::player(new Position(8.0, 64.0, 0.0));
        $closerBystander = self::player(new Position(1.0, 64.0, 0.0));
        $world = new PolarBearAiWorld([$closerBystander, $angerTarget]);
        $bear = self::adult();
        $bear->setAngerTargetUniqueId($angerTarget->playerId, 400);
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, $world);
        $sensor = new PolarBearThreatSensor();
        $goal = new PolarBearAttackGoal();

        $sensor->sense($bear, $memory, $context);
        $goal->start($bear, $memory, $context);

        self::assertSame($angerTarget, $memory->get(VanillaAiMemories::nearestPlayer(), 20));
        self::assertGreaterThan(0.0, $bear->getMotion()->x);
        self::assertSame(0.0, $bear->getMotion()->z);
        self::assertFalse($bear->isStanding());
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 20));
    }

    public function testProvokedAdultEmitsCooldownBoundedMeleeIntent(): void
    {
        $target = self::player(new Position(1.0, 64.0, 0.0));
        $world = new PolarBearAiWorld([$target]);
        $bear = self::adult();
        $bear->setAngerTargetUniqueId($target->playerId, 400);
        $memory = new AiMemoryStore();
        $sensor = new PolarBearThreatSensor();
        $goal = new PolarBearAttackGoal();

        $sensor->sense($bear, $memory, new AiTickContext(20, $world));
        $goal->start($bear, $memory, new AiTickContext(20, $world));
        $intent = $memory->take(VanillaAiMemories::meleeIntent(), 20);

        self::assertInstanceOf(AiMeleeIntent::class, $intent);
        self::assertSame($target->playerId, $intent->targetPlayerId);
        self::assertSame(6.0, $intent->damage);
        self::assertSame(2.0, $intent->maximumReach);
        self::assertSame(0.0, $bear->getMotion()->x);
        self::assertTrue($bear->isStanding());

        $goal->tick($bear, $memory, new AiTickContext(21, $world));
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 21));
        $goal->stop($bear, $memory, new AiTickContext(22, $world));
        self::assertFalse($bear->isStanding());
    }

    public function testHurtCubFleesButNeverAcquiresAnAttackIntent(): void
    {
        $nearby = self::player(new Position(2.0, 64.0, 0.0));
        $world = new PolarBearAiWorld([$nearby]);
        $cub = self::cub();
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, $world);
        (new PolarBearThreatSensor())->sense($cub, $memory, $context);
        $memory->put(VanillaAiMemories::hurt(), true, 80);
        $flee = new PolarBearCubFleeGoal();

        self::assertTrue($flee->canStart($cub, $memory, $context));
        $flee->start($cub, $memory, $context);

        self::assertLessThan(0.0, $cub->getMotion()->x);
        self::assertFalse((new PolarBearAttackGoal())->canStart($cub, $memory, $context));
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 20));
    }

    public function testUnhurtCubRemainsPassiveAndAdultNeverUsesCubFleeGoal(): void
    {
        $nearby = self::player(new Position(2.0, 64.0, 0.0));
        $world = new PolarBearAiWorld([$nearby]);
        $context = new AiTickContext(20, $world);
        $flee = new PolarBearCubFleeGoal();

        $cub = self::cub();
        $cubMemory = new AiMemoryStore();
        (new PolarBearThreatSensor())->sense($cub, $cubMemory, $context);
        self::assertFalse($flee->canStart($cub, $cubMemory, $context));

        $adult = self::adult();
        $adultMemory = new AiMemoryStore();
        $adultMemory->put(VanillaAiMemories::nearestPlayer(), $nearby, 40);
        $adultMemory->put(VanillaAiMemories::hurt(), true, 40);
        self::assertFalse($flee->canStart($adult, $adultMemory, $context));
    }

    public function testDefaultBehaviorUsesOnlyPolarBearSpecificThreatGoals(): void
    {
        $behavior = LandAnimalAiBehaviors::polarBear();

        self::assertSame(
            ['bedriox:polar_bear_threat', 'bedriox:polar_bear_hurt'],
            array_map(static fn(AiSensor $sensor): string => $sensor->identifier(), $behavior->sensors),
        );
        self::assertSame(
            ['bedriox:polar_bear_attack', 'bedriox:polar_bear_cub_flee', 'bedriox:polar_bear_wander'],
            array_map(static fn(AiGoal $goal): string => $goal->identifier(), $behavior->goals),
        );
    }

    private static function adult(): PolarBearEntity
    {
        return new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }

    private static function cub(): PolarBearEntity
    {
        return new PolarBearEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            baby: true,
        );
    }

    private static function player(Position $position): AiPlayerSnapshot
    {
        return new AiPlayerSnapshot(EntityUuid::random(), 'world', $position);
    }
}

final class PolarBearAiWorld implements PlayerIdentityAiWorldView
{
    /** @param list<AiPlayerSnapshot> $players */
    public function __construct(private readonly array $players) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return $this->nearestPlayer($entity, 256.0)?->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        $nearest = null;
        $nearestDistance = null;
        foreach ($this->players as $player) {
            if ($player->worldName !== $entity->getWorldName()) {
                continue;
            }
            $distance = $player->distanceSquaredTo($entity->internalPosition());
            if ($distance <= $radius ** 2 && ($nearestDistance === null || $distance < $nearestDistance)) {
                $nearest = $player;
                $nearestDistance = $distance;
            }
        }

        return $nearest;
    }

    public function nearestPlayerHolding(
        AbstractMobEntity $entity,
        float $radius,
        array $itemIdentifiers,
    ): ?AiPlayerSnapshot {
        return null;
    }

    public function playerByIdentity(string $playerId): ?AiPlayerSnapshot
    {
        foreach ($this->players as $player) {
            if ($player->playerId === $playerId) {
                return $player;
            }
        }

        return null;
    }
}
