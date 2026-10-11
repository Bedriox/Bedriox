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

use Bedriox\Api\Event\Player\PlayerEntityPickedEvent;
use Bedriox\Api\Event\Player\PlayerEntityPickEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\EntityUuid;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class EntityPickEventTest extends TestCase
{
    public function testCancellableIntentCanReplaceThePickedItemAndRestoreItsState(): void
    {
        $player = self::player();
        $entity = new CowEntity(EntityUuid::random(), 42, 'world', new Position(0.0, 64.0, 0.0));
        $spawnEgg = new ItemStack('minecraft:cow_spawn_egg', 1);
        $replacement = new ItemStack('minecraft:stick', 1);
        $event = new PlayerEntityPickEvent($player, $entity, $spawnEgg, 3, true);
        $state = $event->captureState();

        $event->cancel();
        $event->setItem($replacement);
        self::assertTrue($event->isCancelled());
        self::assertSame($replacement, $event->item());

        $event->restoreState($state);
        self::assertFalse($event->isCancelled());
        self::assertSame($spawnEgg, $event->item());
        self::assertSame($entity, $event->entity);
        self::assertSame(3, $event->hotbarSlot);
        self::assertTrue($event->userDataRequested);
    }

    public function testCommittedNotificationIsImmutablePostEvent(): void
    {
        $event = new PlayerEntityPickedEvent(
            self::player(),
            new CowEntity(EntityUuid::random(), 43, 'world', new Position(0.0, 64.0, 0.0)),
            new ItemStack('minecraft:cow_spawn_egg', 1),
            8,
            false,
        );

        self::assertInstanceOf(PostEvent::class, $event);
        self::assertSame(8, $event->hotbarSlot);
        self::assertFalse($event->userDataRequested);
    }

    public function testEntityPickEventsRejectSlotsOutsideTheHotbar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlayerEntityPickEvent(
            self::player(),
            new CowEntity(EntityUuid::random(), 44, 'world', new Position(0.0, 64.0, 0.0)),
            new ItemStack('minecraft:cow_spawn_egg', 1),
            9,
            false,
        );
    }

    private static function player(): Player
    {
        return new Player(
            'One',
            '00000000-0000-4000-8000-000000000001',
            new ApiPosition(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
