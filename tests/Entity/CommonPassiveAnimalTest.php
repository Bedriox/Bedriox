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

use Bedriox\Api\Entity\Value\RabbitVariant;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityBredEvent;
use Bedriox\Api\Event\Entity\EntityBreedEvent;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class CommonPassiveAnimalTest extends TestCase
{
    public function testEverySpeciesOwnsItsExactDefinitionAndSharedBreedingLifecycle(): void
    {
        $animals = [
            VanillaEntityType::COW->value => new CowEntity(EntityUuid::random(), 1, 'world', new Position(0.0, 64.0, 0.0)),
            VanillaEntityType::PIG->value => new PigEntity(EntityUuid::random(), 2, 'world', new Position(0.0, 64.0, 0.0)),
            VanillaEntityType::CHICKEN->value => new ChickenEntity(EntityUuid::random(), 3, 'world', new Position(0.0, 64.0, 0.0)),
            VanillaEntityType::RABBIT->value => new RabbitEntity(EntityUuid::random(), 4, 'world', new Position(0.0, 64.0, 0.0)),
        ];
        foreach ($animals as $identifier => $animal) {
            self::assertSame($identifier, $animal->getType()->identifier());
            $animal->setLoveTicks(BreedableAnimalEntity::MAXIMUM_LOVE_TICKS);
            self::assertTrue($animal->isReadyToBreed());
            $animal->beginBreedingCooldown();
            self::assertFalse($animal->isReadyToBreed());
            $animal->setBaby(true);
            self::assertTrue($animal->isBaby());
            $animal->accelerateGrowth(BreedableAnimalEntity::BABY_GROWTH_TICKS);
            self::assertFalse($animal->isBaby());
        }
    }

    public function testSpeciesStateRoundTripsAndProjectsCurrentMetadata(): void
    {
        $pig = new PigEntity(EntityUuid::random(), 11, 'world', new Position(0.0, 64.0, 0.0), baby: true, saddled: true);
        $restoredPig = new PigEntity(EntityUuid::random(), 12, 'world', new Position(0.0, 64.0, 0.0));
        $restoredPig->restorePersistenceState($pig->persistenceVariant(), $pig->persistenceSchemaVersion(), $pig->persistenceData());
        self::assertTrue($restoredPig->isBaby());
        self::assertTrue($restoredPig->isSaddled());

        $rabbit = new RabbitEntity(EntityUuid::random(), 13, 'world', new Position(0.0, 64.0, 0.0), variant: RabbitVariant::GOLD);
        $restoredRabbit = new RabbitEntity(EntityUuid::random(), 14, 'world', new Position(0.0, 64.0, 0.0));
        $restoredRabbit->restorePersistenceState($rabbit->persistenceVariant(), $rabbit->persistenceSchemaVersion(), $rabbit->persistenceData());
        self::assertSame(RabbitVariant::GOLD, $restoredRabbit->getVariant());

        $projector = new BedrockLivingActorProjector();
        $metadata = $projector->metadata($restoredPig);
        $flags = $metadata[0]->value;
        self::assertIsInt($flags);
        self::assertNotSame(0, $flags & ActorFlag::Baby->mask());
        self::assertNotSame(0, $flags & ActorFlag::Saddled->mask());
        self::assertSame(RabbitVariant::GOLD->value, $projector->metadata($restoredRabbit)[2]->value);
        self::assertSame(0.6, $restoredRabbit->scale());
        self::assertSame(0.4, $restoredRabbit->collisionWidth());

        $restoredRabbit->setBaby(true);
        self::assertSame(0.4, $restoredRabbit->scale());
        self::assertSame(0.2, $restoredRabbit->collisionWidth());
    }

    public function testBreedingEventsExposeBoundedPreAndCommittedState(): void
    {
        $first = new CowEntity(EntityUuid::random(), 21, 'world', new Position(0.0, 64.0, 0.0));
        $second = new CowEntity(EntityUuid::random(), 22, 'world', new Position(1.0, 64.0, 0.0));
        $child = new CowEntity(EntityUuid::random(), 23, 'world', new Position(0.5, 64.0, 0.0), baby: true);
        $pre = new EntityBreedEvent($first, $second, VanillaEntityType::COW, 3);
        $pre->setExperience(7);
        self::assertSame(7, $pre->getExperience());
        $post = new EntityBredEvent($first, $second, $child, $pre->getExperience());
        self::assertSame($child, $post->child);
        self::assertSame(7, $post->experience);
    }

    public function testChickenEggTimerIsBoundedAndDurable(): void
    {
        $chicken = new ChickenEntity(EntityUuid::random(), 31, 'world', new Position(0.0, 64.0, 0.0), eggLayTicks: 20);
        self::assertTrue($chicken->advanceEggLayTimer(20));
        $chicken->resetEggLayTimer(6_000);
        self::assertSame(6_000, $chicken->getEggLayTicks());
    }
}
