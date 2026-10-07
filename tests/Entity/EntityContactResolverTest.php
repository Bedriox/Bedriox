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

namespace Bedriox\Server\Tests\Entity;

use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityContactResolver;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorkBudget;
use Bedriox\Server\Entity\Mount\MountRegistry;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class EntityContactResolverTest extends TestCase
{
    public function testContactWakesSleepingMobAndAppliesNoVerticalImpulse(): void
    {
        [$registry, $first, $second] = self::overlappingMobs();
        $first->setActivationState(MobActivationState::SLEEPING);

        $metrics = (new EntityContactResolver($registry))->resolve(new EntityWorkBudget());

        self::assertSame(1, $metrics->contacts);
        self::assertSame(MobActivationState::REDUCED, $first->getActivationState());
        self::assertSame(0.0, $first->getMotion()->y);
        self::assertSame(0.0, $second->getMotion()->y);
    }

    public function testPassengerAndVehicleDoNotPushTheirOwnMountAssembly(): void
    {
        [$registry, $passenger, $vehicle] = self::overlappingMobs();
        $mounts = new MountRegistry();
        self::assertNotNull($mounts->mountEntity($passenger, $vehicle, MountSeat::DRIVER));

        $metrics = (new EntityContactResolver($registry, $mounts))->resolve(new EntityWorkBudget());

        self::assertSame(0, $metrics->contacts);
        self::assertSame(0.0, $passenger->getMotion()->lengthSquared());
        self::assertSame(0.0, $vehicle->getMotion()->lengthSquared());
    }

    public function testRepeatedContactDoesNotAccumulateSlidingMomentum(): void
    {
        [$registry, $first, $second] = self::overlappingMobs();
        $resolver = new EntityContactResolver($registry);

        $resolver->resolve(new EntityWorkBudget());
        $firstMotion = $first->getMotion();
        $resolver->resolve(new EntityWorkBudget());

        self::assertLessThanOrEqual(0.05, hypot($first->getMotion()->x, $first->getMotion()->z));
        self::assertLessThanOrEqual(0.05, hypot($second->getMotion()->x, $second->getMotion()->z));
        self::assertNotEquals(new EntityMotion(), $firstMotion);
    }

    /** @return array{EntityRegistry, AbstractMobEntity, AbstractMobEntity} */
    private static function overlappingMobs(): array
    {
        $registry = new EntityRegistry();
        $spawns = new EntitySpawnService($registry, EntityDefinitionRegistry::baseline());
        $first = $spawns->spawn(new EntitySpawnRequest(
            VanillaEntityType::COW,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        $second = $spawns->spawn(new EntitySpawnRequest(
            VanillaEntityType::PIG,
            SpawnCause::COMMAND,
            'world',
            new Position(0.5, 64.0, 0.5),
        ))->entity;
        self::assertInstanceOf(AbstractMobEntity::class, $first);
        self::assertInstanceOf(AbstractMobEntity::class, $second);

        return [$registry, $first, $second];
    }
}
