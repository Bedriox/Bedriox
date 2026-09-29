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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Entity\EntityCombustEvent;
use Bedriox\Api\Event\Entity\EntityDamageByEntityEvent;
use Bedriox\Api\Event\Entity\EntityDeathEvent;
use Bedriox\Api\Event\Entity\EntityEffectRemoveEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangedEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangeEvent;
use Bedriox\Api\Event\Entity\EntitySpawnEvent;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class EntityEventTest extends TestCase
{
    public function testNaturalEffectExpirationCannotBeCancelled(): void
    {
        $cow = new CowEntity('00000000-0000-4000-8000-000000000099', 99, 'world', new Position(0.0, 64.0, 0.0));
        $event = new EntityEffectRemoveEvent(
            $cow,
            new EffectInstance(EffectType::SPEED, 1),
            EffectCause::EXPIRATION,
        );

        $this->expectException(LogicException::class);
        $event->cancel();
    }

    public function testSpawnAndDamageEventsExposeTypedEntitiesAndBoundedMutableDamage(): void
    {
        $cow = new CowEntity('00000000-0000-4000-8000-000000000001', 1, 'world', new Position(0.0, 64.0, 0.0));
        $zombie = new ZombieEntity('00000000-0000-4000-8000-000000000002', 2, 'world', new Position(1.0, 64.0, 0.0));
        $spawn = new EntitySpawnEvent($cow, SpawnCause::SPAWN_EGG);
        self::assertFalse($spawn->isCancelled());
        $spawn->cancel();
        self::assertTrue($spawn->isCancelled());

        $damage = new EntityDamageByEntityEvent($zombie, $cow, EntityDamageCause::ATTACK, 3.0);
        $damage->setDamage(4.5);
        self::assertSame(4.5, $damage->damage());
        self::assertSame($zombie, $damage->damager);
        self::assertSame($cow, $damage->entity);
    }

    public function testCombustEventExposesTypedCauseAndBoundedMutableDuration(): void
    {
        $zombie = new ZombieEntity('00000000-0000-4000-8000-000000000003', 3, 'world', new Position(0.0, 64.0, 0.0));
        $event = new EntityCombustEvent($zombie, EntityCombustionCause::SUNLIGHT, 160);

        $event->setDurationTicks(80);

        self::assertSame($zombie, $event->entity);
        self::assertSame(EntityCombustionCause::SUNLIGHT, $event->cause);
        self::assertSame(80, $event->durationTicks());
        self::assertFalse($event->isCancelled());
    }

    public function testEntityEquipmentEventsCarryItemAndDropChanceChanges(): void
    {
        $zombie = new ZombieEntity('00000000-0000-4000-8000-000000000004', 4, 'world', new Position(0.0, 64.0, 0.0));
        $leather = new ItemStack('minecraft:leather_helmet', 1);
        $iron = new ItemStack('minecraft:iron_helmet', 1);
        $event = new EntityEquipmentChangeEvent(
            $zombie,
            EquipmentSlot::HEAD,
            $leather,
            $iron,
            0.1,
            0.25,
        );

        $event->setItem(null);
        $event->setDropChance(0.5);

        self::assertNull($event->item());
        self::assertSame(0.5, $event->dropChance());
        self::assertFalse($event->isCancelled());

        $committed = new EntityEquipmentChangedEvent(
            $zombie,
            EquipmentSlot::HEAD,
            $leather,
            null,
            0.1,
            0.5,
        );
        self::assertSame($leather, $committed->previous);
    }

    public function testEntityDeathDropsAreStableMutableAndBounded(): void
    {
        $zombie = new ZombieEntity('00000000-0000-4000-8000-000000000005', 5, 'world', new Position(0.0, 64.0, 0.0));
        $flesh = new ItemStack('minecraft:rotten_flesh', 2);
        $event = new EntityDeathEvent($zombie, null, [$flesh]);

        self::assertSame([$flesh], $event->getDrops());
        $event->addDrop(new ItemStack('minecraft:iron_ingot', 1));
        self::assertCount(2, $event->getDrops());
        $event->setDrops([new ItemStack('minecraft:carrot', 1)]);
        self::assertSame('minecraft:carrot', $event->getDrops()[0]->identifier);
        $event->clearDrops();
        self::assertSame([], $event->getDrops());
        $this->expectException(InvalidArgumentException::class);
        /** @phpstan-ignore argument.type */
        $event->setDrops(['minecraft:stone']);
    }
}
