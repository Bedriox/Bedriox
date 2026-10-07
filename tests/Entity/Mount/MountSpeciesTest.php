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

namespace Bedriox\Server\Tests\Entity\Mount;

use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\Capability\Rideable;
use Bedriox\Api\Entity\Capability\Undead;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Vanilla\CamelEntity;
use Bedriox\Server\Entity\Vanilla\DonkeyEntity;
use Bedriox\Server\Entity\Vanilla\HorseEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\MuleEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonHorseEntity;
use Bedriox\Server\Entity\Vanilla\TraderLlamaEntity;
use Bedriox\Server\Entity\Vanilla\ZombieHorseEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class MountSpeciesTest extends TestCase
{
    public function testEveryMountOwnsItsExactDefinitionAndBoundedSeatCapacity(): void
    {
        $position = new Position(0.0, 64.0, 0.0);
        $mounts = [
            new HorseEntity(EntityUuid::random(), 1, 'world', $position),
            new DonkeyEntity(EntityUuid::random(), 2, 'world', $position),
            new MuleEntity(EntityUuid::random(), 3, 'world', $position),
            new CamelEntity(EntityUuid::random(), 4, 'world', $position),
            new LlamaEntity(EntityUuid::random(), 5, 'world', $position),
            new TraderLlamaEntity(EntityUuid::random(), 6, 'world', $position),
            new SkeletonHorseEntity(EntityUuid::random(), 7, 'world', $position),
            new ZombieHorseEntity(EntityUuid::random(), 8, 'world', $position),
        ];
        $types = [
            VanillaEntityType::HORSE,
            VanillaEntityType::DONKEY,
            VanillaEntityType::MULE,
            VanillaEntityType::CAMEL,
            VanillaEntityType::LLAMA,
            VanillaEntityType::TRADER_LLAMA,
            VanillaEntityType::SKELETON_HORSE,
            VanillaEntityType::ZOMBIE_HORSE,
        ];

        foreach ($mounts as $index => $mount) {
            self::assertInstanceOf(Rideable::class, $mount);
            self::assertSame($types[$index], $mount->getType());
            self::assertSame($mount instanceof CamelEntity ? 2 : 1, $mount->getSeatCapacity());
        }
        $expectedPlayerSeats = [
            [0.0, 2.32, -0.2],
            [0.0, 2.145, -0.2],
            [0.0, 2.195, -0.2],
            [0.0, 3.125, 0.5],
            [0.0, 2.39, -0.3],
            [0.0, 2.39, -0.3],
            [0.0, 2.32, -0.2],
            [0.0, 2.32, -0.2],
        ];
        foreach ($mounts as $index => $mount) {
            $seat = $mount->mountedPassengerOffset(MountSeat::DRIVER, 1.8, true);
            self::assertEqualsWithDelta($expectedPlayerSeats[$index][0], $seat->x, 0.000_001);
            self::assertEqualsWithDelta($expectedPlayerSeats[$index][1], $seat->y, 0.000_001);
            self::assertEqualsWithDelta($expectedPlayerSeats[$index][2], $seat->z, 0.000_001);
        }
        $camelRearSeat = $mounts[3]->mountedPassengerOffset(MountSeat::PASSENGER_1, 1.8, true);
        self::assertEqualsWithDelta(0.0, $camelRearSeat->x, 0.000_001);
        self::assertEqualsWithDelta(3.125, $camelRearSeat->y, 0.000_001);
        self::assertEqualsWithDelta(-0.5, $camelRearSeat->z, 0.000_001);
        self::assertInstanceOf(Breedable::class, $mounts[0]);
        self::assertInstanceOf(Undead::class, $mounts[6]);
        self::assertInstanceOf(Undead::class, $mounts[7]);
        self::assertFalse($mounts[6]->definition()->burnsInDaylight);
        self::assertTrue($mounts[7]->definition()->burnsInDaylight);
    }

    public function testLivingAndUndeadMountStateRoundTrips(): void
    {
        $owner = EntityUuid::random();
        $horse = new HorseEntity(EntityUuid::random(), 11, 'world', new Position(0.0, 64.0, 0.0), ownerUniqueId: $owner, saddled: true, temper: 71);
        $restoredHorse = new HorseEntity(EntityUuid::random(), 12, 'world', new Position(0.0, 64.0, 0.0));
        $restoredHorse->restorePersistenceState(null, 1, $horse->persistenceData());
        self::assertSame($owner, $restoredHorse->getOwnerUniqueId());
        self::assertTrue($restoredHorse->isSaddled());
        self::assertSame(71, $restoredHorse->getTemper());

        $skeleton = new SkeletonHorseEntity(EntityUuid::random(), 13, 'world', new Position(0.0, 64.0, 0.0), ownerUniqueId: $owner, saddled: true, temper: 35);
        $restoredSkeleton = new SkeletonHorseEntity(EntityUuid::random(), 14, 'world', new Position(0.0, 64.0, 0.0));
        $restoredSkeleton->restorePersistenceState(null, 1, $skeleton->persistenceData());
        self::assertSame($owner, $restoredSkeleton->getOwnerUniqueId());
        self::assertTrue($restoredSkeleton->isSaddled());
        self::assertSame(35, $restoredSkeleton->getTemper());
    }

    public function testChestedMountAndLlamaEquipmentStateRoundTrips(): void
    {
        $position = new Position(0.0, 64.0, 0.0);
        $donkey = new DonkeyEntity(EntityUuid::random(), 15, 'world', $position, chested: true);
        $restoredDonkey = new DonkeyEntity(EntityUuid::random(), 16, 'world', $position);
        $restoredDonkey->restorePersistenceState(null, 1, $donkey->persistenceData());
        self::assertTrue($restoredDonkey->hasChest());
        self::assertSame(15, $restoredDonkey->getStorageSlotCount());

        $llama = new LlamaEntity(
            EntityUuid::random(),
            17,
            'world',
            $position,
            strength: 5,
            chested: true,
            carpetColor: WoolColor::LIME,
        );
        $restoredLlama = new LlamaEntity(EntityUuid::random(), 18, 'world', $position);
        $restoredLlama->restorePersistenceState(null, 1, $llama->persistenceData());
        self::assertSame(5, $restoredLlama->getStrength());
        self::assertTrue($restoredLlama->hasChest());
        self::assertSame(15, $restoredLlama->getStorageSlotCount());
        self::assertSame(WoolColor::LIME, $restoredLlama->getCarpetColor());
        self::assertNull($restoredLlama->getRetaliationTargetUniqueId());

        $target = EntityUuid::random();
        $restoredLlama->setRetaliationTargetUniqueId($target, 20);
        self::assertSame($target, $restoredLlama->getRetaliationTargetUniqueId());
        self::assertTrue($restoredLlama->canSpit());
        $restoredLlama->markSpat(20);
        self::assertFalse($restoredLlama->canSpit());
        $restoredLlama->advanceLlamaCombatState(20);
        self::assertNull($restoredLlama->getRetaliationTargetUniqueId());
        self::assertTrue($restoredLlama->canSpit());
    }

    public function testChestedMountStorageRoundTripsAndDrainsExactlyOnce(): void
    {
        $position = new Position(0.0, 64.0, 0.0);
        $donkey = new DonkeyEntity(EntityUuid::random(), 19, 'world', $position, chested: true);
        $donkey->storageInventory()->setStack(0, new ItemStack('minecraft:diamond', 3));
        $donkey->storageInventory()->setStack(14, new ItemStack('minecraft:apple', 2));

        $restored = new DonkeyEntity(EntityUuid::random(), 20, 'world', $position);
        $restored->restorePersistenceState(null, 1, $donkey->persistenceData());
        $diamond = $restored->storageInventory()->stackAt(0);
        self::assertNotNull($diamond);
        self::assertSame('minecraft:diamond', $diamond->identifier);
        self::assertSame(3, $diamond->count);
        $apple = $restored->storageInventory()->stackAt(14);
        self::assertNotNull($apple);
        self::assertSame('minecraft:apple', $apple->identifier);

        $firstDrain = $restored->drainStorageItems();
        self::assertSame(['minecraft:diamond', 'minecraft:apple'], array_map(
            static fn(ItemStack $stack): string => $stack->identifier,
            $firstDrain,
        ));
        self::assertSame([], $restored->drainStorageItems());
        self::assertSame(array_fill(0, 15, null), $restored->storageInventory()->contents());
    }

    public function testHorseFamilyPersistenceRejectsPayloadAboveTheSharedBound(): void
    {
        $donkey = new DonkeyEntity(
            EntityUuid::random(),
            21,
            'world',
            new Position(0.0, 64.0, 0.0),
        );

        $this->expectException(InvalidArgumentException::class);
        $donkey->restorePersistenceState(
            null,
            1,
            str_repeat('x', EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES + 1),
        );
    }
}
