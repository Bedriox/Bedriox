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

use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginAuthentication;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Server\Authentication\AuthenticationException;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\LazyFullLoginAuthenticator;
use Bedriox\Server\Login\LoginAuthenticator;
use PHPUnit\Framework\TestCase;

final class LazyFullLoginAuthenticatorTest extends TestCase
{
    public function testCreatesAndReusesDelegateOnFirstFullLogin(): void
    {
        $factoryCalls = 0;
        $delegate = new ThrowingRecordingFullAuthenticator();
        $authenticator = new LazyFullLoginAuthenticator(static function () use (&$factoryCalls, $delegate): LoginAuthenticator {
            ++$factoryCalls;

            return $delegate;
        });
        $packet = new LoginPacket(
            ProtocolVersion::CURRENT,
            new LoginAuthentication(AuthenticationType::Full, token: 'token'),
            'client',
        );

        for ($attempt = 0; $attempt < 2; ++$attempt) {
            try {
                $authenticator->authenticate($packet, AuthenticationMode::FULL);
            } catch (AuthenticationException) {
                self::addToAssertionCount(1);
            }
        }

        self::assertSame(1, $factoryCalls);
        self::assertSame(2, $delegate->calls);
    }

    public function testWrongModeDoesNotInitializeDelegate(): void
    {
        $factoryCalls = 0;
        $authenticator = new LazyFullLoginAuthenticator(static function () use (&$factoryCalls): LoginAuthenticator {
            ++$factoryCalls;

            return new ThrowingRecordingFullAuthenticator();
        });
        $packet = new LoginPacket(
            ProtocolVersion::CURRENT,
            new LoginAuthentication(AuthenticationType::Full, token: 'token'),
            'client',
        );

        try {
            $authenticator->authenticate($packet, AuthenticationMode::SELF_SIGNED);
            self::fail('Wrong authentication mode was accepted.');
        } catch (AuthenticationException) {
            self::assertSame(0, $factoryCalls);
        }
    }
}

final class ThrowingRecordingFullAuthenticator implements LoginAuthenticator
{
    public int $calls = 0;

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        ++$this->calls;
        throw new AuthenticationException('Recorded test call.');
    }
}
