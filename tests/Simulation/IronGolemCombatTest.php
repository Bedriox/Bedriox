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

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMeleeIntent;
use Bedriox\Server\Entity\Ai\AiMemoryStore;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\Goal\IronGolemAttackGoal;
use Bedriox\Server\Entity\Ai\PlayerIdentityAiWorldView;
use Bedriox\Server\Entity\Ai\Sensor\AngerTargetSensor;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Vanilla\IronGolemEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class IronGolemCombatTest extends TestCase
{
    private const string PLAYER_UUID = '00000000-0000-4000-8000-000000000041';

    public function testVillageGolemRetaliatesAgainstItsPlayerAttacker(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', self::PLAYER_UUID, 'Attacker', 71)));
        $simulation->tick();
        $golem = $simulation->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::IRON_GOLEM,
            SpawnCause::STRUCTURE,
            'world',
            new Position(0.0, 64.0, 2.0),
        ))->entity;
        self::assertInstanceOf(IronGolemEntity::class, $golem);

        self::assertTrue($simulation->enqueue($commands->attack(
            'player',
            $golem->getRuntimeId(),
            0,
        )));
        $simulation->tick();

        self::assertSame(self::PLAYER_UUID, $golem->getAngerTargetUniqueId());
        self::assertGreaterThanOrEqual(400, $golem->getRemainingAngerTicks());
        self::assertLessThanOrEqual(800, $golem->getRemainingAngerTicks());
    }

    public function testPlayerCreatedGolemDoesNotRetaliateAgainstPlayers(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $commands = new SimulationCommandFactory();
        self::assertTrue($simulation->enqueue($commands->join('player', self::PLAYER_UUID, 'Attacker', 71)));
        $simulation->tick();
        $golem = $simulation->entityRuntime()->registry()->spawn(
            static fn(string $uuid, int $runtimeId): IronGolemEntity => new IronGolemEntity(
                $uuid,
                $runtimeId,
                'world',
                new Position(0.0, 64.0, 2.0),
                playerCreated: true,
            ),
        );
        self::assertInstanceOf(IronGolemEntity::class, $golem);

        self::assertTrue($simulation->enqueue($commands->attack(
            'player',
            $golem->getRuntimeId(),
            0,
        )));
        $simulation->tick();

        self::assertNull($golem->getAngerTargetUniqueId());
        self::assertSame(0, $golem->getRemainingAngerTicks());
    }

    public function testAngerStatePersistsWithTheGolem(): void
    {
        $target = EntityUuid::random();
        $golem = new IronGolemEntity(EntityUuid::random(), 91, 'world', new Position(0.0, 64.0, 0.0));
        $golem->setAngerTargetUniqueId($target, 600);
        $copy = new IronGolemEntity(EntityUuid::random(), 92, 'world', new Position(0.0, 64.0, 0.0));
        $copy->restorePersistenceState(
            $golem->persistenceVariant(),
            $golem->persistenceSchemaVersion(),
            $golem->persistenceData(),
        );

        self::assertSame($target, $copy->getAngerTargetUniqueId());
        self::assertSame(600, $copy->getRemainingAngerTicks());
    }

    public function testAngryGolemPursuesBeforeUsingItsDelayedMeleeStrike(): void
    {
        $target = new AiPlayerSnapshot(self::PLAYER_UUID, 'world', new Position(8.0, 64.0, 0.0));
        $world = new IronGolemAiWorld($target);
        $golem = new IronGolemEntity(EntityUuid::random(), 91, 'world', new Position(0.0, 64.0, 0.0));
        $golem->setAngerTargetUniqueId(self::PLAYER_UUID, 600);
        $memory = new AiMemoryStore();
        $sensor = new AngerTargetSensor('bedriox:test_golem_anger', 1);
        $goal = new IronGolemAttackGoal();

        $sensor->sense($golem, $memory, new AiTickContext(20, $world));
        $goal->start($golem, $memory, new AiTickContext(20, $world));
        self::assertGreaterThan(0.0, $golem->getMotion()->x);
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 20));

        $world->target = new AiPlayerSnapshot(self::PLAYER_UUID, 'world', new Position(2.0, 64.0, 0.0));
        $sensor->sense($golem, $memory, new AiTickContext(21, $world));
        $goal->tick($golem, $memory, new AiTickContext(21, $world));
        self::assertGreaterThan(0.0, $golem->getMotion()->x, 'The golem must close to body-to-body melee range.');
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 21));

        $world->target = new AiPlayerSnapshot(self::PLAYER_UUID, 'world', new Position(1.0, 64.0, 0.0));
        $sensor->sense($golem, $memory, new AiTickContext(22, $world));
        $goal->tick($golem, $memory, new AiTickContext(22, $world));
        self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), 22));
        for ($tick = 23; $tick < 29; ++$tick) {
            $sensor->sense($golem, $memory, new AiTickContext($tick, $world));
            $goal->tick($golem, $memory, new AiTickContext($tick, $world));
            self::assertNull($memory->get(VanillaAiMemories::meleeIntent(), $tick));
        }
        $sensor->sense($golem, $memory, new AiTickContext(29, $world));
        $goal->tick($golem, $memory, new AiTickContext(29, $world));
        $intent = $memory->take(VanillaAiMemories::meleeIntent(), 29);
        self::assertInstanceOf(AiMeleeIntent::class, $intent);
        self::assertSame(self::PLAYER_UUID, $intent->targetPlayerId);
        self::assertSame(2.8, $intent->maximumReach);
    }
}

final class IronGolemAiWorld implements PlayerIdentityAiWorldView
{
    public function __construct(public AiPlayerSnapshot $target) {}

    public function nearbyEntities(AbstractMobEntity $entity, float $radius, int $limit): array
    {
        return [];
    }

    public function nearestPlayerDistanceSquared(AbstractMobEntity $entity): float
    {
        return $this->target->distanceSquaredTo($entity->internalPosition());
    }

    public function nearestPlayer(AbstractMobEntity $entity, float $radius): ?AiPlayerSnapshot
    {
        return $this->nearestPlayerDistanceSquared($entity) <= $radius ** 2 ? $this->target : null;
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
        return $playerId === $this->target->playerId ? $this->target : null;
    }
}
