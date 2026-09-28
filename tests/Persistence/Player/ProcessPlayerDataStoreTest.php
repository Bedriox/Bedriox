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

namespace Bedriox\Server\Tests\Persistence\Player;

use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\Player\ProcessPlayerDataStore;
use Bedriox\Server\Player\Persistence\Exception\CorruptPlayerDataException;
use Bedriox\Server\Player\Persistence\Exception\PlayerDataException;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Position;
use PHPUnit\Framework\TestCase;

final class ProcessPlayerDataStoreTest extends TestCase
{
    private const string UUID = '12345678-1234-5678-9abc-123456789abc';
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-player-process-' . bin2hex(random_bytes(6));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory . DIRECTORY_SEPARATOR . '*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->directory);
    }

    public function testChildExclusivelySavesLoadsAndClosesFileStore(): void
    {
        $store = ProcessPlayerDataStore::start('test-version', $this->directory);
        self::assertFalse($store->exists(self::UUID));
        self::assertNull($store->load(self::UUID));

        $store->save(self::profile('First'));
        $store->save(self::profile('Second'));
        self::assertTrue($store->exists(self::UUID));
        self::assertSame('Second', $store->load(self::UUID)?->identity->displayName);
        self::assertSame([], glob($this->directory . DIRECTORY_SEPARATOR . '*.tmp-*') ?: []);

        $store->close();
        self::assertTrue($store->ownerConfirmedClosed());
        $this->expectException(PlayerDataException::class);
        $store->load(self::UUID);
    }

    public function testCorruptProfileCategoryCrossesProcessBoundary(): void
    {
        self::assertTrue(mkdir($this->directory));
        file_put_contents($this->directory . DIRECTORY_SEPARATOR . self::UUID . '.dat', 'invalid nbt');
        $store = ProcessPlayerDataStore::start('test-version', $this->directory);
        try {
            $this->expectException(CorruptPlayerDataException::class);
            $store->load(self::UUID);
        } finally {
            $store->close();
        }
    }

    public function testNonblockingSavesCoalesceAndAcknowledgeExactPlayerRevision(): void
    {
        $store = ProcessPlayerDataStore::start('test-version', $this->directory);
        try {
            self::assertSame(PersistenceSubmission::ACCEPTED, $store->enqueueSave(self::profile('First'), 4)->status);
            self::assertSame(PersistenceSubmission::COALESCED, $store->enqueueSave(self::profile('Second'), 5)->status);

            $completions = $store->drainSaves(5_000);
            self::assertCount(1, $completions);
            self::assertTrue($completions[0]->successful);
            self::assertSame(5, $completions[0]->revision);
            self::assertSame(self::UUID, ProcessPlayerDataStore::uuidFor($completions[0]));
            self::assertSame('Second', $store->load(self::UUID)?->identity->displayName);
        } finally {
            $store->close();
        }
    }

    private static function profile(string $name): PlayerBootstrap
    {
        return new PlayerBootstrap(
            new PlayerIdentity(self::UUID, $name, '123'),
            'world',
            new Position(1.0, 65.0, 2.0),
            10.0,
            5.0,
            new PlayerInventoryState([], 0),
            100,
            200,
        );
    }
}
