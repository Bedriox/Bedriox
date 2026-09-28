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

use Bedriox\Api\Event\Player\PlayerEquipmentChangedEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangeEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangeEvent;
use Bedriox\Api\Event\Player\PlayerItemBreakEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumedEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumeEvent;
use Bedriox\Api\Event\Player\PlayerItemDamageEvent;
use Bedriox\Api\Event\Player\PlayerItemUseCancelledEvent;
use Bedriox\Api\Event\Player\PlayerItemUsedEvent;
use Bedriox\Api\Event\Player\PlayerRegainedHealthEvent;
use Bedriox\Api\Event\Player\PlayerRegainHealthEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\ArmorDefinition;
use Bedriox\Api\Inventory\ConsumableDefinition;
use Bedriox\Api\Inventory\ConsumptionResult;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemBehaviorDefinition;
use Bedriox\Api\Inventory\ItemDamageCause;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Inventory\ItemUseCancellationReason;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Player\FoodLevelChangeCause;
use Bedriox\Api\Player\HealthRegainCause;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemInteractionApiTest extends TestCase
{
    public function testDefinesBoundedConsumableArmorAndOffhandBehavior(): void
    {
        $residue = new ItemStack('minecraft:bowl', 1);
        $consumable = new ConsumableDefinition(6, 7.2, residue: [$residue]);
        $food = new ItemBehaviorDefinition(ItemUseKind::CONSUME, 32, $consumable);
        $armor = new ItemBehaviorDefinition(
            ItemUseKind::EQUIP,
            armor: new ArmorDefinition(EquipmentSlot::CHEST, 8, 592, 0.1),
        );
        $offhand = new ItemBehaviorDefinition(allowedInOffhand: true);

        self::assertSame(32, $food->useDurationTicks);
        self::assertSame([$residue], $food->consumable?->result()->residue);
        $armorDefinition = $armor->armor;
        self::assertInstanceOf(ArmorDefinition::class, $armorDefinition);
        self::assertSame(EquipmentSlot::CHEST, $armorDefinition->slot);
        self::assertTrue($armorDefinition->slot->isArmor());
        self::assertTrue($offhand->allowedInOffhand);
        self::assertFalse(EquipmentSlot::OFF_HAND->isArmor());
    }

    #[DataProvider('invalidBehaviorDefinitions')]
    public function testRejectsInvalidBehaviorDefinitions(callable $create): void
    {
        $this->expectException(InvalidArgumentException::class);
        $create();
    }

    /** @return iterable<string, array{callable(): object}> */
    public static function invalidBehaviorDefinitions(): iterable
    {
        yield 'empty capability set' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition()];
        yield 'duration without use' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(useDurationTicks: 1, allowedInOffhand: true)];
        yield 'duration on instant use' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(ItemUseKind::INSTANT, 1)];
        yield 'consume without definition' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(ItemUseKind::CONSUME, 32)];
        yield 'consume without duration' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(
            ItemUseKind::CONSUME,
            consumable: new ConsumableDefinition(1, 1.0),
        )];
        yield 'equip without armor' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(ItemUseKind::EQUIP)];
        yield 'nutrition on instant behavior' => [static fn(): ItemBehaviorDefinition => new ItemBehaviorDefinition(
            ItemUseKind::INSTANT,
            consumable: new ConsumableDefinition(1, 1.0),
        )];
        yield 'non-armor equipment slot' => [static fn(): ArmorDefinition => new ArmorDefinition(
            EquipmentSlot::OFF_HAND,
            1,
            10,
        )];
        yield 'excessive knockback resistance' => [static fn(): ArmorDefinition => new ArmorDefinition(
            EquipmentSlot::HEAD,
            1,
            10,
            1.1,
        )];
    }

    public function testConsumptionAndFoodPreEventsRestoreControlledState(): void
    {
        $player = self::player();
        $stack = new ItemStack('minecraft:apple', 1);
        $before = new Nutrition(12, 2.0, 0.5);
        $originalResult = new ConsumptionResult(4, 2.4);
        $consume = new PlayerItemConsumeEvent($player, $stack, $before, $originalResult);
        $consumeState = $consume->captureState();
        $consume->setResult(new ConsumptionResult(1, 0.5, [new ItemStack('minecraft:bowl', 1)]));
        $consume->cancel();
        $consume->restoreState($consumeState);

        self::assertFalse($consume->isCancelled());
        self::assertSame($originalResult, $consume->result());

        $proposed = new Nutrition(16, 4.4, 0.5);
        $food = new PlayerFoodLevelChangeEvent($player, $before, $proposed, FoodLevelChangeCause::CONSUMPTION);
        $foodState = $food->captureState();
        $food->setNutrition(new Nutrition(20, 20.0, 0.0));
        $food->cancel();
        $food->restoreState($foodState);

        self::assertFalse($food->isCancelled());
        self::assertSame($proposed, $food->nutrition());
    }

    public function testEquipmentAndDamagePreEventsRestoreControlledState(): void
    {
        $player = self::player();
        $helmet = new ItemStack('minecraft:iron_helmet', 1);
        $replacement = new ItemStack('minecraft:diamond_helmet', 1);
        $equipment = new PlayerEquipmentChangeEvent($player, EquipmentSlot::HEAD, $helmet, $replacement);
        $equipmentState = $equipment->captureState();
        $equipment->setItem(null);
        $equipment->cancel();
        $equipment->restoreState($equipmentState);

        self::assertFalse($equipment->isCancelled());
        self::assertSame($replacement, $equipment->item());

        $damage = new PlayerItemDamageEvent(
            $player,
            $helmet,
            ItemDamageCause::DAMAGE_ABSORPTION,
            EquipmentSlot::HEAD,
            1,
        );
        $damageState = $damage->captureState();
        $damage->setDamage(3);
        $damage->cancel();
        $damage->restoreState($damageState);

        self::assertFalse($damage->isCancelled());
        self::assertSame(1, $damage->damage());
    }

    public function testNaturalHealthRegainEventIsAdjustableAndRestorable(): void
    {
        $event = new PlayerRegainHealthEvent(self::player(), HealthRegainCause::SATURATION, 1.0);
        $state = $event->captureState();
        $event->setAmount(0.5);
        $event->cancel();
        $event->restoreState($state);

        self::assertFalse($event->isCancelled());
        self::assertSame(1.0, $event->amount());
    }

    public function testCompletionEventsAreObservationalPostEvents(): void
    {
        $player = self::player();
        $item = new ItemStack('minecraft:apple', 1);
        $nutrition = new Nutrition(20, 4.4, 0.0);
        $result = new ConsumptionResult(4, 2.4);
        $events = [
            new PlayerItemUsedEvent($player, $item, ItemUseKind::CONSUME, EquipmentSlot::MAIN_HAND, 32),
            new PlayerItemUseCancelledEvent(
                $player,
                $item,
                ItemUseKind::CONSUME,
                EquipmentSlot::MAIN_HAND,
                ItemUseCancellationReason::RELEASED_EARLY,
                12,
            ),
            new PlayerItemConsumedEvent($player, $item, new Nutrition(16, 2.0, 0.0), $nutrition, $result),
            new PlayerEquipmentChangedEvent($player, EquipmentSlot::HEAD, null, $item),
            new PlayerItemBreakEvent($player, $item, ItemDamageCause::ITEM_USE, EquipmentSlot::MAIN_HAND),
            new PlayerRegainedHealthEvent($player, HealthRegainCause::SATURATION, 1.0),
        ];

        foreach ($events as $event) {
            self::assertInstanceOf(PostEvent::class, $event);
        }
    }

    #[DataProvider('invalidNutrition')]
    public function testRejectsInvalidNutrition(int $food, float $saturation, float $exhaustion): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Nutrition($food, $saturation, $exhaustion);
    }

    /** @return iterable<string, array{int, float, float}> */
    public static function invalidNutrition(): iterable
    {
        yield 'negative food' => [-1, 0.0, 0.0];
        yield 'food above maximum' => [21, 0.0, 0.0];
        yield 'negative saturation' => [20, -0.1, 0.0];
        yield 'exhaustion at threshold' => [20, 0.0, 4.0];
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
