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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Security\EphemeralKeyFactory;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Runtime\ConfiguredWorldFactory;
use Bedriox\Server\Runtime\OpenedWorld;
use Bedriox\Server\Runtime\PlayInitializationFactory;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\Provider\ChunkSaveData;
use Bedriox\Server\World\Provider\LoadedChunkData;
use Bedriox\Server\World\Provider\WorldData;
use Bedriox\Server\World\Provider\WritableWorldProvider;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;

final class ServerBootstrapTest extends TestCase
{
    private const string OFFLINE_MAGIC = "\x00\xff\xff\x00\xfe\xfe\xfe\xfe\xfd\xfd\xfd\xfd\x12\x34\x56\x78";

    public function testExplicitSelfSignedBootstrapBindsAndExposesWarning(): void
    {
        $port = $this->availableUdpPort();
        $server = (new ServerBootstrap())->create(
            new ServerConfig('127.0.0.1', $port, 'Bootstrap Test', 2, AuthenticationMode::SELF_SIGNED),
            new BootstrapEmptyInitializationFactory(),
        );
        try {
            self::assertSame('127.0.0.1', $server->localAddress);
            self::assertSame($port, $server->localPort);
            self::assertSame(ServerBootstrap::SELF_SIGNED_WARNING, $server->securityWarning);
            self::assertFalse($server->runtime->isClosed());
            self::assertSame('world', $server->worldManager->getDefault()->id());
            self::assertSame([$server->worldManager->getDefault()], $server->worldManager->getLoaded());
        } finally {
            $server->runtime->close();
        }
        self::assertTrue($server->runtime->isClosed());
    }

    public function testDiscoveryAdvertisesJoinableSurvivalStatus(): void
    {
        $port = $this->availableUdpPort();
        $server = (new ServerBootstrap())->create(
            new ServerConfig('127.0.0.1', $port, 'Bootstrap Test', 2, AuthenticationMode::SELF_SIGNED),
            new BootstrapEmptyInitializationFactory(),
        );
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $socket);
        self::assertTrue(socket_set_option($socket, SOL_SOCKET, SO_RCVTIMEO, ['sec' => 1, 'usec' => 0]));

        try {
            $ping = $this->independentPing(0x01, 123_456, 987_654);
            self::assertSame(strlen($ping), socket_sendto($socket, $ping, strlen($ping), 0, '127.0.0.1', $port));
            self::assertTrue($server->runtime->poll());

            $fields = $this->independentPongFields($socket, 123_456);
            self::assertSame('MCPE', $fields[0] ?? null);
            self::assertSame('Bootstrap Test', $fields[1] ?? null);
            self::assertSame('2193', $fields[2] ?? null);
            self::assertSame('1.26.50', $fields[3] ?? null);
            self::assertSame('0', $fields[4] ?? null);
            self::assertSame('2', $fields[5] ?? null);
            self::assertMatchesRegularExpression('/^[1-9][0-9]*$/', $fields[6] ?? '');
            self::assertSame('Powered by Bedriox', $fields[7] ?? null);
            self::assertSame('Survival', $fields[8] ?? null);
            self::assertSame('1', $fields[9] ?? null);
            self::assertSame((string) $port, $fields[10] ?? null);
            self::assertSame('19133', $fields[11] ?? null);
            self::assertSame('', $fields[12] ?? null);
            self::assertCount(13, $fields);

            $openConnectionsPing = $this->independentPing(0x02, 123_457, 987_655);
            self::assertSame(strlen($openConnectionsPing), socket_sendto($socket, $openConnectionsPing, strlen($openConnectionsPing), 0, '127.0.0.1', $port));
            self::assertTrue($server->runtime->poll());
            self::assertSame($fields, $this->independentPongFields($socket, 123_457));
        } finally {
            socket_close($socket);
            $server->runtime->close();
        }
    }

    public function testConfiguredWorldIsSharedAndClosedWithRuntime(): void
    {
        $port = $this->availableUdpPort();
        $worlds = new BootstrapRecordingWorldFactory();
        $server = (new ServerBootstrap($worlds))->create(
            new ServerConfig('127.0.0.1', $port, 'Bootstrap Test', 2, AuthenticationMode::SELF_SIGNED),
            new BootstrapEmptyInitializationFactory(),
        );

        self::assertSame($worlds->world, $server->world);
        self::assertFalse($worlds->provider->closed);
        $server->runtime->close();
        self::assertTrue($worlds->provider->closed);
    }

    public function testWorldOpenedBeforeBindFailureIsClosed(): void
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $socket);
        self::assertTrue(socket_bind($socket, '127.0.0.1', 0));
        $address = '';
        $port = 0;
        self::assertTrue(socket_getsockname($socket, $address, $port));
        self::assertIsInt($port);
        $worlds = new BootstrapRecordingWorldFactory();

        try {
            (new ServerBootstrap($worlds))->create(
                new ServerConfig('127.0.0.1', $port, 'Bootstrap Test', 2, AuthenticationMode::SELF_SIGNED),
                new BootstrapEmptyInitializationFactory(),
            );
            self::fail('Bootstrap unexpectedly bound an occupied UDP port.');
        } catch (\Throwable) {
            self::assertSame(1, $worlds->openCount);
            self::assertTrue($worlds->provider->closed);
        } finally {
            socket_close($socket);
        }
    }

    public function testHandshakeKeyPreflightFailsBeforeWorldOpenOrUdpBind(): void
    {
        $port = $this->availableUdpPort();
        $worlds = new BootstrapRecordingWorldFactory();

        try {
            (new ServerBootstrap($worlds, new BootstrapFailingEphemeralKeyFactory()))->create(
                new ServerConfig('127.0.0.1', $port, 'Bootstrap Test', 2, AuthenticationMode::SELF_SIGNED),
                new BootstrapEmptyInitializationFactory(),
            );
            self::fail('Bootstrap unexpectedly accepted a handshake key factory that cannot generate P-384 keys.');
        } catch (\RuntimeException $exception) {
            self::assertSame('P-384 unavailable for test', $exception->getMessage());
            self::assertSame(0, $worlds->openCount);
        }

        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $socket);
        try {
            self::assertTrue(socket_bind($socket, '127.0.0.1', $port));
        } finally {
            socket_close($socket);
        }
    }

    private function independentPing(int $identifier, int $timestamp, int $clientGuid): string
    {
        $identifierByte = match ($identifier) {
            0x01 => "\x01",
            0x02 => "\x02",
            default => throw new \InvalidArgumentException('Independent discovery ping ID must be 0x01 or 0x02.'),
        };

        return $identifierByte
            . pack('NN', 0, $timestamp)
            . self::OFFLINE_MAGIC
            . pack('NN', 0, $clientGuid);
    }

    /** @return list<string> */
    private function independentPongFields(\Socket $socket, int $expectedTimestamp): array
    {
        $response = '';
        $sourceAddress = '';
        $sourcePort = 0;
        self::assertGreaterThan(0, socket_recvfrom($socket, $response, 2_048, 0, $sourceAddress, $sourcePort));
        if (!is_string($response) || $response === '') {
            throw new \RuntimeException('Test socket returned an invalid pong payload.');
        }
        self::assertSame(0x1c, ord($response[0]));
        self::assertSame(pack('NN', 0, $expectedTimestamp), substr($response, 1, 8));
        self::assertSame(self::OFFLINE_MAGIC, substr($response, 17, 16));

        $length = unpack('nlength', substr($response, 33, 2));
        if (!is_array($length) || !isset($length['length']) || !is_int($length['length'])) {
            throw new \RuntimeException('Test socket returned an invalid pong status length.');
        }
        $statusLength = $length['length'];
        self::assertSame(35 + $statusLength, strlen($response));

        return explode(';', substr($response, 35, $statusLength));
    }

    private function availableUdpPort(): int
    {
        $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
        self::assertInstanceOf(\Socket::class, $socket);
        self::assertTrue(socket_bind($socket, '127.0.0.1', 0));
        $address = '';
        $port = 0;
        self::assertTrue(socket_getsockname($socket, $address, $port));
        socket_close($socket);
        if (!is_int($port) || $port < 1) {
            throw new \RuntimeException('Test socket returned an invalid port.');
        }

        return $port;
    }
}

final class BootstrapFailingEphemeralKeyFactory implements EphemeralKeyFactory
{
    public function generate(): P384KeyPair
    {
        throw new \RuntimeException('P-384 unavailable for test');
    }
}

final class BootstrapEmptyInitializationFactory implements PlayInitializationFactory
{
    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): array
    {
        return [];
    }

    public function fixedFlatRuntimeIds(): array
    {
        return ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4];
    }
}

final class BootstrapRecordingWorldFactory implements ConfiguredWorldFactory
{
    public int $openCount = 0;

    public readonly BootstrapRecordingWorldProvider $provider;

    public readonly World $world;

    public function __construct()
    {
        $data = BedrockDataSet::bundled();
        $states = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($states);
        $worldData = new WorldData(
            new WorldMetadata('Stored Bootstrap World', 44),
            'flat',
            new SpawnPosition(2, 64, 3),
        );
        $this->provider = new BootstrapRecordingWorldProvider($worldData);
        $this->world = new World(
            $worldData->metadata,
            new FlatWorldGenerator($palette),
            new ChunkRepository(162),
            provider: $this->provider,
        );
    }

    public function open(ServerConfig $config, BedrockDataSet $data): OpenedWorld
    {
        ++$this->openCount;

        return new OpenedWorld($this->world, $this->provider->worldData());
    }
}

final class BootstrapRecordingWorldProvider implements WritableWorldProvider
{
    public bool $closed = false;

    public function __construct(private WorldData $data) {}

    public function worldData(): WorldData
    {
        return $this->data;
    }

    public function loadChunk(ChunkPosition $position): ?LoadedChunkData
    {
        return null;
    }

    public function saveWorldData(WorldData $worldData): void
    {
        $this->data = $worldData;
    }

    public function saveChunk(ChunkSaveData $chunkData): void {}

    public function close(): void
    {
        $this->closed = true;
    }
}
