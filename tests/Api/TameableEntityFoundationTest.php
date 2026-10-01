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

use Bedriox\Api\Entity\Capability\Angerable;
use Bedriox\Api\Entity\Capability\Ownable;
use Bedriox\Api\Entity\Capability\Sittable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Api\Entity\Controller\AngerableController;
use Bedriox\Api\Entity\Controller\BreedableAnimalController;
use Bedriox\Api\Entity\Controller\MobController;
use Bedriox\Api\Entity\Controller\TameableAnimalController;
use Bedriox\Api\Entity\Controller\TameableController;
use Bedriox\Api\Entity\Controller\WolfController;
use Bedriox\Api\Event\Entity\EntityTamedEvent;
use Bedriox\Api\Event\Entity\EntityTameEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class TameableEntityFoundationTest extends TestCase
{
    public function testCapabilitiesExposeStableReadState(): void
    {
        $tameable = $this->createStub(Tameable::class);
        $tameable->method('getOwnerUniqueId')->willReturn('00000000-0000-4000-8000-000000000101');
        $tameable->method('isTamed')->willReturn(true);

        $sittable = $this->createStub(Sittable::class);
        $sittable->method('isSitting')->willReturn(true);

        $angerable = $this->createStub(Angerable::class);
        $angerable->method('getAngerTargetUniqueId')->willReturn('00000000-0000-4000-8000-000000000102');
        $angerable->method('getRemainingAngerTicks')->willReturn(300);

        self::assertInstanceOf(Ownable::class, $tameable);
        self::assertSame('00000000-0000-4000-8000-000000000101', $tameable->getOwnerUniqueId());
        self::assertTrue($tameable->isTamed());
        self::assertTrue($sittable->isSitting());
        self::assertSame('00000000-0000-4000-8000-000000000102', $angerable->getAngerTargetUniqueId());
        self::assertSame(300, $angerable->getRemainingAngerTicks());
    }

    public function testControllersExposeTypedMutationIntent(): void
    {
        self::assertContains(
            MobController::class,
            (new ReflectionClass(TameableController::class))->getInterfaceNames(),
        );
        $animalControllers = (new ReflectionClass(TameableAnimalController::class))->getInterfaceNames();
        self::assertContains(BreedableAnimalController::class, $animalControllers);
        self::assertContains(TameableController::class, $animalControllers);

        $owner = new ReflectionMethod(TameableController::class, 'setOwner');
        $ownerType = $owner->getParameters()[0]->getType();
        self::assertNotNull($ownerType);
        self::assertSame('?Bedriox\\Api\\Player\\Player', (string) $ownerType);

        self::assertTrue((new ReflectionMethod(TameableController::class, 'setSitting'))->isPublic());
        self::assertTrue((new ReflectionMethod(AngerableController::class, 'setAngerTarget'))->isPublic());
        $wolfControllers = (new ReflectionClass(WolfController::class))->getInterfaceNames();
        self::assertContains(TameableAnimalController::class, $wolfControllers);
        self::assertContains(AngerableController::class, $wolfControllers);
    }

    public function testTameEventsCarryTypedIntentAndCommittedObservation(): void
    {
        $entity = $this->createStub(Tameable::class);
        $player = self::player();
        $event = new EntityTameEvent($entity, $player);
        $state = $event->captureState();

        $event->cancel();
        self::assertTrue($event->isCancelled());
        $event->restoreState($state);
        self::assertFalse($event->isCancelled());
        self::assertSame($entity, $event->entity);
        self::assertSame($player, $event->player);

        $committed = new EntityTamedEvent($entity, $player);
        self::assertInstanceOf(PostEvent::class, $committed);
        self::assertSame($entity, $committed->entity);
        self::assertSame($player, $committed->player);
    }

    public function testTameIntentCannotBeCancelledByMonitorListener(): void
    {
        $event = new EntityTameEvent($this->createStub(Tameable::class), self::player());
        $event->setReadOnly(true);

        $this->expectException(LogicException::class);
        $event->cancel();
    }

    private static function player(): Player
    {
        return new Player(
            'Tamer',
            '00000000-0000-4000-8000-000000000100',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
