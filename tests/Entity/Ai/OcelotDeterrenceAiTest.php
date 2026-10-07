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

use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiWorldView;
use Bedriox\Server\Entity\Ai\Goal\AvoidFelineGoal;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class OcelotDeterrenceAiTest extends TestCase
{
    public function testCreeperFleesNearbyOcelotAndStopsWhenItLeavesRange(): void
    {
        $creeper = new CreeperEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $ocelot = new OcelotEntity(EntityUuid::random(), 2, 'world', new Position(2.0, 64.0, 0.0));
        $world = new FelineAiWorld([$ocelot]);
        $goal = new AvoidFelineGoal();
        $memory = new AiMemoryStore();

        self::assertTrue($goal->canStart($creeper, $memory, new AiTickContext(2, $world)));
        $goal->start($creeper, $memory, new AiTickContext(2, $world));
        self::assertLessThan(0.0, $creeper->getMotion()->x);

        $world->entities = [];
        self::assertFalse($goal->shouldContinue($creeper, $memory, new AiTickContext(4, $world)));
        $goal->stop($creeper, $memory, new AiTickContext(4, $world));
        self::assertSame(0.0, $creeper->getMotion()->x);
    }

    public function testGoalIgnoresDeadOutOfRangeAndUnrelatedEntities(): void
    {
        $creeper = new CreeperEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));
        $far = new OcelotEntity(EntityUuid::random(), 2, 'world', new Position(7.0, 64.0, 0.0));
        $dead = new OcelotEntity(EntityUuid::random(), 3, 'world', new Position(2.0, 64.0, 0.0));
        $dead->damage(100.0);
        $world = new FelineAiWorld([$far, $dead, $creeper]);

        self::assertFalse((new AvoidFelineGoal())->canStart(
            $creeper,
            new AiMemoryStore(),
            new AiTickContext(2, $world),
        ));
    }
}

final class FelineAiWorld implements AiWorldView
{
    /** @param list<AbstractEntity> $entities */
    public function __construct(public array $entities) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return array_slice(array_values(array_filter(
            $this->entities,
            static fn(AbstractEntity $candidate): bool => $candidate !== $entity
                && $candidate->getWorldName() === $entity->getWorldName()
                && $candidate->internalPosition()->distanceTo($entity->internalPosition()) ** 2 <= $radius ** 2,
        )), 0, $limit);
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): ?float
    {
        return null;
    }
}
