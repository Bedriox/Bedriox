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
use Bedriox\Api\Player\GameMode;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Simulation\DamageCause;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;

final class KillDamageTest extends TestCase
{
    public function testKillBypassesCreativeInvulnerabilityThroughTheDamagePipeline(): void
    {
        $factory = new SimulationCommandFactory();
        $world = new WorldSimulation();
        self::assertTrue($world->enqueue($factory->join('session', 'identity', 'Player')));
        $world->tick();
        self::assertTrue($world->enqueue($factory->changeGameMode('session', GameMode::CREATIVE)));
        $world->tick();

        self::assertTrue($world->enqueueKillPlayer('identity'));
        $events = $world->tick()->events;
        $damaged = array_values(array_filter($events, static fn(object $event): bool => $event instanceof PlayerDamaged));
        $died = array_values(array_filter($events, static fn(object $event): bool => $event instanceof PlayerDied));

        self::assertCount(1, $damaged);
        self::assertSame(DamageCause::Kill, $damaged[0]->cause);
        self::assertCount(1, $died);
        self::assertSame(DamageCause::Kill, $died[0]->cause);
        self::assertFalse($world->snapshot()->players[0]->alive);
    }

    public function testGeneralEntityKillUsesAuthoritativeDamageAndDeathProjection(): void
    {
        $world = new WorldSimulation();
        $spawn = $world->spawnEntity(new EntitySpawnRequest(
            VanillaEntityType::ZOMBIE,
            SpawnCause::COMMAND,
            'world',
            new Position(0.0, 64.0, 0.0),
        ));
        self::assertInstanceOf(AbstractLivingEntity::class, $spawn->entity);

        self::assertTrue($world->enqueueKillEntity(
            $spawn->entity->getRuntimeId(),
            $spawn->entity->getUniqueId(),
        ));
        $events = $world->tick()->events;

        self::assertCount(1, array_filter($events, static fn(object $event): bool => $event instanceof EntityActorDamaged));
        self::assertCount(1, array_filter($events, static fn(object $event): bool => $event instanceof EntityActorDied));
        self::assertFalse($spawn->entity->isAlive());
    }
}
