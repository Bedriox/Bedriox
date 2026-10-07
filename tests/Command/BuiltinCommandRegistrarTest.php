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

namespace Bedriox\Server\Tests\Command;

use Bedriox\Api\Command\CommandSender;
use Bedriox\Api\Command\CommandSenderType;
use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\World\Position;
use Bedriox\Api\World\WorldDifficulty;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Server\Access\BanManager;
use Bedriox\Server\Command\BuiltinCommandRegistrar;
use Bedriox\Server\Command\Default\GarbageCollectionStatus;
use Bedriox\Server\Command\Default\OnlinePlayerResolver;
use Bedriox\Server\Entity\EntityRuntimeMetrics;
use Bedriox\Server\Observability\BackgroundLogWriterSnapshot;
use Bedriox\Server\Observability\LogQueueSnapshot;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\Memory\MemorySnapshot;
use Bedriox\Server\Observability\PerformanceSnapshot;
use Bedriox\Server\Observability\PerformanceSubsystem;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Runtime\ChunkStreamingSnapshot;
use Bedriox\Server\Worker\Chunk\PreparedChunkCacheSnapshot;
use Bedriox\Server\Worker\WorkerPoolSnapshot;
use Bedriox\Server\World\ChunkRepositorySnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use Closure;
use PHPUnit\Framework\TestCase;
use Throwable;

final class BuiltinCommandRegistrarTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryDirectories = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryDirectories as $directory) {
            $files = glob($directory . DIRECTORY_SEPARATOR . '*');
            if (is_array($files)) {
                foreach ($files as $file) {
                    if (is_file($file)) {
                        unlink($file);
                    }
                }
            }
            if (is_dir($directory)) {
                rmdir($directory);
            }
        }
        $this->temporaryDirectories = [];
    }

    public function testRegistrarPreservesDefaultCommandDefinitionsAndAliases(): void
    {
        [$registry, $permissions] = $this->registry();
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
        ))->register();

        self::assertSame(17, $registry->count());
        $definitions = $registry->availableDefinitions(CommandSenderType::CONSOLE, static fn(string $permission): bool => true);
        self::assertSame(
            ['version', 'help', 'list', 'stop', 'op', 'deop', 'permission', 'gamemode', 'give', 'tp', 'effect', 'experience', 'kick', 'clear', 'tell', 'title'],
            array_map(static fn($definition): string => $definition->name, $definitions),
        );
        self::assertSame(['ver'], $definitions[0]->aliases);
        self::assertSame(['commands'], $definitions[1]->aliases);
        self::assertNull($definitions[2]->permission);
        self::assertSame('bedriox.command.stop', $definitions[3]->permission);
        self::assertSame('bedriox.command.op', $definitions[4]->permission);
        self::assertSame('bedriox.command.op', $definitions[5]->permission);
        self::assertSame(['perm'], $definitions[6]->aliases);
        self::assertSame('bedriox.command.permission', $definitions[6]->permission);
        self::assertSame('bedriox.command.gamemode', $definitions[7]->permission);
        self::assertSame('bedriox.command.give', $definitions[8]->permission);
        self::assertSame(['teleport'], $definitions[9]->aliases);
        self::assertSame('bedriox.command.teleport', $definitions[9]->permission);
        self::assertSame('bedriox.command.effect', $definitions[10]->permission);
        self::assertSame(['xp'], $definitions[11]->aliases);
        self::assertSame('bedriox.command.experience', $definitions[11]->permission);

        $sender = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($sender, 'ver'))->isSuccess());
        self::assertSame(
            'This server is running Bedriox version 1.0.0-beta.3-dev (protocol 2193).',
            $sender->messages[0],
        );
        self::assertSame('Visit https://bedriox.com', $sender->messages[1]);
        self::assertTrue(($registry->dispatch($sender, 'commands'))->isSuccess());
        self::assertContains('--------- Commands (1/1) ---------', $sender->messages);
    }

    public function testExpandedAdministrativeCommandsInvokeAuthoritativeServices(): void
    {
        $kick = null;
        $online = $this->player(
            'Example',
            '00000000-0000-0000-0000-000000000001',
            static function (string $reason, ?string $quitMessage, ?string $screenMessage, PlayerKickCause $cause, ?string $actor) use (&$kick): bool {
                $kick = [$reason, $quitMessage, $screenMessage, $cause, $actor];

                return true;
            },
        );
        $players = static fn(): array => [$online];
        [$registry, $permissions] = $this->registry($players);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-command-bans-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory));
        $this->temporaryDirectories[] = $directory;
        $broadcasts = [];
        $saved = 0;
        $autosave = null;
        $defaultMode = GameMode::SURVIVAL;
        $difficulty = WorldDifficulty::NORMAL;
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            $players,
            static function (): void {},
            static fn(): array => ['minecraft:stone'],
            broadcast: static function (string $message) use (&$broadcasts): int {
                $broadcasts[] = $message;
                return 2;
            },
            pluginStates: static fn(): array => ['Example' => true],
            worldSeed: static fn(): int => 123,
            setBlock: static fn(): bool => true,
            enchant: static fn(): bool => true,
            saveWorlds: static function () use (&$saved): int {
                ++$saved;
                return 1;
            },
            currentDefaultGameMode: static fn(): GameMode => $defaultMode,
            setDefaultGameMode: static function (GameMode $mode) use (&$defaultMode): bool {
                $defaultMode = $mode;
                return true;
            },
            currentDifficulty: static fn(): WorldDifficulty => $difficulty,
            setDifficulty: static function ($world, WorldDifficulty $value) use (&$difficulty): bool {
                $difficulty = $value;
                return true;
            },
            setWorldSpawn: static fn(): bool => true,
            setPlayerSpawnPoint: static fn(): bool => true,
            setAutosave: static function (bool $enabled) use (&$autosave): bool {
                $autosave = $enabled;
                return true;
            },
            bans: new BanManager($directory . DIRECTORY_SEPARATOR . 'bans.json'),
            playerAddress: static fn(): ?string => null,
            kickAddress: static fn(string $address, string $reason, string $actor): int => 0,
        ))->register();

        $names = array_map(
            static fn($command): string => $command->definition->name,
            $registry->availableCommands(CommandSenderType::CONSOLE, static fn(string $permission): bool => true),
        );
        foreach (['say', 'plugins', 'seed', 'setblock', 'enchant', 'save-all', 'defaultgamemode', 'difficulty',
            'setworldspawn', 'spawnpoint', 'save-on', 'save-off', 'ban', 'ban-ip', 'banlist', 'pardon', 'pardon-ip'] as $name) {
            self::assertContains($name, $names);
        }

        $sender = new BuiltinCommandSender();
        self::assertTrue($registry->dispatch($sender, 'say maintenance soon')->isSuccess());
        self::assertSame(['[Server] maintenance soon'], $broadcasts);
        self::assertTrue($registry->dispatch($sender, 'defaultgamemode creative')->isSuccess());
        self::assertSame(GameMode::CREATIVE, $defaultMode);
        self::assertTrue($registry->dispatch($sender, 'difficulty hard')->isSuccess());
        self::assertSame(WorldDifficulty::HARD, $difficulty);
        self::assertTrue($registry->dispatch($sender, 'save-all')->isSuccess());
        self::assertSame(1, $saved);
        self::assertTrue($registry->dispatch($sender, 'save-off')->isSuccess());
        self::assertFalse($autosave);
        self::assertTrue($registry->dispatch($sender, 'kick Example griefing')->isSuccess());
        self::assertSame(['griefing', null, 'griefing', PlayerKickCause::OPERATOR, 'Console'], $kick);
        self::assertTrue($registry->dispatch($sender, 'ban Example testing')->isSuccess());
        self::assertSame([
            'testing',
            null,
            "You are banned from this server.\ntesting",
            PlayerKickCause::BAN,
            'Console',
        ], $kick);
        self::assertTrue($registry->dispatch($sender, 'banlist players')->isSuccess());
        self::assertTrue($registry->dispatch($sender, 'pardon Example')->isSuccess());
        self::assertTrue($registry->dispatch($sender, 'ban-ip 127.0.0.1 testing')->isSuccess());
        self::assertTrue($registry->dispatch($sender, 'pardon-ip 127.0.0.1')->isSuccess());
    }

    public function testKillCommandIsRegisteredWithItsParentPermissionWhenRuntimeIsAvailable(): void
    {
        [$registry, $permissions] = $this->registry();
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            killTarget: static fn(
                \Bedriox\Api\Player\Player|\Bedriox\Api\Entity\Entity $target,
            ): bool => true,
        ))->register();

        $definitions = $registry->availableDefinitions(
            CommandSenderType::CONSOLE,
            static fn(string $permission): bool => true,
        );
        $kill = array_values(array_filter(
            $definitions,
            static fn($definition): bool => $definition->name === 'kill',
        ));

        self::assertCount(1, $kill);
        self::assertSame(['suicide'], $kill[0]->aliases);
        self::assertSame('bedriox.command.kill', $kill[0]->permission);
    }

    public function testPlayerListOperatorAndPermissionCommandsPreserveBehavior(): void
    {
        $amy = $this->player('Amy', '00000000-0000-0000-0000-000000000001');
        $zed = $this->player('zed', '00000000-0000-0000-0000-000000000002');
        [$registry, $permissions] = $this->registry(static fn(): array => [$zed, $amy]);
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [$zed, $amy],
            static function (): void {},
            static fn(): array => [],
        ))->register();
        $sender = new BuiltinCommandSender();

        self::assertTrue(($registry->dispatch($sender, 'list'))->isSuccess());
        self::assertSame('There are 2 players online.', $sender->messages[0]);
        self::assertSame('Players: Amy, zed', $sender->messages[1]);
        self::assertTrue(($registry->dispatch($sender, 'op aMY'))->isSuccess());
        self::assertSame('Amy is now an operator.', array_pop($sender->messages));
        self::assertTrue($permissions->isOperator($amy->uuid));
        self::assertTrue(($registry->dispatch($sender, 'op Amy'))->isSuccess());
        self::assertSame('Amy is already an operator.', array_pop($sender->messages));
        self::assertTrue(($registry->dispatch($sender, 'deop AMY'))->isSuccess());
        self::assertSame('Amy is no longer an operator.', array_pop($sender->messages));
        self::assertFalse($permissions->isOperator($amy->uuid));

        self::assertTrue(($registry->dispatch($sender, 'perm grant amy example.use'))->isSuccess());
        self::assertSame('Permission assignment updated.', array_pop($sender->messages));
        self::assertTrue($permissions->hasPermission($amy->uuid, 'example.use'));
        self::assertTrue(($registry->dispatch($sender, 'permission list Amy'))->isSuccess());
        self::assertSame('Permissions for Amy: example.use', array_pop($sender->messages));
        self::assertTrue(($registry->dispatch($sender, 'permission revoke Amy example.use'))->isSuccess());
        self::assertSame('Permission assignment updated.', array_pop($sender->messages));
        self::assertFalse($permissions->hasPermission($amy->uuid, 'example.use'));

        self::assertFalse(($registry->dispatch($sender, 'op Missing'))->isSuccess());
        self::assertSame("Player 'Missing' is not connected.", $sender->messages[count($sender->messages) - 2]);
        self::assertSame('Usage: /op <player>', array_pop($sender->messages));
        self::assertFalse(($registry->dispatch($sender, 'permission invalid Amy'))->isSuccess());
        self::assertContains('Usage: /permission list <player>', $sender->messages);
        self::assertContains('Usage: /permission grant <player> <node>', $sender->messages);
        self::assertSame('Usage: /permission revoke <player> <node>', array_pop($sender->messages));
    }

    public function testStopCommandInvokesTheExistingShutdownBoundaryOnlyAfterValidUsage(): void
    {
        [$registry, $permissions] = $this->registry();
        $stops = 0;
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function () use (&$stops): void {
                ++$stops;
            },
            static fn(): array => [],
        ))->register();
        $sender = new BuiltinCommandSender();

        self::assertFalse(($registry->dispatch($sender, 'stop now'))->isSuccess());
        self::assertSame(0, $stops);
        self::assertSame('Usage: /stop', array_pop($sender->messages));
        self::assertTrue(($registry->dispatch($sender, 'stop'))->isSuccess());
        self::assertSame(1, $stops);
        self::assertSame('Stopping the server...', array_pop($sender->messages));
    }

    public function testOnlinePlayerResolverUsesFreshSnapshotsAndCaseInsensitiveExactNames(): void
    {
        $players = [$this->player('First', '00000000-0000-0000-0000-000000000001')];
        $resolver = new OnlinePlayerResolver(static function () use (&$players): array {
            return $players;
        });

        self::assertSame('First', $resolver->find('fIrSt')?->name);
        self::assertNull($resolver->find('Fir'));
        $players = [$this->player('Second', '00000000-0000-0000-0000-000000000002')];
        self::assertNull($resolver->find('First'));
        self::assertSame('Second', $resolver->find('second')?->name);
    }

    public function testPlayerFacingListAndVersionMessagesUseSeparateColors(): void
    {
        $player = $this->player('Amy', '00000000-0000-0000-0000-000000000001');
        [$registry, $permissions] = $this->registry(static fn(): array => [$player]);
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [$player],
            static function (): void {},
            static fn(): array => [],
        ))->register();
        $sender = new BuiltinCommandSender(CommandSenderType::PLAYER);

        self::assertTrue(($registry->dispatch($sender, 'list'))->isSuccess());
        self::assertSame("\u{00a7}aThere is 1 player online.\u{00a7}r", $sender->messages[0]);
        self::assertSame("\u{00a7}bPlayers: Amy\u{00a7}r", $sender->messages[1]);

        $versionSender = new BuiltinCommandSender(CommandSenderType::PLAYER);
        self::assertTrue(($registry->dispatch($versionSender, 'version'))->isSuccess());
        self::assertSame(
            "\u{00a7}aThis server is running Bedriox version 1.0.0-beta.3-dev (protocol 2193).\u{00a7}r",
            $versionSender->messages[0],
        );
        self::assertSame("\u{00a7}bVisit https://bedriox.com\u{00a7}r", $versionSender->messages[1]);
    }

    public function testGameplayCommandCallbacksAreWiredThroughTheRegistrar(): void
    {
        $player = $this->player('Amy', '00000000-0000-0000-0000-000000000001');
        [$registry, $permissions] = $this->registry(static fn(): array => [$player]);
        $changes = [];
        $grants = [];
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [$player],
            static function (): void {},
            static fn(): array => ['diamond', 'minecraft:diamond'],
            changeGameMode: static function (Player $target, GameMode $mode) use (&$changes): bool {
                $changes[] = [$target->uuid, $mode];

                return true;
            },
            giveItem: static function (Player $target, string $identifier, int $amount) use (&$grants): bool {
                $grants[] = [$target->uuid, $identifier, $amount];

                return true;
            },
            itemExists: static fn(string $identifier): bool => $identifier === 'minecraft:diamond',
        ))->register();
        $sender = new BuiltinCommandSender();

        self::assertTrue(($registry->dispatch($sender, 'gamemode creative Amy'))->isSuccess());
        self::assertSame([[$player->uuid, GameMode::CREATIVE]], $changes);
        self::assertTrue(($registry->dispatch($sender, 'give Amy diamond 3'))->isSuccess());
        self::assertSame([[$player->uuid, 'minecraft:diamond', 3]], $grants);
    }

    public function testStatusCommandRequiresItsPermissionAndRendersTheProvidedSnapshot(): void
    {
        [$registry, $permissions] = $this->registry();
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            status: static fn(): PerformanceSnapshot => new PerformanceSnapshot(
                90_061,
                20.0,
                19.95,
                18.5,
                2.5,
                3.0,
                5.0,
                8.0,
                5.0,
                1200,
                0.5,
                1.0,
                16 * 1_048_576,
                32 * 1_048_576,
                2,
                20,
                25,
                3,
                averageSubsystemMilliseconds: [
                    PerformanceSubsystem::TRANSPORT => 0.25,
                    PerformanceSubsystem::WORLD => 1.5,
                ],
                averageUnclassifiedMilliseconds: 0.75,
                networkReceiveBytesPerSecond: 1_024.0,
                networkSendBytesPerSecond: 2_048.0,
                configuredMemoryLimitBytes: 500 * 1_048_576,
                worldCount: 1,
                entityCount: 4,
                pendingAsyncPluginTasks: 2,
                maximumAsyncCompletionsPerTick: 64,
                chunkCache: new ChunkRepositorySnapshot(128, 25, 9, 18, 3, 80, 20, 4),
                chunkStreaming: new ChunkStreamingSnapshot(2, 6, 8, 3, 4, 18, 5),
                worldPersistence: new PersistenceQueueSnapshot(2, 1, 3, 4_096, 4, 5, 6),
                playerPersistence: new PersistenceQueueSnapshot(1, 0, 2, 2_048, 3, 4, 5),
                preparedChunkCache: new PreparedChunkCacheSnapshot(12, 24_576, 3, 6_144, 80, 20, 2, 4, 1),
                entityRuntime: new EntityRuntimeMetrics(40, 12, 10, 28, 2, 1, 8, 3, 1_250_000, true),
            ),
        ))->register();

        $sender = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($sender, 'status'))->isSuccess());
        self::assertSame([
            '--------- Bedriox Status ---------',
            'Version: Bedriox 1.0.0-beta.3-dev',
            'Uptime: 1d 01h 01m 01s',
            'Players: 2/20 online',
            'TPS: 20.00 current, 19.95 average',
            'MSPT: 2.50 current, 3.00 average',
            'Memory: 16.0 MiB current, 500.0 MiB limit, 32.0 MiB peak',
            'Network: 1.0 KiB/s receive, 2.0 KiB/s send',
            'Worlds: 1, 25 loaded chunks, 4 entities',
            '--------- End Status ---------',
        ], $sender->messages);

        $advanced = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($advanced, 'status advanced'))->isSuccess());
        self::assertSame('--------- Bedriox Status: Advanced ---------', $advanced->messages[0]);
        self::assertContains('--------- Tick Performance ---------', $advanced->messages);
        self::assertContains('TPS history: 19.95 average, 18.50 minimum', $advanced->messages);
        self::assertContains('MSPT history: 3.00 average, 5.00 p95, 8.00 p99', $advanced->messages);
        self::assertContains('Chunks: 25 loaded, 3 dirty, 0 generating', $advanced->messages);
        self::assertContains('Chunk cache: 25/128 loaded, 9 retained (18 references)', $advanced->messages);
        self::assertContains('Chunk cache lookups: 80 hits, 20 misses, 4 evictions, 80.0% hit ratio', $advanced->messages);
        self::assertContains('Chunk streaming: 6 visible pending, 8 prefetch pending, 3 generation queued, 4 delivery queued', $advanced->messages);
        self::assertContains('Prepared chunks: 12 entries (24.0 KiB), 3 pending (6.0 KiB)', $advanced->messages);
        self::assertContains('Prepared chunk lookups: hits 80, misses 20, evictions 2, invalidations 4, failures 1, hit ratio 80.0%', $advanced->messages);
        self::assertContains('Asynchronous tasks: 2 pending, 64 completions per tick maximum', $advanced->messages);
        self::assertContains('Session queues: 5 outgoing payloads', $advanced->messages);
        self::assertContains('World persistence: 2 queued, 1 in flight, 3 completions (4.0 KiB)', $advanced->messages);
        self::assertContains('Player persistence totals: 3 coalesced, 4 saturated, 5 failed', $advanced->messages);
        self::assertContains('Core workers: unavailable', $advanced->messages);
        self::assertContains('Plugin workers: unavailable', $advanced->messages);
        self::assertContains('Transport: 0.25 ms', $advanced->messages);
        self::assertContains('Sessions: unavailable', $advanced->messages);
        self::assertContains('World: 1.50 ms', $advanced->messages);
        self::assertContains('Background logging: unavailable', $advanced->messages);
        self::assertContains('Entity physics: 10/12 ticked, 28 cadence skipped, 2 budget deferred', $advanced->messages);
        self::assertContains('Entity motion: 8 moved, 3 velocity changes, 1.25 ms, budget exhausted (1 safety-critical)', $advanced->messages);
        self::assertSame('--------- End Status ---------', $advanced->messages[count($advanced->messages) - 1]);

        $alias = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($alias, 'status advance'))->isSuccess());
        self::assertSame($advanced->messages, $alias->messages);
        self::assertFalse(($registry->dispatch($sender, 'status extra'))->isSuccess());
        self::assertSame('Usage: /status [detail:advanced|advance]', $sender->messages[count($sender->messages) - 1]);

        $definitions = $registry->availableDefinitions(CommandSenderType::CONSOLE, static fn(string $permission): bool => true);
        self::assertNotEmpty($definitions);
        self::assertSame('bedriox.command.status', $definitions[count($definitions) - 1]->permission);
        self::assertNotContains(
            'status',
            array_map(
                static fn($definition): string => $definition->name,
                $registry->availableDefinitions(CommandSenderType::PLAYER, static fn(string $permission): bool => false),
            ),
        );
    }

    public function testGarbageCollectorCommandIsRegisteredOnlyWithCompleteRuntimeCallbacks(): void
    {
        [$registry, $permissions] = $this->registry();
        $statusCalls = 0;
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            garbageCollectionStatus: static function () use (&$statusCalls): GarbageCollectionStatus {
                ++$statusCalls;

                return new GarbageCollectionStatus(
                    new MemorySnapshot(1_024, 2_048, 4_096, 8_192, 1),
                    MemoryPressure::NORMAL,
                    0,
                    10_001,
                    null,
                    1,
                    0,
                    0,
                    0,
                    0,
                );
            },
            collectGarbage: static fn(): GarbageCollectionReport => new GarbageCollectionReport(
                true,
                true,
                true,
                1,
                0,
                1,
                0,
                1,
                10_001,
                10_001,
            ),
            unloadChunks: static fn(): ChunkUnloadResult => new ChunkUnloadResult(1, 1, 0, false, 0),
        ))->register();

        self::assertSame(18, $registry->count());
        $sender = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($sender, 'gc'))->isSuccess());
        self::assertSame(1, $statusCalls);

        $definitions = $registry->availableDefinitions(CommandSenderType::CONSOLE, static fn(string $permission): bool => true);
        self::assertSame('gc', $definitions[count($definitions) - 1]->name);
        self::assertSame('bedriox.command.gc', $definitions[count($definitions) - 1]->permission);
        self::assertNotContains(
            'gc',
            array_map(
                static fn($definition): string => $definition->name,
                $registry->availableDefinitions(CommandSenderType::PLAYER, static fn(string $permission): bool => false),
            ),
        );
    }

    public function testAdvancedStatusDistinguishesWorkerAndLoggingServiceStates(): void
    {
        [$registry, $permissions] = $this->registry();
        (new BuiltinCommandRegistrar(
            $registry,
            $permissions,
            static fn(): array => [],
            static function (): void {},
            static fn(): array => [],
            status: static fn(): PerformanceSnapshot => new PerformanceSnapshot(
                1,
                20.0,
                20.0,
                20.0,
                1.0,
                1.0,
                1.0,
                1.0,
                2.0,
                1,
                0.1,
                0.2,
                1_024,
                2_048,
                0,
                20,
                0,
                0,
                coreWorkers: new WorkerPoolSnapshot('', 0, 0, 1, 1_024, 2, 3, 4, 5, 6, 7, 8, 9, false, 2_048),
                pluginWorkers: new WorkerPoolSnapshot(
                    '',
                    2,
                    1,
                    3,
                    4_096,
                    4,
                    5,
                    6,
                    7,
                    8,
                    9,
                    10,
                    11,
                    false,
                    8_192,
                    12_288,
                    34_816,
                ),
                logWriter: new BackgroundLogWriterSnapshot(
                    false,
                    true,
                    new LogQueueSnapshot(2, 3_072, 1, 2, 3, 42),
                    10,
                    4,
                ),
            ),
        ))->register();

        $basic = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($basic, 'status'))->isSuccess());
        self::assertSame([], array_values(array_filter(
            $basic->messages,
            static fn(string $message): bool => str_starts_with($message, 'Network:'),
        )));

        $sender = new BuiltinCommandSender();
        self::assertTrue(($registry->dispatch($sender, 'status advanced'))->isSuccess());
        self::assertContains('Core workers: disabled, 0/0 busy', $sender->messages);
        self::assertContains('Core queue: 1 pending (1.0 KiB), 2 ready (2.0 KiB)', $sender->messages);
        self::assertContains('Core totals: 3 submitted, 4 completed, 5 rejected, 6 cancelled', $sender->messages);
        self::assertContains('Core failures: 7 timed out, 8 failed, 9 restarts', $sender->messages);
        self::assertContains('Plugin workers: offline, 1/2 busy', $sender->messages);
        self::assertContains('Plugin worker memory: 34.0 KiB compute, 12.0 KiB broker', $sender->messages);
        self::assertContains('Background logging: offline', $sender->messages);
        self::assertContains('Logging queue: 2 entries (3.0 KiB), oldest sequence 42', $sender->messages);
        self::assertContains('Logging writer: write in flight, 10 acknowledged', $sender->messages);
        self::assertContains('Logging drops: 1 routine, 2 high-severity', $sender->messages);
        self::assertContains('Logging failures: 4 service, 3 write', $sender->messages);
    }

    /**
     * @param Closure(): list<Player>|null $players
     * @return array{CommandRegistry, PermissionStore}
     */
    private function registry(?Closure $players = null): array
    {
        $plugins = new BuiltinPluginControl();
        $execution = new PluginExecutionContext();
        $actions = new PluginActionBuffer();
        $ownership = new PluginOwnershipRegistry();
        $events = new EventDispatcher($plugins, $execution, $actions, $ownership);
        $directory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'bedriox-builtins-' . bin2hex(random_bytes(8));
        $this->temporaryDirectories[] = $directory;

        return [
            new CommandRegistry(
                $plugins,
                $execution,
                $actions,
                $ownership,
                $events,
                onlinePlayers: $players,
            ),
            new PermissionStore($directory . DIRECTORY_SEPARATOR . 'permissions.json'),
        ];
    }

    /** @param Closure(string, ?string, ?string, \Bedriox\Api\Event\Player\PlayerKickCause, ?string): bool|null $kick */
    private function player(string $name, string $uuid, ?Closure $kick = null): Player
    {
        return new Player(
            $name,
            $uuid,
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
            playerConnection: new PlayerConnection(
                static fn(): bool => true,
                static fn(Packet $packet, bool $immediate): bool => true,
                $kick,
            ),
        );
    }
}

final class BuiltinCommandSender implements CommandSender
{
    /** @var list<string> */
    public array $messages = [];

    public function __construct(private readonly CommandSenderType $senderType = CommandSenderType::CONSOLE) {}

    public function type(): CommandSenderType
    {
        return $this->senderType;
    }

    public function name(): string
    {
        return 'Console';
    }

    public function sendMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    public function hasPermission(string $permission): bool
    {
        return true;
    }
}

final class BuiltinPluginControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return true;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
