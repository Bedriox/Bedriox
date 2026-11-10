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

use Bedriox\NetherNet\Protocol\Reliability;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\PlayStatusPacket;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkDataPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkRequestPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ResourcePacksInfoPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384KeyPair;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginChannelLimits;
use Bedriox\Server\Login\LoginFailureCode;
use Bedriox\Server\Login\LoginLimits;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\LoginState;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\ResourcePack\ResourcePackArchive;
use Bedriox\Server\ResourcePack\ResourcePackCatalog;
use Bedriox\Server\Transport\InboundPayload;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use Bedriox\Server\Worker\Network\CompressionWorkerDispatcher;
use Bedriox\Server\Worker\WorkerReceipt;
use Bedriox\Server\Worker\WorkerSubmission;
use Closure;
use PHPUnit\Framework\TestCase;

final class BedrockLoginChannelTest extends TestCase
{
    public function testRawNetherNetPayloadCompletesPlaintextLogin(): void
    {
        [$channel] = $this->channel();
        $networkRequest = $this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed);
        self::assertNotSame("\xfe", $networkRequest[0]);
        self::assertTrue($channel->accept($this->event($networkRequest)));
        self::assertCount(1, $channel->drainOutgoing());

        self::assertTrue($channel->accept($this->event($this->encode($this->login(), CompressionMode::NegotiatedZlib))));
        $loginOutput = $channel->drainOutgoing();
        self::assertCount(2, $loginOutput);
        self::assertInstanceOf(PlayStatusPacket::class, $this->decode($loginOutput[0]->payload, CompressionMode::NegotiatedZlib));
        self::assertInstanceOf(ResourcePacksInfoPacket::class, $this->decode($loginOutput[1]->payload, CompressionMode::NegotiatedZlib));

        self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), CompressionMode::NegotiatedZlib))));
        self::assertCount(1, $channel->drainOutgoing());
        self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), CompressionMode::NegotiatedZlib))));
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
        $event = new InboundPayload($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed), Reliability::UNRELIABLE);
        self::assertFalse($wrong->accept($event));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $wrong->failure());
    }

    public function testValidCombinedLoginPacketsAreProcessedInOrder(): void
    {
        [$channel] = $this->channel();
        $this->advanceThroughLogin($channel);

        $combined = BedrockBatchCodec::encode(new BedrockBatch([
            $this->frame(new ClientCacheStatusPacket(false)),
            $this->frame(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, [])),
        ], CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES), new BatchLimits());

        self::assertTrue($channel->accept($this->event($combined)));
        self::assertSame(LoginState::WAIT_PACK_STACK_RESPONSE, $channel->state());
        self::assertCount(1, $channel->drainOutgoing());
    }

    public function testUnsupportedLoginFrameDoesNotDiscardFollowingKnownFrame(): void
    {
        [$channel] = $this->channel();
        $this->advanceThroughLogin($channel);

        $combined = BedrockBatchCodec::encode(new BedrockBatch([
            new PacketFrame(new PacketHeader(0x3ff), "\xde\xad"),
            $this->frame(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, [])),
        ], CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES), new BatchLimits());

        self::assertTrue($channel->accept($this->event($combined)));
        self::assertSame(LoginState::WAIT_PACK_STACK_RESPONSE, $channel->state());
        self::assertCount(1, $channel->drainOutgoing());
    }

    public function testClearPostLoginPayloadIsAcceptedAndMalformedBatchFailsClosed(): void
    {
        [$clear] = $this->channel();
        $this->advanceThroughLogin($clear);
        self::assertTrue($clear->accept($this->event($this->encode(
            new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []),
            CompressionMode::NegotiatedZlib,
        ))));
        self::assertSame(LoginState::WAIT_PACK_STACK_RESPONSE, $clear->state());

        [$corrupt] = $this->channel();
        $this->advanceThroughLogin($corrupt);
        $bytes = $this->encode(
            new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []),
            CompressionMode::NegotiatedZlib,
        );
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

    public function testExplicitCloseIsIdempotentBeforeAndAfterLogin(): void
    {
        [$before] = $this->channel();
        self::assertFalse($before->securityPosture()->requiresOperatorWarning());
        $before->close();
        $before->close();
        self::assertSame(LoginState::CLOSED, $before->state());
        self::assertSame(LoginFailureCode::CLOSED_BY_SERVER, $before->failure());
        self::assertSame([], $before->drainOutgoing());

        [$after] = $this->channel();
        $this->advanceThroughLogin($after);
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $after->state());
        $after->close();
        $after->close(LoginFailureCode::INPUT_LIMIT);
        self::assertSame(LoginFailureCode::CLOSED_BY_SERVER, $after->failure());
        self::assertSame([], $after->drainOutgoing());
        self::assertNull($after->takeReady());
    }

    public function testCloseAfterReadyDiscardsUnclaimedTransferAndDestructorIsSafe(): void
    {
        [$channel] = $this->channel();
        $this->advanceThroughLogin($channel);
        self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), CompressionMode::NegotiatedZlib))));
        $channel->drainOutgoing();
        self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), CompressionMode::NegotiatedZlib))));
        self::assertSame(LoginState::LOGIN_READY, $channel->state());
        $channel->close();
        self::assertSame(LoginState::CLOSED, $channel->state());
        self::assertNull($channel->takeReady());
        $weakChannel = \WeakReference::create($channel);
        unset($channel);
        gc_collect_cycles();
        self::assertNull($weakChannel->get());
    }

    public function testResourcePackChunkUsesOrderedCompressionBoundaryAndReceipt(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-login-channel-pack-');
        self::assertIsString($path);
        $bytes = random_bytes(131_072);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $pack = new ResourcePackArchive(
            '418c933d-c42a-4211-ae75-d5b572fe98fd',
            '1.0.0',
            'Fixture',
            strlen($bytes),
            hash('sha256', $bytes, true),
            '',
            '',
            $path,
        );
        try {
            [$channel] = $this->channel(resourcePacks: new ResourcePackCatalog([$pack]), compressionWorkers: new ZeroCompressionWorkers());
            $this->advanceThroughLogin($channel);
            self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackClientResponsePacket(
                ResourcePackResponseStatus::SendPacks,
                [$pack->wireId()],
            ), CompressionMode::NegotiatedZlib))));
            $channel->drainOutgoing();
            self::assertTrue($channel->accept($this->event($this->encode(new ResourcePackChunkRequestPacket(
                $pack->id,
                $pack->version,
                0,
            ), CompressionMode::NegotiatedZlib))));

            $outgoing = $channel->drainOutgoing();
            self::assertCount(1, $outgoing);
            self::assertNotNull($outgoing[0]->receiptId);
            self::assertInstanceOf(ResourcePackChunkDataPacket::class, $this->decode(
                $outgoing[0]->payload,
                CompressionMode::NegotiatedZlib,
            ));
            self::assertTrue($channel->acknowledge($outgoing[0]->receiptId));
        } finally {
            unlink($path);
        }
    }

    /** @return array{BedrockLoginChannel, P384KeyPair, PipelineClock, LoginSession} */
    private function channel(
        ?LoginChannelLimits $limits = null,
        ?LoginLimits $sessionLimits = null,
        ?ResourcePackCatalog $resourcePacks = null,
        ?CompressionWorkerDispatcher $compressionWorkers = null,
    ): array {
        $factory = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $client = $factory->generate();
        $authenticator = new PipelineAuthenticator(new AuthenticatedLogin(
            'Player',
            'identity',
            '1',
            $client->publicKey,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
        ));
        $clock = new PipelineClock();
        $session = new LoginSession(
            $clock,
            $authenticator,
            limits: $sessionLimits ?? new LoginLimits(),
            resourcePacks: $resourcePacks ?? new ResourcePackCatalog(),
        );
        return [
            new BedrockLoginChannel(
                $session,
                $limits ?? new LoginChannelLimits(),
                $compressionWorkers,
            ),
            $client,
            $clock,
            $session,
        ];
    }

    private function advanceThroughLogin(BedrockLoginChannel $channel): void
    {
        self::assertTrue($channel->accept($this->event($this->encode(new RequestNetworkSettingsPacket(), CompressionMode::Uncompressed))));
        $channel->drainOutgoing();
        self::assertTrue($channel->accept($this->event($this->encode($this->login(), CompressionMode::NegotiatedZlib))));
        self::assertCount(2, $channel->drainOutgoing());
    }

    private function event(string $payload): InboundPayload
    {
        return new InboundPayload($payload, Reliability::RELIABLE);
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

final readonly class ZeroCompressionWorkers implements CompressionWorkerDispatcher
{
    public function workerCount(): int
    {
        return 0;
    }

    public function submit(
        int $taskTypeId,
        string $payload,
        Closure $completion,
        ?int $deadlineNanoseconds = null,
    ): WorkerSubmission {
        throw new \LogicException('Zero-worker compression must use the bounded synchronous fallback.');
    }

    public function cancel(WorkerReceipt $receipt): bool
    {
        return false;
    }
}
