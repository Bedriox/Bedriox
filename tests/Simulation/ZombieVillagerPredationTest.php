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

use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\VillagerEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\WorldSimulation;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

final class ZombieVillagerPredationTest extends TestCase
{
    public function testCloserVillagerIsAttackedWhileADistantPlayerIsVisible(): void
    {
        $simulation = new WorldSimulation(entityAiEnabled: false, spawnAnimals: false, spawnMonsters: false);
        $zombie = $simulation->entityRuntime()->registry()->spawn(
            static fn(string $uuid, int $runtimeId): ZombieEntity => new ZombieEntity(
                $uuid,
                $runtimeId,
                'world',
                new Position(0.0, 64.0, 0.0),
            ),
        );
        self::assertInstanceOf(ZombieEntity::class, $zombie);
        $villager = $simulation->entityRuntime()->registry()->spawn(
            static fn(string $uuid, int $runtimeId): VillagerEntity => new VillagerEntity(
                $uuid,
                $runtimeId,
                'world',
                new Position(1.0, 64.0, 0.0),
            ),
        );
        self::assertInstanceOf(VillagerEntity::class, $villager);
        $zombie->aiRuntime()->memory()->put(
            VanillaAiMemories::nearestPlayer(),
            new AiPlayerSnapshot(EntityUuid::random(), 'world', new Position(20.0, 64.0, 0.0)),
            100,
        );

        (new ReflectionMethod(WorldSimulation::class, 'advanceZombieVillagerPredation'))->invoke($simulation);

        self::assertLessThan($villager->getMaximumHealth(), $villager->getHealth());
    }
}
