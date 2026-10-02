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

namespace Bedriox\Server\Tests\Access;

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\Server\BanListChangedEvent;
use Bedriox\Api\Event\Server\BanListChangeEvent;
use Bedriox\Server\Access\BanManager;
use PHPUnit\Framework\TestCase;

final class BanManagerTest extends TestCase
{
    public function testPersistsPlayerAndAddressBansAtomically(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-bans-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'bans.json';
        $events = [];
        try {
            $manager = new BanManager($path, static function (Event $event) use (&$events): Event {
                $events[] = $event;
                return $event;
            });
            self::assertTrue($manager->banPlayer('Example', 'Testing', '00000000-0000-0000-0000-000000000001'));
            self::assertTrue($manager->banAddress('127.0.0.1', 'Testing address'));
            self::assertFalse($manager->banPlayer('example', 'Duplicate'));

            $reloaded = new BanManager($path);
            self::assertSame('Testing', $reloaded->playerBan('EXAMPLE')?->reason);
            self::assertSame('Testing address', $reloaded->addressBan('127.0.0.1')?->reason);
            self::assertTrue($reloaded->pardonPlayer('example'));
            self::assertTrue($reloaded->pardonAddress('127.0.0.1'));
            self::assertNull((new BanManager($path))->playerBan('example'));
            self::assertCount(4, $events);
            self::assertInstanceOf(BanListChangeEvent::class, $events[0]);
            self::assertInstanceOf(BanListChangedEvent::class, $events[1]);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }

    public function testCancelledChangeIsNotPersisted(): void
    {
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-bans-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $path = $directory . DIRECTORY_SEPARATOR . 'bans.json';
        try {
            $manager = new BanManager($path, static function (Event $event): Event {
                if ($event instanceof BanListChangeEvent) {
                    $event->cancel();
                }
                return $event;
            });
            self::assertFalse($manager->banPlayer('Blocked'));
            self::assertNull($manager->playerBan('Blocked'));
            self::assertFileDoesNotExist($path);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
    }
}
