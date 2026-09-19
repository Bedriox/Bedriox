<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player\Persistence;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\Persistence\PlayerDataStore;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
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

    public function testUnavailableWorldUsesDefaultSpawnWithoutDiscardingInventory(): void
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

        self::assertEquals($spawn, $loaded->position);
        self::assertSame(0.0, $loaded->yaw);
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
        self::assertSame(20.0, $store->profile?->health);
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
