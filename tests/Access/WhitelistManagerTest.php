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

use Bedriox\Api\Event\Server\WhitelistChangedEvent;
use Bedriox\Api\Event\Server\WhitelistChangeType;
use Bedriox\Server\Access\WhitelistManager;
use PHPUnit\Framework\TestCase;

final class WhitelistManagerTest extends TestCase
{
    public function testPersistsEntriesAndUpgradesAuthenticatedIdentity(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-whitelist-' . bin2hex(random_bytes(8)) . '.json';
        $enabled = [];
        $events = [];
        try {
            $manager = new WhitelistManager(
                $path,
                false,
                static function (bool $value) use (&$enabled): void {
                    $enabled[] = $value;
                },
                static function (WhitelistChangedEvent $event) use (&$events): void {
                    $events[] = $event->type;
                },
            );
            self::assertTrue($manager->add('Alice'));
            self::assertTrue($manager->contains('alice'));
            self::assertTrue($manager->admit('ALICE', '12345678-1234-1234-1234-123456789abc'));
            self::assertSame('12345678-1234-1234-1234-123456789abc', $manager->entries()[0]->uuid);
            self::assertTrue($manager->setEnabled(true));
            self::assertSame([true], $enabled);
            self::assertContains(WhitelistChangeType::ENTRY_ADDED, $events);
            self::assertContains(WhitelistChangeType::ENABLED, $events);

            $reloaded = new WhitelistManager($path, true, static function (bool $value): void {});
            self::assertTrue($reloaded->contains('Alice', '12345678-1234-1234-1234-123456789abc'));
        } finally {
            @unlink($path);
        }
    }

    public function testFailedReloadRetainsActiveEntries(): void
    {
        $path = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-whitelist-' . bin2hex(random_bytes(8)) . '.json';
        try {
            $manager = new WhitelistManager($path, true, static function (bool $value): void {});
            $manager->add('Alice');
            file_put_contents($path, '{broken');
            try {
                $manager->reload();
                self::fail('Expected invalid JSON to fail.');
            } catch (\RuntimeException) {
            }
            self::assertTrue($manager->contains('Alice'));
        } finally {
            @unlink($path);
        }
    }
}
