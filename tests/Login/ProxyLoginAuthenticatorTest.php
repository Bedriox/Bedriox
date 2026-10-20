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

use Bedriox\Protocol\Identity\ClientDataJwt;
use Bedriox\Protocol\Identity\ClientDataJwtVerifier;
use Bedriox\Protocol\Identity\GatewayForwardingClaims;
use Bedriox\Protocol\Identity\GatewayForwardingToken;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Security\P384;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Authentication\AuthenticationClock;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\ProxyLoginAuthenticator;
use Bedriox\Server\Login\ProxyTokenReplayGuard;
use PHPUnit\Framework\TestCase;

final class ProxyLoginAuthenticatorTest extends TestCase
{
    public function testTrustedGatewayForwardsVerifiedIdentityAndRejectsReplay(): void
    {
        $keys = (new OpenSslEphemeralKeyFactory(__DIR__ . '/../Fixtures/openssl.cnf'))->generate();
        $secret = str_repeat('s', 32);
        $claims = new GatewayForwardingClaims(
            'gateway',
            'lobby',
            'subject',
            '123e4567-e89b-42d3-a456-426614174000',
            'Player',
            '123',
            '203.0.113.10',
            50_000,
            2_193,
            P384::exportPublicDerBase64($keys->publicKey),
            1_000,
            999,
            1_015,
            'unique-token',
            runtimeEntityId: 424_242,
            operator: true,
        );
        $packet = new LoginPacket(
            2_193,
            new LoginAuthentication(AuthenticationType::Full, GatewayForwardingToken::issue($claims, $secret)),
            ClientDataJwt::create(new VerifiedClientData(1, 1, "\x00\x00\x00\xff", 0, 0, '', '{}', []), $keys),
        );
        $clock = new class implements AuthenticationClock {
            public function nowEpochSeconds(): int
            {
                return 1_005;
            }
        };
        $authenticator = new ProxyLoginAuthenticator(
            $secret,
            'lobby',
            'gateway',
            ['127.0.0.1/32'],
            $clock,
            new ClientDataJwtVerifier(),
            new ProxyTokenReplayGuard(),
        );
        $peer = new SessionInfo('127.0.0.1', 40_000, 1, 1_400, 11);
        $bound = $authenticator->forPeer($peer);

        $login = $bound->authenticate($packet, AuthenticationMode::GATEWAY);
        self::assertSame('Player', $login->displayName);
        self::assertSame('123e4567-e89b-42d3-a456-426614174000', $login->identity);
        self::assertSame(424_242, $login->preferredRuntimeEntityId);
        self::assertTrue($login->proxyOperator);

        $this->expectException(AuthenticationException::class);
        $bound->authenticate($packet, AuthenticationMode::GATEWAY);
    }

    public function testUntrustedGatewayAddressFailsClosed(): void
    {
        $clock = new class implements AuthenticationClock {
            public function nowEpochSeconds(): int
            {
                return 1_005;
            }
        };
        $authenticator = new ProxyLoginAuthenticator(
            str_repeat('s', 32),
            'lobby',
            'gateway',
            ['127.0.0.1/32'],
            $clock,
            new ClientDataJwtVerifier(),
            new ProxyTokenReplayGuard(),
        );
        $packet = new LoginPacket(2_193, new LoginAuthentication(AuthenticationType::Full, 'invalid'), 'invalid');

        $this->expectException(AuthenticationException::class);
        $authenticator->forPeer(new SessionInfo('192.0.2.1', 40_000, 1, 1_400, 11))
            ->authenticate($packet, AuthenticationMode::GATEWAY);
    }
}
