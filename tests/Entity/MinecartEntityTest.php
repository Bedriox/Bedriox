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

use Bedriox\Api\Entity\Capability\ContainerVehicle;
use Bedriox\Api\Entity\Controller\MinecartController;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\ChestMinecart;
use Bedriox\Api\Entity\Vanilla\Minecart;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Vehicle\Minecart\AbstractMinecartEntity;
use Bedriox\Server\Entity\Vehicle\Minecart\ChestMinecartEntity;
use Bedriox\Server\Entity\Vehicle\Minecart\RideableMinecartEntity;
use Bedriox\Server\Entity\Vehicle\VehicleInventoryOwner;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractMinecartEntity::class)]
#[CoversClass(RideableMinecartEntity::class)]
#[CoversClass(ChestMinecartEntity::class)]
final class MinecartEntityTest extends TestCase
{
    public function testRideableMinecartExposesOneStandardSeatAndBoundedTrackControl(): void
    {
        $minecart = self::rideable();

        self::assertInstanceOf(Minecart::class, $minecart);
        self::assertSame(VanillaEntityType::MINECART, $minecart->getType());
        self::assertSame(1, $minecart->getSeatCapacity());
        self::assertSame(0.98, $minecart->getCollisionWidth());
        self::assertSame(0.7, $minecart->getCollisionHeight());
        self::assertEquals(
            [0.0, 1.020_01, 0.0],
            (static function ($offset): array {
                return [$offset->x, $offset->y, $offset->z];
            })($minecart->mountedPassengerOffset(MountSeat::DRIVER, 1.8, true)),
        );
        self::assertFalse($minecart->mountedViewConstraint()->locked);

        $controller = $minecart->getController();
        self::assertInstanceOf(MinecartController::class, $controller);
        $controller->setTrackSpeed(0.25);
        self::assertSame(0.25, $minecart->getTrackSpeed());
        $controller->reverse();
        self::assertSame(-0.25, $minecart->getTrackSpeed());
        $controller->stop();
        self::assertSame(0.0, $minecart->getTrackSpeed());
    }

    public function testMinecartRejectsAnUnboundedPluginTrackSpeed(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::rideable()->getController()->setTrackSpeed(AbstractMinecartEntity::MAXIMUM_TRACK_SPEED + 0.01);
    }

    public function testTrackSpeedAndMotionSurviveIntrinsicPersistence(): void
    {
        $minecart = self::rideable();
        $minecart->setTrackSpeed(-0.35);

        $restored = self::rideable();
        $restored->restorePersistenceState(
            $minecart->persistenceVariant(),
            $minecart->persistenceSchemaVersion(),
            $minecart->persistenceData(),
        );

        self::assertSame(-0.35, $restored->getTrackSpeed());
    }

    public function testChestMinecartOwnsPersistentTwentySevenSlotStorage(): void
    {
        $minecart = self::chest();
        self::assertInstanceOf(ChestMinecart::class, $minecart);
        self::assertInstanceOf(ContainerVehicle::class, $minecart);
        self::assertInstanceOf(VehicleInventoryOwner::class, $minecart);
        self::assertSame(VanillaEntityType::CHEST_MINECART, $minecart->getType());
        self::assertSame(ContainerType::CHEST_MINECART, $minecart->getContainerType());
        self::assertSame(ContainerType::CHEST_MINECART, $minecart->vehicleContainerType());
        self::assertCount(27, $minecart->vehicleInventory()->contents());
        $revision = $minecart->revision();
        $minecart->markVehicleInventoryChanged();
        self::assertGreaterThan($revision, $minecart->revision());

        $minecart->setTrackSpeed(0.2);
        $minecart->vehicleInventory()->setStack(
            8,
            new ItemStack('minecraft:diamond', 3, 0, ItemNbt::empty()->withString('owner', 'Bedriox')),
        );
        $restored = self::chest();
        $restored->restorePersistenceState(
            $minecart->persistenceVariant(),
            $minecart->persistenceSchemaVersion(),
            $minecart->persistenceData(),
        );

        $stack = $restored->vehicleInventory()->stackAt(8);
        self::assertNotNull($stack);
        self::assertSame('minecraft:diamond', $stack->identifier);
        self::assertSame(3, $stack->count);
        self::assertSame('Bedriox', $stack->nbt?->string('owner'));
        self::assertSame(0.2, $restored->getTrackSpeed());
    }

    public function testChestMinecartRejectsMalformedPersistentStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::chest()->restorePersistenceState(null, 1, '{"trackSpeed":0,"state":{"items":[]}}');
    }

    private static function rideable(): RideableMinecartEntity
    {
        return new RideableMinecartEntity(
            '00000000-0000-4000-8000-000000000010',
            10,
            'world',
            new Position(0.5, 64.0, 0.5),
            new EntityMotion(0.1, 0.0, 0.0),
        );
    }

    private static function chest(): ChestMinecartEntity
    {
        return new ChestMinecartEntity(
            '00000000-0000-4000-8000-000000000011',
            11,
            'world',
            new Position(0.5, 64.0, 0.5),
        );
    }
}
