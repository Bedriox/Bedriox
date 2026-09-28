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

namespace Bedriox\Server\Tests\Plugin;

use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\Vanilla\ZombieEntity;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class BufferedMobControllerTest extends TestCase
{
    public function testEquipmentMutationIsCommittedOnlyWithItsPluginTransaction(): void
    {
        $actions = new PluginActionBuffer();
        $zombie = self::zombie();
        $zombie->attachController($actions);
        $equipment = $zombie->getController()->equipment();
        $helmet = new ItemStack('minecraft:iron_helmet', 1);

        $actions->begin();
        $equipment->setItem(EquipmentSlot::HEAD, $helmet);
        self::assertNull($zombie->equipmentState()->getItem(EquipmentSlot::HEAD));
        $actions->discard();
        self::assertNull($zombie->equipmentState()->getItem(EquipmentSlot::HEAD));

        $actions->begin();
        $equipment->setItem(EquipmentSlot::HEAD, $helmet);
        $equipment->setDropChance(EquipmentSlot::HEAD, 0.5);
        self::assertNull($zombie->equipmentState()->getItem(EquipmentSlot::HEAD));
        $actions->commit();

        self::assertEquals($helmet, $zombie->equipmentState()->getItem(EquipmentSlot::HEAD));
        self::assertSame(0.5, $zombie->equipmentState()->getDropChance(EquipmentSlot::HEAD));
    }

    public function testControllerIntentBudgetRenewsForEachPluginTransaction(): void
    {
        $actions = new PluginActionBuffer();
        $zombie = self::zombie();
        $zombie->attachController($actions);
        $controller = $zombie->getController();

        for ($transaction = 0; $transaction < 2; ++$transaction) {
            $actions->begin();
            for ($intent = 0; $intent < 16; ++$intent) {
                $controller->setNameTag("guard-{$transaction}-{$intent}");
            }
            $actions->commit();
        }

        self::assertSame('guard-1-15', $zombie->nameTag());
    }

    public function testSetHealthUsesStateProducedByEarlierBufferedIntents(): void
    {
        $actions = new PluginActionBuffer();
        $zombie = self::zombie();
        $zombie->attachController($actions);
        $controller = $zombie->getController();

        $actions->begin();
        $controller->damage(5.0);
        $controller->setHealth(10.0);
        $actions->commit();

        self::assertSame(10.0, $zombie->getHealth());
    }

    private static function zombie(): ZombieEntity
    {
        return new ZombieEntity(
            '00000000-0000-4000-8000-000000000001',
            1,
            'world',
            new Position(0.0, 64.0, 0.0),
        );
    }
}
