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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Server\Runtime\BossBar\ActorBackedBossBarDelivery;
use Bedriox\Server\Simulation\Event\EnderDragonBossBarAction;
use Bedriox\Server\Simulation\Event\EnderDragonBossBarChanged;
use PHPUnit\Framework\TestCase;

final class ActorBackedBossBarDeliveryTest extends TestCase
{
    public function testCreateWaitsForBackingActorAndRetainsLatestSnapshot(): void
    {
        $delivery = new ActorBackedBossBarDelivery();
        $create = new EnderDragonBossBarChanged(
            42,
            EnderDragonBossBarAction::CREATE,
            1.0,
            ['viewer'],
        );

        self::assertNull($delivery->admit('world@end', $create, []));
        self::assertNull($delivery->admit('world@end', new EnderDragonBossBarChanged(
            42,
            EnderDragonBossBarAction::UPDATE_PROGRESS,
            0.75,
            ['viewer'],
        ), ['viewer' => true]));

        $ready = $delivery->actorAppeared('world@end', 42, ['viewer']);
        self::assertCount(1, $ready);
        self::assertSame(EnderDragonBossBarAction::CREATE, $ready[0]->action);
        self::assertSame(0.75, $ready[0]->progress);
        self::assertSame(['viewer'], $ready[0]->recipientSessionIds);
        self::assertSame([], $delivery->actorAppeared('world@end', 42, ['viewer']));
    }

    public function testVisibleViewerIsAdmittedAndPendingViewerCanBeRemoved(): void
    {
        $delivery = new ActorBackedBossBarDelivery();
        $event = new EnderDragonBossBarChanged(
            7,
            EnderDragonBossBarAction::CREATE,
            1.0,
            ['visible', 'pending'],
        );

        $admitted = $delivery->admit('world@end', $event, ['visible' => true]);
        self::assertNotNull($admitted);
        self::assertSame(['visible'], $admitted->recipientSessionIds);

        $delivery->removeViewer('pending');
        self::assertSame([], $delivery->actorAppeared('world@end', 7, ['pending']));
    }
}
