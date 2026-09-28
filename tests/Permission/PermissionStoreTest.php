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

namespace Bedriox\Server\Tests\Permission;

use Bedriox\Server\Permission\PermissionStore;
use PHPUnit\Framework\TestCase;

final class PermissionStoreTest extends TestCase
{
    private const string UUID = '00112233-4455-6677-8899-aabbccddeeff';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-permissions-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'permissions.json';
        if (is_file($path)) {
            unlink($path);
        }
        if (is_dir($this->directory)) {
            rmdir($this->directory);
        }
    }

    public function testOperatorsAndAssignmentsPersistByUuid(): void
    {
        $path = $this->directory . DIRECTORY_SEPARATOR . 'permissions.json';
        $store = new PermissionStore($path);
        self::assertFalse($store->hasPermission(self::UUID, 'example.build'));
        self::assertTrue($store->grant(self::UUID, 'Player', 'example.*'));
        self::assertTrue($store->hasPermission(self::UUID, 'example.build'));
        self::assertTrue($store->setOperator(self::UUID, 'RenamedPlayer', true));
        self::assertTrue($store->hasPermission(self::UUID, 'anything.at.all'));

        $reloaded = new PermissionStore($path);
        self::assertTrue($reloaded->isOperator(self::UUID));
        self::assertSame(['example.*'], $reloaded->grants(self::UUID));
        self::assertTrue($reloaded->setOperator(self::UUID, 'RenamedPlayer', false));
        self::assertFalse($reloaded->hasPermission(self::UUID, 'anything.at.all'));
        self::assertTrue($reloaded->revoke(self::UUID, 'example.*'));
        self::assertFalse((new PermissionStore($path))->hasPermission(self::UUID, 'example.build'));
    }

    public function testCorruptAndDuplicateDataFailsClosed(): void
    {
        mkdir($this->directory, 0o775, true);
        $path = $this->directory . DIRECTORY_SEPARATOR . 'permissions.json';
        file_put_contents($path, '{"schema":1,"operators":{},"permissions":{"' . self::UUID . '":{"name":"Player","grants":["a.b","a.b"]}}}');
        $this->expectException(\RuntimeException::class);
        new PermissionStore($path);
    }
}
