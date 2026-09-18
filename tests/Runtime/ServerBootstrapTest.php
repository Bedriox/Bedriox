<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Runtime\PlayInitializationFactory;
use Bedriox\Server\Runtime\ServerBootstrap;
use Bedriox\Server\Runtime\ServerConfig;
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

final class BootstrapEmptyInitializationFactory implements PlayInitializationFactory
{
    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId): array
    {
        return [];
    }

    public function fixedFlatRuntimeIds(): array
    {
        return ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4];
    }
}
