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

namespace Bedriox\Server\Tests\Login;

use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\ClientToServerHandshakePacket;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ServerToClientHandshakePacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\HandshakeJwt;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Login\HandshakeMaterial;
use Bedriox\Server\Login\HandshakeMaterialFactory;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginChannelLimits;
use Bedriox\Server\Login\LoginFailureCode;
use Bedriox\Server\Login\LoginLimits;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\LoginState;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use PHPUnit\Framework\TestCase;

final class BedrockLoginChannelTest extends TestCase
{
    public function testRawRakNetPayloadCompletesEncryptedLogin(): void
    {
        [$channel, $clientKeys] = $this->channel();
        self::assertTrue($channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed))));
        self::assertCount(1, $channel->drainOutgoing());

        self::assertTrue($channel->accept($this->event($this->encode($this->login(), CompressionMode::NegotiatedZlib))));
        $serverHandshakeEnvelope = $channel->drainOutgoing()[0]->payload;
        $serverHandshake = $this->decode($serverHandshakeEnvelope, CompressionMode::NegotiatedZlib);
        self::assertInstanceOf(ServerToClientHandshakePacket::class, $serverHandshake);
        $jws = HandshakeJwt::parse($serverHandshake->jwt);
        $x5u = $jws->header['x5u'] ?? null;
        $encodedSalt = $jws->payload['salt'] ?? null;
        self::assertIsString($x5u);
        self::assertIsString($encodedSalt);
        $serverKey = P384::importPublicDerBase64($x5u);
        $salt = base64_decode($encodedSalt, true);
        self::assertIsString($salt);
        $key = P384::deriveSessionKey($salt, P384::deriveSharedSecret($clientKeys->privateKey, $serverKey));
        $clientEncryptor = new BedrockEncryptor($key);

        self::assertTrue($channel->accept($this->event($clientEncryptor->encryptEnvelope($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib)))));
        self::assertCount(2, $channel->drainOutgoing());
        self::assertTrue($channel->accept($this->event($clientEncryptor->encryptEnvelope($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), CompressionMode::NegotiatedZlib)))));
        self::assertCount(1, $channel->drainOutgoing());
        self::assertTrue($channel->accept($this->event($clientEncryptor->encryptEnvelope($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), CompressionMode::NegotiatedZlib)))));
        self::assertSame(LoginState::LOGIN_READY, $channel->state());
        $ready = $channel->takeReady();
        self::assertNotNull($ready);
        self::assertSame('Player', $ready->login->displayName);
        self::assertSame("\0\0\0\0", $ready->login->clientData->skin);
        self::assertNull($channel->takeReady());
    }

    public function testWrongReliabilityFailsClosed(): void
    {
        [$wrong] = $this->channel();
        $event = new ConnectedPayloadEvent($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed), Reliability::Reliable, null);
        self::assertFalse($wrong->accept($event));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $wrong->failure());
    }

    public function testValidCombinedLoginPacketsAreProcessedInOrder(): void
    {
        [$channel, $client] = $this->channel();
        [, $encryptor] = $this->advanceToCryptoWithPair($channel, $client);
        self::assertTrue($channel->accept($this->event($encryptor->encryptEnvelope($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib)))));
        $channel->drainOutgoing();

        $combined = BedrockBatchCodec::encode(new BedrockBatch([
            $this->frame(new ClientCacheStatusPacket(false)),
            $this->frame(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, [])),
        ], CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES), new BatchLimits());

        self::assertTrue($channel->accept($this->event($encryptor->encryptEnvelope($combined))));
        self::assertSame(LoginState::WAIT_PACK_STACK_RESPONSE, $channel->state());
        self::assertCount(1, $channel->drainOutgoing());
    }

    public function testPlaintextAfterCryptoAndCorruptionFailClosed(): void
    {
        [$plain, $plainClient] = $this->channel();
        $this->advanceToCryptoWithPair($plain, $plainClient);
        self::assertFalse($plain->accept($this->event($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib))));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $plain->failure());

        [$corrupt, $corruptClient] = $this->channel();
        [, $encryptor] = $this->advanceToCryptoWithPair($corrupt, $corruptClient);
        $bytes = $encryptor->encryptEnvelope($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib));
        $bytes[strlen($bytes) - 1] = $bytes[strlen($bytes) - 1] ^ "\x01";
        self::assertFalse($corrupt->accept($this->event($bytes)));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $corrupt->failure());
    }

    public function testOutgoingLimitFailsClosedWithoutLeakingQueuedPayload(): void
    {
        [$channel] = $this->channel(new LoginChannelLimits(maximumQueuedPayloadBytes: 1));
        self::assertFalse($channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed))));
        self::assertSame(LoginFailureCode::EFFECT_LIMIT, $channel->failure());
        self::assertSame([], $channel->drainOutgoing());
    }

    public function testExpiredChannelRejectsBeforeMalformedFraming(): void
    {
        [$channel, , $clock] = $this->channel();
        $clock->advanceSeconds(15);
        self::assertFalse($channel->accept($this->event("\x00")));
        self::assertSame(LoginFailureCode::TIMEOUT, $channel->failure());
    }

    public function testExternalCloseClearsPendingOutputAndCryptoState(): void
    {
        [$channel, , , $session] = $this->channel();
        self::assertTrue($channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed))));
        self::assertNotSame([], $channel->drainOutgoing());
        self::assertTrue($channel->accept($this->event($this->encode($this->login(), CompressionMode::NegotiatedZlib))));
        $session->close();
        self::assertFalse($channel->tick());
        self::assertSame([], $channel->drainOutgoing());
        self::assertNull($channel->takeReady());
    }

    public function testSessionEnqueueRejectionTearsDownChannel(): void
    {
        [$channel] = $this->channel(sessionLimits: new LoginLimits(maximumPacketBytes: 1));
        self::assertFalse($channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed))));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $channel->failure());
        self::assertSame([], $channel->drainOutgoing());
    }

    public function testExplicitCloseIsIdempotentBeforeAndAfterCrypto(): void
    {
        [$before] = $this->channel();
        self::assertFalse($before->securityPosture()->requiresOperatorWarning());
        $before->close();
        $before->close();
        self::assertSame(LoginState::CLOSED, $before->state());
        self::assertSame(LoginFailureCode::CLOSED_BY_SERVER, $before->failure());
        self::assertSame([], $before->drainOutgoing());

        [$after, $client] = $this->channel();
        [, $encryptor] = $this->advanceToCryptoWithPair($after, $client);
        self::assertTrue($after->accept($this->event($encryptor->encryptEnvelope($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib)))));
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $after->state());
        $after->close();
        $after->close(LoginFailureCode::INPUT_LIMIT);
        self::assertSame(LoginFailureCode::CLOSED_BY_SERVER, $after->failure());
        self::assertSame([], $after->drainOutgoing());
        self::assertNull($after->takeReady());
    }

    public function testCloseAfterReadyDiscardsUnclaimedTransferAndDestructorIsSafe(): void
    {
        [$channel, $client] = $this->channel();
        [, $encryptor] = $this->advanceToCryptoWithPair($channel, $client);
        $channel->drainOutgoing();
        self::assertTrue($channel->accept($this->event($encryptor->encryptEnvelope($this->encode(new ClientToServerHandshakePacket(), CompressionMode::NegotiatedZlib)))));
        $channel->drainOutgoing();
        self::assertTrue($channel->accept($this->event($encryptor->encryptEnvelope($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), CompressionMode::NegotiatedZlib)))));
        $channel->drainOutgoing();
        self::assertTrue($channel->accept($this->event($encryptor->encryptEnvelope($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), CompressionMode::NegotiatedZlib)))));
        self::assertSame(LoginState::LOGIN_READY, $channel->state());
        $channel->close();
        self::assertSame(LoginState::CLOSED, $channel->state());
        self::assertNull($channel->takeReady());
        $weakChannel = \WeakReference::create($channel);
        unset($channel);
        gc_collect_cycles();
        self::assertNull($weakChannel->get());
    }

    /** @return array{BedrockLoginChannel, P384KeyPair, PipelineClock, LoginSession} */
    private function channel(?LoginChannelLimits $limits = null, ?LoginLimits $sessionLimits = null): array
    {
        $factory = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $client = $factory->generate();
        $authenticator = new PipelineAuthenticator(new AuthenticatedLogin(
            'Player',
            'identity',
            '1',
            $client->publicKey,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
        ));
        $handshake = new PipelineHandshakeFactory(new HandshakeMaterial($factory->generate(), str_repeat("\x41", 16)));
        $clock = new PipelineClock();
        $session = new LoginSession($clock, $authenticator, $handshake, limits: $sessionLimits ?? new LoginLimits());
        return [new BedrockLoginChannel($session, $limits ?? new LoginChannelLimits()), $client, $clock, $session];
    }

    /** @return array{P384KeyPair, BedrockEncryptor} */
    private function advanceToCryptoWithPair(BedrockLoginChannel $channel, P384KeyPair $client): array
    {
        $channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed)));
        $channel->drainOutgoing();
        $channel->accept($this->event($this->encode($this->login(), CompressionMode::NegotiatedZlib)));
        $handshake = $this->decode($channel->drainOutgoing()[0]->payload, CompressionMode::NegotiatedZlib);
        self::assertInstanceOf(ServerToClientHandshakePacket::class, $handshake);
        $jws = HandshakeJwt::parse($handshake->jwt);
        $encodedSalt = $jws->payload['salt'] ?? null;
        $x5u = $jws->header['x5u'] ?? null;
        self::assertIsString($encodedSalt);
        self::assertIsString($x5u);
        $salt = base64_decode($encodedSalt, true);
        self::assertIsString($salt);
        $key = P384::deriveSessionKey($salt, P384::deriveSharedSecret($client->privateKey, P384::importPublicDerBase64($x5u)));
        return [$client, new BedrockEncryptor($key)];
    }

    private function event(string $payload): ConnectedPayloadEvent
    {
        return new ConnectedPayloadEvent($payload, Reliability::ReliableOrdered, 0);
    }

    private function encode(Packet $packet, CompressionMode $mode): string
    {
        return BedrockBatchCodec::encode(new BedrockBatch(
            [$this->frame($packet)],
            $mode,
            NetworkCompressionPolicy::THRESHOLD_BYTES,
        ), new BatchLimits());
    }

    private function frame(Packet $packet): PacketFrame
    {
        return new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet));
    }

    private function decode(string $envelope, CompressionMode $mode): Packet
    {
        $frame = BedrockBatchCodec::decode(
            $envelope,
            $mode,
            new BatchLimits(),
            NetworkCompressionPolicy::THRESHOLD_BYTES,
        )->packets[0];
        return BedrockPacketCodec::decode($frame->header->packetId, $frame->payload);
    }

    private function login(): LoginPacket
    {
        return new LoginPacket(ProtocolVersion::CURRENT, new LoginAuthentication(AuthenticationType::Full, 'token'), 'client');
    }
}

final class PipelineClock implements MonotonicClock
{
    private int $now = 1;

    public function nowNanoseconds(): int
    {
        return $this->now;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->now += $seconds * 1_000_000_000;
    }
}

final readonly class PipelineAuthenticator implements LoginAuthenticator
{
    public function __construct(private AuthenticatedLogin $login) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        return $this->login;
    }
}

final readonly class PipelineHandshakeFactory implements HandshakeMaterialFactory
{
    public function __construct(private HandshakeMaterial $material) {}

    public function create(): HandshakeMaterial
    {
        return $this->material;
    }
}
