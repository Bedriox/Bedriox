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

use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\Vanilla\Cow;
use Bedriox\Api\Entity\Vanilla\Zombie;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EntityTypeTest extends TestCase
{
    public function testVanillaEntitiesExposeStableTypeCategoryAndMarkerInterfaces(): void
    {
        $cow = new CowEntity(EntityUuid::random(), 10, 'world', new Position(1.0, 65.0, 2.0));
        $zombie = new ZombieEntity(EntityUuid::random(), 11, 'world', new Position(3.0, 65.0, 4.0));

        self::assertInstanceOf(Cow::class, $cow);
        self::assertSame(VanillaEntityType::COW, $cow->getType());
        self::assertSame(EntityCategory::ANIMAL, $cow->getCategory());
        self::assertSame(10.0, $cow->getHealth());
        self::assertInstanceOf(Zombie::class, $zombie);
        self::assertSame(VanillaEntityType::ZOMBIE, $zombie->getType());
        self::assertSame(EntityCategory::MONSTER, $zombie->getCategory());
        self::assertSame(20.0, $zombie->getHealth());
    }

    public function testLivingHealthMutationIsBoundedAndRetainsDeadState(): void
    {
        $zombie = new ZombieEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0));

        self::assertSame(6.0, $zombie->damage(6.0));
        self::assertSame(14.0, $zombie->getHealth());
        self::assertSame(6.0, $zombie->heal(10.0));
        self::assertSame(20.0, $zombie->getHealth());
        self::assertSame(20.0, $zombie->damage(100.0));
        self::assertFalse($zombie->isAlive());

        $restoredDead = new ZombieEntity(
            EntityUuid::random(),
            2,
            'world',
            new Position(0.0, 64.0, 0.0),
            health: 0.0,
        );
        self::assertFalse($restoredDead->isAlive());
    }

    public function testCustomTypesAreNamespacedAndCannotClaimMinecraftIdentity(): void
    {
        self::assertSame('example:guard', (new CustomEntityType('example:guard'))->identifier());

        $rejected = 0;
        foreach (['zombie', 'Minecraft:zombie', 'minecraft:zombie', 'bad namespace:guard'] as $identifier) {
            try {
                new CustomEntityType($identifier);
            } catch (InvalidArgumentException) {
                ++$rejected;
            }
        }
        self::assertSame(4, $rejected);
    }
}
