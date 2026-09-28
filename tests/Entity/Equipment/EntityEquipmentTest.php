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

namespace Bedriox\Server\Tests\Entity\Equipment;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Entity\Equipment\EntityEquipment;
use Bedriox\Server\Entity\Equipment\EntityEquipmentTransition;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EntityEquipmentTest extends TestCase
{
    public function testSixSlotStateIsCopySafeAndReportsBoundedChanges(): void
    {
        $changes = [];
        $equipment = new EntityEquipment(static function (EquipmentSlot $slot) use (&$changes): void {
            $changes[] = $slot;
        });
        $helmet = new ItemStack('minecraft:iron_helmet', 1, 7);

        $equipment->setItem(EquipmentSlot::HEAD, $helmet);
        $equipment->setDropChance(EquipmentSlot::HEAD, 0.25);

        self::assertSame($helmet, $equipment->getItem(EquipmentSlot::HEAD));
        self::assertSame(['head' => $helmet], $equipment->getContents());
        self::assertSame(0.25, $equipment->getDropChance(EquipmentSlot::HEAD));
        self::assertSame(2, $equipment->revision());
        self::assertSame([EquipmentSlot::HEAD, EquipmentSlot::HEAD], $changes);

        $copy = $equipment->getContents();
        unset($copy['head']);
        self::assertSame($helmet, $equipment->getItem(EquipmentSlot::HEAD));

        $equipment->clear(EquipmentSlot::HEAD);
        self::assertNull($equipment->getItem(EquipmentSlot::HEAD));
        self::assertSame(3, $equipment->revision());
    }

    public function testArmorSlotCompatibilityAndDropChanceAreValidated(): void
    {
        $equipment = new EntityEquipment();
        $equipment->setItem(EquipmentSlot::HEAD, new ItemStack('minecraft:iron_helmet', 1));

        try {
            $equipment->setItem(EquipmentSlot::FEET, new ItemStack('minecraft:iron_helmet', 1));
            self::fail('An incompatible armor item was accepted.');
        } catch (InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        foreach ([NAN, -0.01, 1.01] as $chance) {
            try {
                $equipment->setDropChance(EquipmentSlot::HEAD, $chance);
                self::fail('An invalid equipment drop chance was accepted.');
            } catch (InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testActiveCatalogEnforcesCanonicalMaximumStackSize(): void
    {
        $data = BedrockDataSet::bundled();
        $equipment = new EntityEquipment();
        $equipment->configureCatalog(ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        ));

        $this->expectException(InvalidArgumentException::class);
        $equipment->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:iron_sword', 2));
    }

    public function testPersistenceRestoreDoesNotPublishALiveMutation(): void
    {
        $changes = [];
        $equipment = new EntityEquipment(static function (EquipmentSlot $slot) use (&$changes): void {
            $changes[] = $slot;
        });
        $item = new ItemStack('minecraft:iron_sword', 1, 12, auxValue: 3);

        $equipment->restoreItem(EquipmentSlot::MAIN_HAND, $item, 0.085);

        self::assertSame($item, $equipment->getItem(EquipmentSlot::MAIN_HAND));
        self::assertSame(0.085, $equipment->getDropChance(EquipmentSlot::MAIN_HAND));
        self::assertSame(0, $equipment->revision());
        self::assertSame([], $changes);
    }

    public function testPreHookCanAdjustOneAtomicTransitionAndPostHookSeesCommittedState(): void
    {
        $commits = [];
        $equipment = new EntityEquipment();
        $equipment->configureTransitionHooks(
            static function (
                EquipmentSlot $slot,
                ?ItemStack $previous,
                float $previousChance,
                ?ItemStack $item,
                float $chance,
            ): EntityEquipmentTransition {
                self::assertSame(EquipmentSlot::MAIN_HAND, $slot);
                self::assertNull($previous);
                self::assertSame(0.0, $previousChance);
                self::assertSame('minecraft:iron_sword', $item?->identifier);
                self::assertSame(0.0, $chance);

                return new EntityEquipmentTransition(new ItemStack('minecraft:stone_sword', 1), 0.5);
            },
            static function (
                EquipmentSlot $slot,
                ?ItemStack $previous,
                float $previousChance,
                ?ItemStack $item,
                float $chance,
            ) use (&$commits): void {
                $commits[] = [$slot, $previous, $previousChance, $item, $chance];
            },
        );

        $equipment->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:iron_sword', 1));

        self::assertSame('minecraft:stone_sword', $equipment->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame(0.5, $equipment->getDropChance(EquipmentSlot::MAIN_HAND));
        self::assertCount(1, $commits);
        self::assertSame('minecraft:stone_sword', $commits[0][3]?->identifier);
    }

    public function testCancelledTransitionAndHydrationBypassDoNotPublishPostHooks(): void
    {
        $posts = 0;
        $equipment = new EntityEquipment();
        $equipment->configureTransitionHooks(
            static fn(
                EquipmentSlot $_slot,
                ?ItemStack $_previous,
                float $_previousChance,
                ?ItemStack $_item,
                float $_chance,
            ): null => null,
            static function (
                EquipmentSlot $_slot,
                ?ItemStack $_previous,
                float $_previousChance,
                ?ItemStack $_item,
                float $_chance,
            ) use (&$posts): void {
                ++$posts;
            },
        );

        $equipment->setItem(EquipmentSlot::MAIN_HAND, new ItemStack('minecraft:iron_sword', 1));
        self::assertNull($equipment->getItem(EquipmentSlot::MAIN_HAND));
        self::assertSame(0, $equipment->revision());

        $equipment->restoreItem(
            EquipmentSlot::MAIN_HAND,
            new ItemStack('minecraft:stone_sword', 1),
            0.25,
        );
        self::assertSame('minecraft:stone_sword', $equipment->getItem(EquipmentSlot::MAIN_HAND)?->identifier);
        self::assertSame(0, $posts);
    }
}
