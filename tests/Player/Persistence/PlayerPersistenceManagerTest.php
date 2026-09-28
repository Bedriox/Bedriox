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

namespace Bedriox\Server\Tests\Player\Persistence;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Persistence\PersistenceEnqueueResult;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Persistence\PersistenceWriteCompletion;
use Bedriox\Server\Persistence\PersistenceWriteRequest;
use Bedriox\Server\Player\Persistence\AsynchronousPlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class PlayerPersistenceManagerTest extends TestCase
{
    public function testRestoresExactPositionAndRefreshesAuthenticatedIdentityMetadata(): void
    {
        $palette = self::palette();
        $stored = new PlayerBootstrap(
            new PlayerIdentity(self::UUID, 'OldName', '11'),
            'world',
            new Position(2.25, 61.0, -9.5),
            170.0,
            -20.0,
            PlayerInventory::starter($palette)->exportState(),
            100,
            200,
        );
        $manager = new PlayerPersistenceManager(
            $store = new MemoryPlayerDataStore($stored),
            'world',
            new Position(0.0, 64.0, 0.0),
            $palette,
            static fn(): int => 300,
        );

        $loaded = $manager->load(self::login('CurrentName', '22'));

        self::assertEquals(new Position(2.25, 61.0, -9.5), $loaded->position);
        self::assertSame(170.0, $loaded->yaw);
        self::assertSame(-20.0, $loaded->pitch);
        self::assertSame('CurrentName', $loaded->identity->displayName);
        self::assertSame('22', $loaded->identity->xuid);
        self::assertSame(100, $loaded->firstPlayedAt);
        self::assertSame(300, $loaded->lastPlayedAt);
        self::assertSame($stored, $store->profile);
    }

    public function testSavedWorldIsPreservedForRuntimeAvailabilityResolution(): void
    {
        $palette = self::palette();
        $stored = new PlayerBootstrap(
            new PlayerIdentity(self::UUID, 'Player', '1'),
            'unavailable',
            new Position(20.0, 90.0, 20.0),
            40.0,
            15.0,
            PlayerInventory::starter($palette)->exportState(),
            100,
            200,
        );
        $manager = new PlayerPersistenceManager(
            new MemoryPlayerDataStore($stored),
            'world',
            $spawn = new Position(4.0, 72.0, -3.0),
            $palette,
            static fn(): int => 300,
        );

        $loaded = $manager->load(self::login());

        self::assertSame('unavailable', $loaded->worldName);
        self::assertEquals($stored->position, $loaded->position);
        self::assertSame(40.0, $loaded->yaw);
        self::assertEquals($stored->inventory, $loaded->inventory);
    }

    public function testDeadSavedProfileReturnsAtSpawnAliveWithoutDiscardingInventory(): void
    {
        $palette = self::palette();
        $stored = new PlayerBootstrap(
            new PlayerIdentity(self::UUID, 'Player', '1'),
            'world',
            new Position(20.0, 90.0, 20.0),
            40.0,
            15.0,
            PlayerInventory::starter($palette)->exportState(),
            100,
            200,
            health: 0.0,
            food: 3.0,
            saturation: 1.0,
            exhaustion: 2.0,
        );
        $manager = new PlayerPersistenceManager(
            new MemoryPlayerDataStore($stored),
            'world',
            $spawn = new Position(4.0, 72.0, -3.0),
            $palette,
            static fn(): int => 300,
        );

        $loaded = $manager->load(self::login());

        self::assertEquals($spawn, $loaded->position);
        self::assertSame(0.0, $loaded->yaw);
        self::assertSame(0.0, $loaded->pitch);
        self::assertSame(20.0, $loaded->health);
        self::assertSame(20.0, $loaded->food);
        self::assertSame(20.0, $loaded->saturation);
        self::assertSame(0.0, $loaded->exhaustion);
        self::assertEquals($stored->inventory, $loaded->inventory);
    }

    public function testFailedSaveRemainsPendingUntilAConfirmedRetry(): void
    {
        $palette = self::palette();
        $store = new MemoryPlayerDataStore();
        $manager = new PlayerPersistenceManager(
            $store,
            'world',
            new Position(0.0, 64.0, 0.0),
            $palette,
            static fn(): int => 300,
        );
        $player = new Player(
            'session',
            1,
            new PlayerIdentity(self::UUID, 'Player', '1'),
            new Position(5.0, 70.0, 6.0),
            4,
            0,
            64.0,
            PlayerInventory::starter($palette),
            'world',
            100,
            health: 12.0,
            food: 9.0,
            saturation: 3.0,
            exhaustion: 1.5,
        );
        $player->markDirty();
        $store->failWrites = true;

        self::assertFalse($manager->save($player));
        self::assertTrue($player->isDirty());
        self::assertSame(1, $manager->pendingCount());

        $store->failWrites = false;
        self::assertSame(1, $manager->retryPending(1));
        self::assertFalse($player->isDirty());
        self::assertSame(0, $manager->pendingCount());
        self::assertEquals(new Position(5.0, 70.0, 6.0), $store->profile?->position);
        self::assertSame(12.0, $store->profile?->health);
        self::assertSame(9.0, $store->profile->food);
        self::assertSame(3.0, $store->profile->saturation);
        self::assertSame(1.5, $store->profile->exhaustion);
    }

    public function testSaveSnapshotPreservesArmorAndOffhandState(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = self::palette();
        $inventory = PlayerInventory::restore(
            new PlayerInventoryState([], 0, armor: [
                new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond_helmet', 1)),
                new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:diamond_chestplate', 1)),
                new PlayerInventoryEntry(2, new PlayerInventoryStackState('minecraft:diamond_leggings', 1)),
                new PlayerInventoryEntry(3, new PlayerInventoryStackState('minecraft:diamond_boots', 1)),
            ], offhand: new PlayerInventoryStackState('minecraft:shield', 1, damage: 3)),
            $palette,
            ItemCatalog::vanilla($data->itemNetworkRegistry()),
            new BlockStateRegistry($data->blockStateRegistry()->states()),
        );
        $player = new Player(
            'session',
            1,
            new PlayerIdentity(self::UUID, 'Player', '1'),
            new Position(5.0, 70.0, 6.0),
            4,
            0,
            64.0,
            $inventory,
            'world',
            100,
        );
        $store = new MemoryPlayerDataStore();
        $manager = new PlayerPersistenceManager(
            $store,
            'world',
            new Position(0.0, 64.0, 0.0),
            $palette,
            static fn(): int => 300,
        );

        self::assertTrue($manager->save($player));
        self::assertNotNull($store->profile);
        self::assertSame([0, 1, 2, 3], array_map(
            static fn(PlayerInventoryEntry $entry): int => $entry->slot,
            $store->profile->inventory->armor,
        ));
        $offhand = $store->profile->inventory->offhand;
        self::assertNotNull($offhand);
        self::assertSame('minecraft:shield', $offhand->identifier);
        self::assertSame(3, $offhand->damage);
    }

    public function testAsynchronousSaveAcknowledgesOnlyTheCompletedRevisionDuringARace(): void
    {
        $palette = self::palette();
        $store = new FakeAsynchronousPlayerDataStore();
        $manager = new PlayerPersistenceManager(
            $store,
            'world',
            new Position(0.0, 64.0, 0.0),
            $palette,
            static fn(): int => 300,
        );
        $player = self::player($palette);
        self::assertSame(1, $player->markDirty());

        self::assertFalse($manager->save($player));
        self::assertSame([1], $store->submittedRevisions);
        self::assertSame(0, $store->synchronousSaveCalls);

        self::assertSame(2, $player->markDirty());
        self::assertFalse($manager->save($player));
        self::assertSame([1, 2], $store->submittedRevisions);

        $store->complete(1);
        self::assertSame(1, $manager->retryPending(1));
        self::assertSame(1, $player->savedRevision());
        self::assertTrue($player->isDirty());
        self::assertSame(1, $manager->pendingCount());

        $store->complete(2);
        self::assertSame(1, $manager->retryPending(1));
        self::assertSame(2, $player->savedRevision());
        self::assertFalse($player->isDirty());
        self::assertSame(0, $manager->pendingCount());
    }

    public function testCloseDrainsPendingAsynchronousSaveBeforeClosingStore(): void
    {
        $palette = self::palette();
        $store = new FakeAsynchronousPlayerDataStore();
        $store->completeDrainedSaves = true;
        $manager = new PlayerPersistenceManager(
            $store,
            'world',
            new Position(0.0, 64.0, 0.0),
            $palette,
            static fn(): int => 300,
        );
        $player = self::player($palette);
        $player->markDirty();
        self::assertFalse($manager->save($player));

        self::assertTrue($manager->close(25));

        self::assertFalse($player->isDirty());
        self::assertSame(0, $manager->pendingCount());
        self::assertSame(['drain:25', 'close'], $store->lifecycle);
        self::assertSame(0, $store->synchronousSaveCalls);
    }

    private const string UUID = '00000000-0000-0000-0000-000000000001';

    private static function palette(): FixedFlatBlockPalette
    {
        $network = BedrockDataSet::bundled()->blockStateRegistry();

        return FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry($network->states()));
    }

    private static function login(string $name = 'Player', string $xuid = '1'): AuthenticatedLogin
    {
        $key = (new OpenSslEphemeralKeyFactory(dirname(__DIR__, 2) . '/Fixtures/openssl.cnf'))->generate()->publicKey;

        return new AuthenticatedLogin(
            $name,
            self::UUID,
            $xuid,
            $key,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', [], skinId: 'skin'),
        );
    }

    private static function player(FixedFlatBlockPalette $palette): Player
    {
        return new Player(
            'session',
            1,
            new PlayerIdentity(self::UUID, 'Player', '1'),
            new Position(5.0, 70.0, 6.0),
            4,
            0,
            64.0,
            PlayerInventory::starter($palette),
            'world',
            100,
        );
    }
}

final class MemoryPlayerDataStore implements PlayerDataStore
{
    public bool $failWrites = false;

    public function __construct(public ?PlayerBootstrap $profile = null) {}

    public function exists(string $uuid): bool
    {
        return $this->profile?->identity->uuid === $uuid;
    }

    public function load(string $uuid): ?PlayerBootstrap
    {
        return $this->exists($uuid) ? $this->profile : null;
    }

    public function save(PlayerBootstrap $player): void
    {
        if ($this->failWrites) {
            throw new RuntimeException('write failed');
        }
        $this->profile = $player;
    }
}

final class FakeAsynchronousPlayerDataStore implements AsynchronousPlayerDataStore
{
    /** @var list<int> */
    public array $submittedRevisions = [];
    /** @var list<string> */
    public array $lifecycle = [];
    public int $synchronousSaveCalls = 0;
    public bool $completeDrainedSaves = false;
    /** @var array<int, PersistenceWriteRequest> */
    private array $requests = [];
    /** @var list<PersistenceWriteCompletion> */
    private array $completions = [];
    private int $nextRequestId = 1;

    public function exists(string $uuid): bool
    {
        return false;
    }

    public function load(string $uuid): ?PlayerBootstrap
    {
        return null;
    }

    public function save(PlayerBootstrap $player): void
    {
        ++$this->synchronousSaveCalls;
    }

    public function enqueueSave(PlayerBootstrap $player, int $revision): PersistenceEnqueueResult
    {
        if (isset($this->requests[$revision])) {
            return new PersistenceEnqueueResult(PersistenceSubmission::STALE, null);
        }
        $request = new PersistenceWriteRequest(
            $this->nextRequestId++,
            count($this->submittedRevisions) + 1,
            'player:' . $player->identity->uuid,
            $revision,
            'player',
        );
        $this->requests[$revision] = $request;
        $this->submittedRevisions[] = $revision;

        return new PersistenceEnqueueResult(PersistenceSubmission::ACCEPTED, $request);
    }

    public function complete(int $revision): void
    {
        $request = $this->requests[$revision];
        unset($this->requests[$revision]);
        $this->completions[] = new PersistenceWriteCompletion(
            $request->id,
            $request->key,
            $request->revision,
            true,
        );
    }

    public function pollSaves(int $maximumCompletions = 256): array
    {
        return array_splice($this->completions, 0, $maximumCompletions);
    }

    public function drainSaves(int $timeoutMilliseconds): array
    {
        $this->lifecycle[] = 'drain:' . $timeoutMilliseconds;
        if ($this->completeDrainedSaves) {
            foreach (array_keys($this->requests) as $revision) {
                $this->complete($revision);
            }
        }

        return $this->pollSaves();
    }

    public function close(): void
    {
        $this->lifecycle[] = 'close';
    }
}
