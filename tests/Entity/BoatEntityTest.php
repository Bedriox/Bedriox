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

use Bedriox\Api\Entity\Value\BoatVariant;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Protocol\Packet\BoatActorMetadata;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Vehicle\BoatEntity;
use Bedriox\Server\Runtime\BedrockLivingActorProjector;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BoatEntity::class)]
#[CoversClass(BoatVariant::class)]
final class BoatEntityTest extends TestCase
{
    public function testEveryCurrentBoatItemRoundTripsToItsVariant(): void
    {
        foreach (BoatVariant::cases() as $variant) {
            self::assertSame($variant, BoatVariant::fromItem($variant->itemIdentifier()));
            self::assertSame($variant, BoatVariant::fromItem($variant->itemIdentifier(true)));
        }
        self::assertNull(BoatVariant::fromItem('minecraft:stick'));
    }

    public function testLegacyBoatItemsResolveTheirVariantFromAuxValue(): void
    {
        foreach (BoatVariant::cases() as $variant) {
            if ($variant === BoatVariant::POPLAR) {
                self::assertNull(BoatVariant::fromItem('minecraft:boat', $variant->value));
                self::assertFalse($variant->matchesItem('minecraft:boat', $variant->value));
            } else {
                self::assertSame($variant, BoatVariant::fromItem('minecraft:boat', $variant->value));
                self::assertTrue($variant->matchesItem('minecraft:boat', $variant->value));
            }
            self::assertSame($variant, BoatVariant::fromItem('minecraft:chest_boat', $variant->value));
            self::assertTrue($variant->matchesItem('minecraft:chest_boat', $variant->value));
        }
        self::assertNull(BoatVariant::fromItem('minecraft:boat', 99));
    }

    public function testNormalAndChestBoatExposeVanillaSeatCounts(): void
    {
        $boat = self::boat();
        $chestBoat = self::boat(true);

        self::assertSame(2, $boat->getSeatCapacity());
        self::assertSame(1, $chestBoat->getSeatCapacity());
        self::assertGreaterThan(1.0, $boat->mountedPassengerOffsetY(MountSeat::DRIVER, 1.8, true));
        self::assertNull($boat->chestInventory());
        self::assertCount(27, $chestBoat->chestInventory()?->contents() ?? []);
    }

    public function testBoatControlIsStrongOnWaterAndHeavilyLimitedOnLand(): void
    {
        $boat = self::boat();

        self::assertSame(0.28, $boat->controlledSpeed(true));
        self::assertSame(0.04, $boat->controlledSpeed(false));
    }

    public function testBoatWaterlineCorrectionPlacesItExactlyOnTheSurface(): void
    {
        $submerged = self::boat();
        self::assertSame(
            BoatEntity::floatingPositionY(64.0) - $submerged->internalPosition()->y,
            $submerged->waterlineCorrection(64.0),
        );

        $floating = new BoatEntity(
            '00000000-0000-4000-8000-000000000003',
            3,
            'world',
            new Position(0.5, BoatEntity::floatingPositionY(64.0), 0.5),
            motion: new EntityMotion(),
        );
        self::assertEqualsWithDelta(0.0, $floating->waterlineCorrection(64.0), 0.000_001);
        self::assertEqualsWithDelta(
            64.0,
            $floating->internalPosition()->y + $floating->bedrockPositionOffsetY() + 0.01,
            0.000_001,
        );
    }

    public function testBoatUsesBoundedStructuralDamageAndExposesItsHitAnimation(): void
    {
        $boat = self::boat();

        self::assertSame(10.0, $boat->structuralDamage(1.0, false));
        self::assertSame(40.0, $boat->structuralDamage(1.0, true));
        $boat->showDamageAnimation();
        self::assertSame(9, $boat->hurtTicks());
        self::assertSame(-1, $boat->hurtDirection());
        $boat->advanceDamageAnimation();
        self::assertSame(8, $boat->hurtTicks());
    }

    public function testPaddleAndVariantStateProjectsToTypedBoatMetadata(): void
    {
        $boat = self::boat();
        $boat->setVariant(BoatVariant::CHERRY);
        $boat->applyPaddleInput(true, false);
        $boat->advancePaddles();
        $boat->showDamageAnimation();

        $expected = BoatActorMetadata::baseline(
            BoatVariant::CHERRY->value,
            0.0,
            9,
            -1,
            rowTimeLeft: 0.04,
            rowTimeRight: 0.0,
        );
        self::assertEquals($expected, (new BedrockLivingActorProjector())->metadata($boat));
    }

    public function testChestBoatInventoryAndVariantSurviveIntrinsicPersistence(): void
    {
        $boat = self::boat(true);
        $boat->setVariant(BoatVariant::MANGROVE);
        $boat->chestInventory()?->setStack(4, new ItemStack('minecraft:diamond', 3));

        $restored = self::boat(true);
        $restored->restorePersistenceState(
            $boat->persistenceVariant(),
            $boat->persistenceSchemaVersion(),
            $boat->persistenceData(),
        );

        $inventory = $restored->chestInventory();
        self::assertNotNull($inventory);
        $restoredStack = $inventory->stackAt(4);
        self::assertNotNull($restoredStack);
        self::assertSame(BoatVariant::MANGROVE, $restored->getVariant());
        self::assertSame('minecraft:diamond', $restoredStack->identifier);
        self::assertSame(3, $restoredStack->count);
    }

    private static function boat(bool $chest = false): BoatEntity
    {
        return new BoatEntity(
            '00000000-0000-4000-8000-000000000001',
            $chest ? 2 : 1,
            'world',
            new Position(0.5, 63.1, 0.5),
            chestBoat: $chest,
        );
    }
}
