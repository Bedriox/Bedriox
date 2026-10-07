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

use Bedriox\Api\Entity\Value\PandaActivity;
use Bedriox\Api\Entity\Value\PandaGene;
use Bedriox\Server\Entity\Ai\AiGoal;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiSensor;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\PandaWanderGoal;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\LandAnimalAiBehaviors;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class PandaAiBehaviorTest extends TestCase
{
    public function testAggressivePandaUsesExactAngerTargetAndPersistsIt(): void
    {
        $target = new AiPlayerSnapshot(EntityUuid::random(), 'world', new Position(1.0, 64.0, 0.0));
        $panda = new PandaEntity(
            EntityUuid::random(),
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
            mainGene: PandaGene::AGGRESSIVE,
            hiddenGene: PandaGene::AGGRESSIVE,
        );
        $panda->setAngerTargetUniqueId($target->playerId, 400);
        $behavior = LandAnimalAiBehaviors::panda();
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, new PolarBearAiWorld([$target]));

        $behavior->sensors[0]->sense($panda, $memory, $context);

        self::assertSame($target, $memory->get(VanillaAiMemories::nearestPlayer(), 20));
        $restored = new PandaEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0));
        $restored->restorePersistenceState(
            $panda->persistenceVariant(),
            $panda->persistenceSchemaVersion(),
            $panda->persistenceData(),
        );
        self::assertSame($target->playerId, $restored->getAngerTargetUniqueId());
        self::assertSame(400, $restored->getRemainingAngerTicks());
    }

    public function testPandaBehaviorIsBoundedAndDoesNotAcquirePlayersWithoutAnger(): void
    {
        $target = new AiPlayerSnapshot(EntityUuid::random(), 'world', new Position(1.0, 64.0, 0.0));
        $panda = new PandaEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $behavior = LandAnimalAiBehaviors::panda();
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, new PolarBearAiWorld([$target]));

        $behavior->sensors[0]->sense($panda, $memory, $context);

        self::assertNull($memory->get(VanillaAiMemories::nearestPlayer(), 20));
        self::assertSame(
            ['bedriox:panda_anger_target', 'bedriox:panda_nearest_player', 'bedriox:panda_food', 'bedriox:panda_hurt'],
            array_map(static fn(AiSensor $sensor): string => $sensor->identifier(), $behavior->sensors),
        );
        self::assertSame(
            ['bedriox:panda_worried_avoid_threat', 'bedriox:panda_attack', 'bedriox:panda_chase', 'bedriox:panda_follow_bamboo', 'bedriox:panda_wander'],
            array_map(static fn(AiGoal $goal): string => $goal->identifier(), $behavior->goals),
        );
    }

    public function testActivePandaPoseCannotStartOrContinueWandering(): void
    {
        $panda = new PandaEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $goal = new PandaWanderGoal();
        $memory = new AiMemoryStore();
        $context = new AiTickContext(20, new PolarBearAiWorld([]));

        self::assertTrue($goal->canStart($panda, $memory, $context));
        $goal->start($panda, $memory, $context);
        $panda->setActivity(PandaActivity::ROLLING);

        self::assertFalse($goal->canStart($panda, $memory, $context));
        self::assertFalse($goal->shouldContinue($panda, $memory, $context));
    }
}
