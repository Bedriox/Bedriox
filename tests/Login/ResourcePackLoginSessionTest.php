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

use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkDataPacket;
use Bedriox\Protocol\Packet\ResourcePackChunkRequestPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackDataInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ResourcePacksInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackStackPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginFailureCode;
use Bedriox\Server\Login\LoginInput;
use Bedriox\Server\Login\LoginReadyEffect;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\LoginState;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\Login\SendPacketEffect;
use Bedriox\Server\ResourcePack\ResourcePackArchive;
use Bedriox\Server\ResourcePack\ResourcePackCatalog;
use Bedriox\Server\ResourcePack\ResourcePackChunkCache;
use Bedriox\Server\ResourcePack\ResourcePackTransferGovernor;
use PHPUnit\Framework\TestCase;

final class ResourcePackLoginSessionTest extends TestCase
{
    /** @var list<string> */
    private array $temporaryFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->temporaryFiles as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function testRequiredPackCompletesMetadataChunkAndStackConversation(): void
    {
        $bytes = random_bytes(ResourcePackArchive::CHUNK_BYTES + 31);
        $pack = $this->pack($bytes);
        $session = $this->throughAuthentication(new ResourcePackCatalog([$pack], true));
        $effects = $session->drainEffects();
        self::assertCount(2, $effects);
        $info = self::packet($effects[1]);
        self::assertInstanceOf(ResourcePacksInfoPacket::class, $info);
        self::assertTrue($info->forcedToAccept);
        self::assertCount(1, $info->resourcePacks);
        self::assertSame($pack->id, $info->resourcePacks[0]->packId);
        self::assertSame($pack->size, $info->resourcePacks[0]->packSize);

        $this->send($session, new ResourcePackClientResponsePacket(
            ResourcePackResponseStatus::SendPacks,
            [$pack->wireId()],
        ));
        $metadata = self::packet($session->drainEffects()[0]);
        self::assertInstanceOf(ResourcePackDataInfoPacket::class, $metadata);
        self::assertSame(ResourcePackArchive::CHUNK_BYTES, $metadata->maximumChunkBytes);
        self::assertSame(2, $metadata->chunkCount);
        self::assertSame(hash('sha256', $bytes, true), $metadata->sha256);

        $this->send($session, new ResourcePackChunkRequestPacket($pack->id, $pack->version, 0));
        $first = self::packet($session->drainEffects()[0]);
        self::assertInstanceOf(ResourcePackChunkDataPacket::class, $first);
        self::assertSame(0, $first->progress);
        self::assertSame(substr($bytes, 0, ResourcePackArchive::CHUNK_BYTES), $first->data);
        $session->acknowledgePackChunk();

        $this->send($session, new ResourcePackChunkRequestPacket($pack->id, $pack->version, 1));
        $second = self::packet($session->drainEffects()[0]);
        self::assertInstanceOf(ResourcePackChunkDataPacket::class, $second);
        self::assertSame(ResourcePackArchive::CHUNK_BYTES, $second->progress);
        self::assertSame(substr($bytes, ResourcePackArchive::CHUNK_BYTES), $second->data);
        $session->acknowledgePackChunk();

        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []));
        $stack = self::packet($session->drainEffects()[0]);
        self::assertInstanceOf(ResourcePackStackPacket::class, $stack);
        self::assertTrue($stack->forcedToAccept);
        self::assertSame($pack->id, $stack->resourcePacks[0]->packId);

        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []));
        self::assertSame(LoginState::LOGIN_READY, $session->state());
        self::assertInstanceOf(LoginReadyEffect::class, $session->drainEffects()[0]);
    }

    public function testOptionalPackIsAdvertisedWithoutForcedAcceptance(): void
    {
        $pack = $this->pack('optional');
        $session = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));

        $info = self::packet($session->drainEffects()[1]);

        self::assertInstanceOf(ResourcePacksInfoPacket::class, $info);
        self::assertFalse($info->forcedToAccept);
    }

    public function testRefusedPackClosesSessionWithoutReadyEffect(): void
    {
        $session = $this->throughAuthentication(new ResourcePackCatalog([$this->pack('refused')], true));
        $session->drainEffects();

        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Refused, []));

        self::assertSame(LoginState::CLOSED, $session->state());
        self::assertSame(LoginFailureCode::RESOURCE_PACK_PROBLEM, $session->failure());
        self::assertSame([], $session->drainEffects());
    }

    public function testUnknownOrOutOfRangeChunkRequestFailsClosed(): void
    {
        $pack = $this->pack('one chunk');
        $unknown = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));
        $unknown->drainEffects();
        $this->send($unknown, new ResourcePackChunkRequestPacket($pack->id, $pack->version, 0));
        self::assertSame(LoginFailureCode::RESOURCE_PACK_PROBLEM, $unknown->failure());

        $outside = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));
        $outside->drainEffects();
        $this->send($outside, new ResourcePackClientResponsePacket(
            ResourcePackResponseStatus::SendPacks,
            [$pack->wireId()],
        ));
        $outside->drainEffects();
        $this->send($outside, new ResourcePackChunkRequestPacket($pack->id, $pack->version, 1));
        self::assertSame(LoginFailureCode::RESOURCE_PACK_PROBLEM, $outside->failure());
    }

    public function testDuplicateMetadataFailsClosedAndDuplicateChunkIsIdempotent(): void
    {
        $pack = $this->pack('duplicate');
        $metadata = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));
        $metadata->drainEffects();
        $request = new ResourcePackClientResponsePacket(ResourcePackResponseStatus::SendPacks, [$pack->wireId()]);
        $this->send($metadata, $request);
        $metadata->drainEffects();
        $this->send($metadata, $request);
        self::assertSame(LoginFailureCode::RESOURCE_PACK_PROBLEM, $metadata->failure());

        $chunk = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));
        $chunk->drainEffects();
        $this->send($chunk, $request);
        $chunk->drainEffects();
        $chunkRequest = new ResourcePackChunkRequestPacket($pack->id, $pack->version, 0);
        $this->send($chunk, $chunkRequest);
        $chunk->drainEffects();
        $this->send($chunk, $chunkRequest);
        self::assertNull($chunk->failure());
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $chunk->state());
    }

    public function testRetailChunkBurstIsBufferedAndServedInBoundedSlices(): void
    {
        $chunkCount = 40;
        $pack = $this->pack(str_repeat('x', ResourcePackArchive::CHUNK_BYTES * $chunkCount));
        $session = $this->throughAuthentication(new ResourcePackCatalog([$pack], false));
        $session->drainEffects();
        $this->send($session, new ResourcePackClientResponsePacket(
            ResourcePackResponseStatus::SendPacks,
            [$pack->wireId()],
        ));
        $session->drainEffects();

        for ($index = 0; $index < $chunkCount; ++$index) {
            self::assertTrue($session->enqueue(new LoginInput(
                new ResourcePackChunkRequestPacket($pack->id, $pack->version, $index),
                64,
                false,
            )));
        }

        $served = 0;
        while ($served < $chunkCount) {
            $session->tick();
            while (($effects = $session->drainEffects()) !== []) {
                self::assertCount(1, $effects);
                self::assertInstanceOf(ResourcePackChunkDataPacket::class, self::packet($effects[0]));
                ++$served;
                $session->acknowledgePackChunk();
            }
        }

        self::assertSame($chunkCount, $served);
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $session->state());
        self::assertNull($session->failure());
    }

    public function testOneHundredJoiningPlayersProgressFairlyThroughSharedBudget(): void
    {
        $pack = $this->pack(str_repeat('p', ResourcePackArchive::CHUNK_BYTES));
        $catalog = new ResourcePackCatalog([$pack], false);
        $governor = new ResourcePackTransferGovernor(16, 1_073_741_824, 134_217_728, 1_000_000);
        $cache = new ResourcePackChunkCache();
        $sessions = [];
        foreach (range(1, 100) as $_) {
            $session = $this->throughAuthentication($catalog, $governor, $cache);
            $session->drainEffects();
            $this->send($session, new ResourcePackClientResponsePacket(
                ResourcePackResponseStatus::SendPacks,
                [$pack->wireId()],
            ));
            $session->drainEffects();
            $this->send($session, new ResourcePackChunkRequestPacket($pack->id, $pack->version, 0));
            $sessions[] = $session;
        }

        $served = 0;
        for ($pass = 0; $pass < 100 && $served < 100; ++$pass) {
            foreach ($sessions as $session) {
                $session->tick();
                $effects = $session->drainEffects();
                if ($effects === []) {
                    continue;
                }
                self::assertCount(1, $effects);
                self::assertInstanceOf(ResourcePackChunkDataPacket::class, self::packet($effects[0]));
                ++$served;
                $session->acknowledgePackChunk();
            }
        }

        self::assertSame(100, $served);
        self::assertSame(0, $governor->snapshot()['in_flight']);
        self::assertSame(0, $governor->snapshot()['waiting']);
    }

    private function throughAuthentication(
        ResourcePackCatalog $catalog,
        ?ResourcePackTransferGovernor $governor = null,
        ?ResourcePackChunkCache $cache = null,
    ): LoginSession {
        $clock = new ResourcePackFakeClock();
        $keys = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate();
        $authenticated = new AuthenticatedLogin(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            '1',
            $keys->publicKey,
            new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []),
        );
        $session = new LoginSession(
            $clock,
            new ResourcePackFakeAuthenticator($authenticated),
            AuthenticationMode::FULL,
            resourcePacks: $catalog,
            resourcePackGovernor: $governor,
            resourcePackChunkCache: $cache,
        );
        $this->send($session, new RequestNetworkSettingsPacket());
        $session->drainEffects();
        $this->send($session, new LoginPacket(
            ProtocolVersion::CURRENT,
            new LoginAuthentication(AuthenticationType::Full, 'token'),
            'client',
        ));
        return $session;
    }

    private function pack(string $bytes): ResourcePackArchive
    {
        $path = tempnam(sys_get_temp_dir(), 'bedriox-resource-pack-login-');
        self::assertIsString($path);
        self::assertSame(strlen($bytes), file_put_contents($path, $bytes));
        $this->temporaryFiles[] = $path;
        return new ResourcePackArchive(
            'b8f70970-6f5f-4e58-bdf8-21a6f65ef001',
            '1.2.3',
            'Fixture',
            strlen($bytes),
            hash('sha256', $bytes, true),
            '',
            '',
            $path,
        );
    }

    private function send(LoginSession $session, Packet $packet): void
    {
        self::assertTrue($session->enqueue(new LoginInput($packet, max(1, strlen($packet->encode())), false)));
        $session->tick();
    }

    private static function packet(object $effect): Packet
    {
        self::assertInstanceOf(SendPacketEffect::class, $effect);
        return $effect->packet;
    }
}

final class ResourcePackFakeClock implements MonotonicClock
{
    public function nowNanoseconds(): int
    {
        return 1_000_000;
    }
}

final readonly class ResourcePackFakeAuthenticator implements LoginAuthenticator
{
    public function __construct(private AuthenticatedLogin $login) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        return $this->login;
    }
}
