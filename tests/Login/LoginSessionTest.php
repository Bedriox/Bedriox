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
use Bedriox\Protocol\Packet\ClientCacheStatusPacket;
use Bedriox\Protocol\Packet\ClientToServerHandshakePacket;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Packet\NetworkSettingsPacket;
use Bedriox\Protocol\Packet\PlayStatusPacket;
use Bedriox\Protocol\Packet\RequestNetworkSettingsPacket;
use Bedriox\Protocol\Packet\ResourcePackClientResponsePacket;
use Bedriox\Protocol\Packet\ResourcePackResponseStatus;
use Bedriox\Protocol\Packet\ResourcePacksInfoPacket;
use Bedriox\Protocol\Packet\ResourcePackStackPacket;
use Bedriox\Protocol\Packet\ServerToClientHandshakePacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\EnableEncryptionEffect;
use Bedriox\Server\Login\HandshakeMaterial;
use Bedriox\Server\Login\HandshakeMaterialFactory;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginFailureCode;
use Bedriox\Server\Login\LoginInput;
use Bedriox\Server\Login\LoginLimits;
use Bedriox\Server\Login\LoginReadyEffect;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\LoginState;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\Login\SendPacketEffect;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use PHPUnit\Framework\TestCase;

final class LoginSessionTest extends TestCase
{
    public function testCompleteEmptyPackLoginAndEncryptionBoundary(): void
    {
        [$session, $clock] = $this->session();
        self::assertSame(LoginState::WAIT_NETWORK_REQUEST, $session->state());

        $this->send($session, new RequestNetworkSettingsPacket());
        $effects = $session->drainEffects();
        self::assertCount(1, $effects);
        self::assertInstanceOf(NetworkSettingsPacket::class, self::packetEffect($effects[0])->packet);
        self::assertSame(
            NetworkCompressionPolicy::THRESHOLD_BYTES,
            self::packetEffect($effects[0])->packet->compressionThreshold,
        );
        self::assertFalse(self::packetEffect($effects[0])->encrypted);

        $clock->advanceSeconds(1);
        $this->send($session, $this->login(AuthenticationType::Full));
        $effects = $session->drainEffects();
        self::assertCount(2, $effects);
        self::assertInstanceOf(ServerToClientHandshakePacket::class, self::packetEffect($effects[0])->packet);
        self::assertFalse(self::packetEffect($effects[0])->encrypted);
        self::assertInstanceOf(EnableEncryptionEffect::class, $effects[1]);

        $this->send($session, new ClientToServerHandshakePacket());
        $effects = $session->drainEffects();
        self::assertInstanceOf(PlayStatusPacket::class, self::packetEffect($effects[0])->packet);
        self::assertTrue(self::packetEffect($effects[0])->encrypted);
        self::assertInstanceOf(ResourcePacksInfoPacket::class, self::packetEffect($effects[1])->packet);

        $this->send($session, new ClientCacheStatusPacket(true));
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $session->state());
        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []));
        $effects = $session->drainEffects();
        self::assertInstanceOf(ResourcePackStackPacket::class, self::packetEffect($effects[0])->packet);
        $stack = self::packetEffect($effects[0])->packet;
        self::assertInstanceOf(ResourcePackStackPacket::class, $stack);
        self::assertSame([], $stack->resourcePacks);

        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []));
        self::assertSame(LoginState::LOGIN_READY, $session->state());
        self::assertInstanceOf(LoginReadyEffect::class, $session->drainEffects()[0]);
        $clock->advanceSeconds(100);
        $session->tick();
        self::assertSame(LoginState::LOGIN_READY, $session->state());
    }

    public function testProtocolVersionIsCheckedInBothPackets(): void
    {
        [$first] = $this->session();
        $this->send($first, new RequestNetworkSettingsPacket(974));
        self::assertSame(LoginFailureCode::UNSUPPORTED_PROTOCOL, $first->failure());
        self::assertSame(974, $first->observedProtocolVersion());

        [$second] = $this->session();
        $this->send($second, new RequestNetworkSettingsPacket());
        $second->drainEffects();
        $this->send($second, new LoginPacket(974, new LoginAuthentication(AuthenticationType::Full, 'token'), 'client'));
        self::assertSame(LoginFailureCode::UNSUPPORTED_PROTOCOL, $second->failure());
        self::assertSame(ProtocolVersion::CURRENT, $second->observedProtocolVersion());

        [$legacy] = $this->session();
        $formerProtocol = ProtocolVersion::CURRENT - 24;
        $this->send($legacy, new RequestNetworkSettingsPacket($formerProtocol));
        self::assertSame(LoginFailureCode::UNSUPPORTED_PROTOCOL, $legacy->failure());
    }

    public function testDuplicateClientCacheStatusFailsClosed(): void
    {
        [$session] = $this->throughHandshake();
        $this->send($session, new ClientCacheStatusPacket(true));
        self::assertSame(LoginState::WAIT_PACK_INFO_RESPONSE, $session->state());
        $this->send($session, new ClientCacheStatusPacket(false));
        self::assertSame(LoginFailureCode::UNEXPECTED_PACKET, $session->failure());
    }

    public function testStrictOrderAndPackStatuses(): void
    {
        [$session] = $this->session();
        $this->send($session, $this->login(AuthenticationType::Full));
        self::assertSame(LoginFailureCode::UNEXPECTED_PACKET, $session->failure());

        [$session] = $this->throughHandshake();
        $this->send($session, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []));
        self::assertSame(LoginFailureCode::INVALID_PACK_RESPONSE, $session->failure());
    }

    public function testAuthenticationModeIsExplicitAndHasNoFallback(): void
    {
        [$full, , $auth] = $this->session();
        $this->send($full, new RequestNetworkSettingsPacket());
        $full->drainEffects();
        $this->send($full, $this->login(AuthenticationType::SelfSigned));
        self::assertSame(LoginFailureCode::AUTHENTICATION_MODE, $full->failure());
        self::assertSame(0, $auth->calls);

        [$selfSigned, , $auth] = $this->session(AuthenticationMode::SELF_SIGNED);
        $this->send($selfSigned, new RequestNetworkSettingsPacket());
        $selfSigned->drainEffects();
        $this->send($selfSigned, $this->login(AuthenticationType::SelfSigned));
        self::assertSame(1, $auth->calls);
        self::assertSame(LoginState::WAIT_CLIENT_HANDSHAKE, $selfSigned->state());
        self::assertFalse($full->securityPosture()->requiresOperatorWarning());
        self::assertNull($full->securityPosture()->operatorNotice());
        self::assertTrue($selfSigned->securityPosture()->requiresOperatorWarning());
        self::assertSame(
            'SELF_SIGNED login authentication is insecure and must not be used for public servers.',
            $selfSigned->securityPosture()->operatorNotice(),
        );
    }

    public function testDevelopmentModeAlsoAdmitsFullRetailAuthentication(): void
    {
        [$session, , $auth] = $this->session(AuthenticationMode::SELF_SIGNED);
        $this->send($session, new RequestNetworkSettingsPacket());
        $session->drainEffects();

        $this->send($session, $this->login(AuthenticationType::Full));

        self::assertSame(1, $auth->calls);
        self::assertSame(LoginState::WAIT_CLIENT_HANDSHAKE, $session->state());
    }

    public function testStateDeadlineAndClockRegressionFailClosed(): void
    {
        [$session, $clock] = $this->session();
        $clock->advanceSeconds(15);
        $session->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $session->failure());

        [$session, $clock] = $this->session();
        $clock->nanoseconds--;
        $session->tick();
        self::assertSame(LoginFailureCode::CLOCK_FAILURE, $session->failure());
    }

    public function testEveryRelativeDeadlineAndAbsoluteDeadlineFailClosedAtBoundary(): void
    {
        [$waitLogin, $clock] = $this->session();
        $this->send($waitLogin, new RequestNetworkSettingsPacket());
        $clock->advanceSeconds(20);
        $waitLogin->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $waitLogin->failure());

        [$waitHandshake, $clock] = $this->session();
        $this->send($waitHandshake, new RequestNetworkSettingsPacket());
        $this->send($waitHandshake, $this->login(AuthenticationType::Full));
        $clock->advanceSeconds(15);
        $waitHandshake->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $waitHandshake->failure());

        [$waitInfo, $clock] = $this->throughHandshake();
        $clock->advanceSeconds(15);
        $waitInfo->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $waitInfo->failure());

        [$waitStack, $clock] = $this->throughHandshake();
        $this->send($waitStack, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []));
        $clock->advanceSeconds(15);
        $waitStack->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $waitStack->failure());

        [$absolute, $clock] = $this->session();
        $clock->advanceSeconds(14);
        $this->send($absolute, new RequestNetworkSettingsPacket());
        $clock->advanceSeconds(19);
        $this->send($absolute, $this->login(AuthenticationType::Full));
        $clock->advanceSeconds(14);
        $this->send($absolute, new ClientToServerHandshakePacket());
        $clock->advanceSeconds(13);
        $absolute->tick();
        self::assertSame(LoginFailureCode::TIMEOUT, $absolute->failure());
    }

    public function testAuthenticationWorkCannotAdvanceAfterDeadlineExpires(): void
    {
        $clock = new FakeClock();
        $keyFactory = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $client = $keyFactory->generate();
        $login = new AuthenticatedLogin('Player', 'identity', '1', $client->publicKey, self::clientData());
        $authenticator = new AdvancingAuthenticator($login, $clock, 20);
        $handshakes = new FixedHandshakeFactory(new HandshakeMaterial($keyFactory->generate(), str_repeat("\x5a", 16)));
        $session = new LoginSession($clock, $authenticator, $handshakes);
        $this->send($session, new RequestNetworkSettingsPacket());
        $session->drainEffects();
        $this->send($session, $this->login(AuthenticationType::Full));
        self::assertSame(LoginState::CLOSED, $session->state());
        self::assertSame(LoginFailureCode::TIMEOUT, $session->failure());
        self::assertSame([], $session->drainEffects());
        self::assertSame(0, $handshakes->calls);
    }

    public function testQueuedInputRechecksDeadlineBeforeEachPacket(): void
    {
        $clock = new SequenceClock([1_000_000, 1_000_000, 15_001_000_000]);
        $keyFactory = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $keys = $keyFactory->generate();
        $session = new LoginSession(
            $clock,
            new FakeAuthenticator(new AuthenticatedLogin('Player', 'identity', '1', $keys->publicKey, self::clientData())),
            new FixedHandshakeFactory(new HandshakeMaterial($keyFactory->generate(), str_repeat("\x5a", 16))),
        );
        self::assertTrue($session->enqueue(new LoginInput(new RequestNetworkSettingsPacket(), 1, false)));

        $session->tick();

        self::assertSame(LoginFailureCode::TIMEOUT, $session->failure());
        self::assertSame([], $session->drainEffects());
    }

    public function testEncryptionProvenanceIsRequiredAtEveryBoundary(): void
    {
        [$initial] = $this->session();
        $this->sendWithProtection($initial, new RequestNetworkSettingsPacket(), true);
        self::assertSame(LoginFailureCode::ENCRYPTION_STATE, $initial->failure());

        [$login] = $this->session();
        $this->send($login, new RequestNetworkSettingsPacket());
        $this->sendWithProtection($login, $this->login(AuthenticationType::Full), true);
        self::assertSame(LoginFailureCode::ENCRYPTION_STATE, $login->failure());

        [$handshake] = $this->session();
        $this->send($handshake, new RequestNetworkSettingsPacket());
        $this->send($handshake, $this->login(AuthenticationType::Full));
        $this->sendWithProtection($handshake, new ClientToServerHandshakePacket(), false);
        self::assertSame(LoginFailureCode::ENCRYPTION_STATE, $handshake->failure());

        [$packs] = $this->throughHandshake();
        $this->sendWithProtection($packs, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), false);
        self::assertSame(LoginFailureCode::ENCRYPTION_STATE, $packs->failure());

        [$stack] = $this->throughHandshake();
        $this->send($stack, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []));
        $this->sendWithProtection($stack, new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), false);
        self::assertSame(LoginFailureCode::ENCRYPTION_STATE, $stack->failure());
    }

    public function testDuplicatePacketsAreRejectedByState(): void
    {
        [$session] = $this->session();
        $this->send($session, new RequestNetworkSettingsPacket());
        $this->sendWithProtection($session, new RequestNetworkSettingsPacket(), false);
        self::assertSame(LoginFailureCode::UNEXPECTED_PACKET, $session->failure());
    }

    public function testInputAndEffectLimitsCloseAndCleanQueues(): void
    {
        [$input] = $this->session(limits: new LoginLimits(1, 4, 4, 32));
        self::assertFalse($input->enqueue(new LoginInput(new RequestNetworkSettingsPacket(), 5, false)));
        self::assertSame(LoginFailureCode::INPUT_LIMIT, $input->failure());

        [$effect] = $this->session(limits: new LoginLimits(maximumQueuedEffects: 1));
        $this->send($effect, new RequestNetworkSettingsPacket());
        $this->send($effect, $this->login(AuthenticationType::Full));
        self::assertSame(LoginFailureCode::EFFECT_LIMIT, $effect->failure());
        self::assertSame([], $effect->drainEffects());
    }

    public function testCloseClearsSecretsQueuesAndIsTerminal(): void
    {
        [$session] = $this->session();
        self::assertTrue($session->enqueue(new LoginInput(new RequestNetworkSettingsPacket(), 1, false)));
        $session->close();
        self::assertSame(LoginState::CLOSED, $session->state());
        self::assertSame([], $session->drainEffects());
        self::assertFalse($session->enqueue(new LoginInput(new RequestNetworkSettingsPacket(), 1, false)));
    }

    public function testReadyTransitionDiscardsTrailingPreLoginInput(): void
    {
        [$session] = $this->session();
        $trailing = $this->login(AuthenticationType::Full);
        $weakTrailing = \WeakReference::create($trailing);

        foreach ([
            new LoginInput(new RequestNetworkSettingsPacket(), 1, false),
            new LoginInput($this->login(AuthenticationType::Full), 1, false),
            new LoginInput(new ClientToServerHandshakePacket(), 1, true),
            new LoginInput(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::HaveAllPacks, []), 1, true),
            new LoginInput(new ResourcePackClientResponsePacket(ResourcePackResponseStatus::Completed, []), 1, true),
            new LoginInput($trailing, 1, true),
        ] as $input) {
            self::assertTrue($session->enqueue($input));
        }
        unset($input, $trailing);

        $session->tick();
        gc_collect_cycles();

        self::assertSame(LoginState::LOGIN_READY, $session->state());
        self::assertNull($weakTrailing->get());
        self::assertFalse($session->enqueue(new LoginInput(new RequestNetworkSettingsPacket(), 1, false)));
    }

    /** @return array{LoginSession, FakeClock, FakeAuthenticator} */
    private function session(AuthenticationMode $mode = AuthenticationMode::FULL, ?LoginLimits $limits = null): array
    {
        $clock = new FakeClock();
        $keyFactory = new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf');
        $keys = $keyFactory->generate();
        $auth = new FakeAuthenticator(new AuthenticatedLogin('Player', '00000000-0000-0000-0000-000000000001', '1', $keys->publicKey, self::clientData()));
        $factory = new FixedHandshakeFactory(new HandshakeMaterial($keyFactory->generate(), str_repeat("\x5a", 16)));
        return [new LoginSession($clock, $auth, $factory, $mode, $limits ?? new LoginLimits()), $clock, $auth];
    }

    /** @return array{LoginSession, FakeClock, FakeAuthenticator} */
    private function throughHandshake(): array
    {
        $tuple = $this->session();
        $this->send($tuple[0], new RequestNetworkSettingsPacket());
        $tuple[0]->drainEffects();
        $this->send($tuple[0], $this->login(AuthenticationType::Full));
        $tuple[0]->drainEffects();
        $this->send($tuple[0], new ClientToServerHandshakePacket());
        $tuple[0]->drainEffects();
        return $tuple;
    }

    private function login(AuthenticationType $type, int $protocolVersion = ProtocolVersion::CURRENT): LoginPacket
    {
        return new LoginPacket($protocolVersion, new LoginAuthentication($type, 'token'), 'client');
    }

    private function send(LoginSession $session, \Bedriox\Protocol\Packet\Packet $packet): void
    {
        $encrypted = !($packet instanceof RequestNetworkSettingsPacket || $packet instanceof LoginPacket);
        $this->sendWithProtection($session, $packet, $encrypted);
    }

    private function sendWithProtection(LoginSession $session, \Bedriox\Protocol\Packet\Packet $packet, bool $encrypted): void
    {
        self::assertTrue($session->enqueue(new LoginInput($packet, max(1, strlen($packet->encode())), $encrypted)));
        $session->tick();
    }

    private static function packetEffect(object $effect): SendPacketEffect
    {
        self::assertInstanceOf(SendPacketEffect::class, $effect);
        return $effect;
    }

    private static function clientData(): VerifiedClientData
    {
        return new VerifiedClientData(1, 1, "\0\0\0\0", 0, 0, '', '{}', []);
    }
}

final class FakeClock implements MonotonicClock
{
    public int $nanoseconds = 1_000_000;

    public function nowNanoseconds(): int
    {
        return $this->nanoseconds;
    }

    public function advanceSeconds(int $seconds): void
    {
        $this->nanoseconds += $seconds * 1_000_000_000;
    }
}

final class SequenceClock implements MonotonicClock
{
    /** @param list<int> $values */
    public function __construct(private array $values) {}

    public function nowNanoseconds(): int
    {
        return array_shift($this->values) ?? throw new \LogicException('Sequence clock was exhausted.');
    }
}

final class FakeAuthenticator implements LoginAuthenticator
{
    public int $calls = 0;

    public function __construct(private readonly AuthenticatedLogin $result) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        ++$this->calls;
        return $this->result;
    }
}

final readonly class AdvancingAuthenticator implements LoginAuthenticator
{
    public function __construct(
        private AuthenticatedLogin $result,
        private FakeClock $clock,
        private int $seconds,
    ) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        $this->clock->advanceSeconds($this->seconds);
        return $this->result;
    }
}

final class FixedHandshakeFactory implements HandshakeMaterialFactory
{
    public int $calls = 0;

    public function __construct(private readonly HandshakeMaterial $material) {}

    public function create(): HandshakeMaterial
    {
        ++$this->calls;
        return $this->material;
    }
}
