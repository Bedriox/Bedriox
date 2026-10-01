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

use Bedriox\Api\Entity\Value\EntityBlockChangeReason;
use Bedriox\Api\Entity\Value\EntityTransformReason;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityBlockChangedEvent;
use Bedriox\Api\Event\Entity\EntityBlockChangeEvent;
use Bedriox\Api\Event\Entity\EntityExplodedEvent;
use Bedriox\Api\Event\Entity\EntityExplosionPrimeEvent;
use Bedriox\Api\Event\Entity\EntitySplitEvent;
use Bedriox\Api\Event\Entity\EntityTransformedEvent;
use Bedriox\Api\Event\Entity\EntityTransformEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\TestCase;

final class HostileEntityEventFoundationTest extends TestCase
{
    public function testExplosionPrimeHasBoundedRollbackSafeMutation(): void
    {
        $entity = self::cow();
        $event = new EntityExplosionPrimeEvent($entity, $entity->getPosition(), 3.0);
        $state = $event->captureState();

        $event->setRadius(4.5);
        $event->setBreaksBlocks(false);
        $event->setFireChance(0.25);
        $event->cancel();

        self::assertSame(4.5, $event->radius());
        self::assertFalse($event->breaksBlocks());
        self::assertSame(0.25, $event->fireChance());
        self::assertTrue($event->isCancelled());

        $event->restoreState($state);
        self::assertSame(3.0, $event->radius());
        self::assertTrue($event->breaksBlocks());
        self::assertSame(0.0, $event->fireChance());
        self::assertFalse($event->isCancelled());

        $event->setReadOnly(true);
        $this->expectException(LogicException::class);
        $event->setRadius(2.0);
    }

    public function testExplosionPrimeRejectsUnboundedRadiusAndFireChance(): void
    {
        $entity = self::cow();
        $event = new EntityExplosionPrimeEvent($entity, $entity->getPosition(), 3.0);

        try {
            $event->setRadius(EntityExplosionPrimeEvent::MAXIMUM_RADIUS + 0.1);
            self::fail('An unbounded explosion radius must be rejected.');
        } catch (InvalidArgumentException) {
        }

        $this->expectException(InvalidArgumentException::class);
        $event->setFireChance(1.01);
    }

    public function testExplodedEventCarriesBoundedUniqueCommittedResults(): void
    {
        $entity = self::cow();
        $event = new EntityExplodedEvent(
            $entity,
            $entity->getPosition(),
            3.0,
            true,
            0.1,
            [new BlockPosition(0, 64, 0)],
            [$entity],
        );

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertCount(1, $event->affectedBlocks);
        self::assertSame([$entity], $event->affectedEntities);

        $this->expectException(InvalidArgumentException::class);
        new EntityExplodedEvent(
            $entity,
            $entity->getPosition(),
            3.0,
            true,
            0.1,
            [new BlockPosition(0, 64, 0), new BlockPosition(0, 64, 0)],
            [],
        );
    }

    public function testTransformEventsExposeMutableIntentAndImmutableResult(): void
    {
        $cow = self::cow();
        $zombie = self::zombie();
        $event = new EntityTransformEvent($cow, VanillaEntityType::ZOMBIE, EntityTransformReason::INFECTION);
        $state = $event->captureState();

        $event->setTargetType(VanillaEntityType::COW);
        $event->cancel();
        $event->restoreState($state);

        self::assertSame(VanillaEntityType::ZOMBIE, $event->targetType());
        self::assertFalse($event->isCancelled());

        $committed = new EntityTransformedEvent($cow, $zombie, EntityTransformReason::INFECTION);
        self::assertInstanceOf(PostEvent::class, $committed);
        self::assertSame($zombie, $committed->transformed);
    }

    public function testBlockChangeRetainsAuthoritativePositionAndRollsBack(): void
    {
        $position = new BlockPosition(1, 63, 2);
        $from = new Block($position, 'minecraft:grass_block');
        $to = new Block($position, 'minecraft:dirt');
        $event = new EntityBlockChangeEvent(self::cow(), $from, $to, EntityBlockChangeReason::GRAZE);
        $state = $event->captureState();

        $event->setTo(new Block($position, 'minecraft:air'));
        $event->cancel();
        $event->restoreState($state);

        self::assertSame($to, $event->to());
        self::assertFalse($event->isCancelled());
        self::assertInstanceOf(
            PostEvent::class,
            new EntityBlockChangedEvent(self::cow(), $from, $to, EntityBlockChangeReason::GRAZE),
        );

        $this->expectException(InvalidArgumentException::class);
        $event->setTo(new Block(new BlockPosition(2, 63, 2), 'minecraft:dirt'));
    }

    public function testSplitCountIsBoundedAndRollbackSafe(): void
    {
        $event = new EntitySplitEvent(self::zombie(), VanillaEntityType::ZOMBIE, 2);
        $state = $event->captureState();

        $event->setChildCount(4);
        $event->cancel();
        $event->restoreState($state);

        self::assertSame(2, $event->childCount());
        self::assertFalse($event->isCancelled());

        $this->expectException(InvalidArgumentException::class);
        $event->setChildCount(EntitySplitEvent::MAXIMUM_CHILDREN + 1);
    }

    private static function cow(): CowEntity
    {
        return new CowEntity(
            '00000000-0000-4000-8000-000000000101',
            101,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }

    private static function zombie(): ZombieEntity
    {
        return new ZombieEntity(
            '00000000-0000-4000-8000-000000000102',
            102,
            'world',
            new Position(1.0, 64.0, 0.0),
        );
    }
}
