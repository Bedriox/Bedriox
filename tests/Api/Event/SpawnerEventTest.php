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

namespace Bedriox\Server\Tests\Api\Event;

use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Event\Spawner\SpawnerActivateEvent;
use Bedriox\Api\Event\Spawner\SpawnerCompleteEvent;
use Bedriox\Api\Event\Spawner\SpawnerSpawnEvent;
use Bedriox\Api\Event\Spawner\TrialSpawnerStateChangeEvent;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\DimensionType;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\Spawner\TrialSpawnerPhase;
use Bedriox\Api\World\World;
use PHPUnit\Framework\TestCase;

final class SpawnerEventTest extends TestCase
{
    public function testSpawnerEventsIdentifyTheirExactWorldAndDimension(): void
    {
        $world = new World('survival', 4, dimensionType: DimensionType::NETHER);
        $position = new BlockPosition(12, 70, -8);

        $activation = new SpawnerActivateEvent($world, $position, false);
        $spawn = new SpawnerSpawnEvent(
            $world,
            $position,
            'minecraft:blaze',
            new Position(12.5, 71.0, -7.5, world: $world),
            true,
            true,
        );
        $transition = new TrialSpawnerStateChangeEvent(
            $world,
            $position,
            TrialSpawnerPhase::ACTIVE,
            TrialSpawnerPhase::EJECTING_REWARDS,
            true,
        );
        $completion = new SpawnerCompleteEvent($world, $position, ['player-one'], true);

        foreach ([$activation, $spawn, $transition, $completion] as $event) {
            self::assertSame($world, $event->world);
            self::assertSame(DimensionType::NETHER, $event->world->getDimensionType());
        }
        self::assertInstanceOf(PostEvent::class, $completion);
    }

    public function testSpawnerPreEventsRemainCancellable(): void
    {
        $world = new World('survival', 1);
        $position = new BlockPosition(0, 64, 0);
        $events = [
            new SpawnerActivateEvent($world, $position, false),
            new SpawnerSpawnEvent($world, $position, 'minecraft:zombie', new Position(0.5, 65.0, 0.5), false),
            new TrialSpawnerStateChangeEvent(
                $world,
                $position,
                TrialSpawnerPhase::INACTIVE,
                TrialSpawnerPhase::WAITING_FOR_PLAYERS,
                false,
            ),
        ];

        foreach ($events as $event) {
            $event->cancel();
            self::assertTrue($event->isCancelled());
        }
    }
}
