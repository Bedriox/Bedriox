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

namespace Bedriox\Server\Login;

use Bedriox\Protocol\Packet\AuthenticationType;
use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationException;

/** Admits verified retail identities and explicit self-signed clients in development mode. */
final readonly class DevelopmentLoginAuthenticator implements LoginAuthenticator
{
    public function __construct(
        private LoginAuthenticator $full,
        private LoginAuthenticator $selfSigned,
    ) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::SELF_SIGNED) {
            throw new AuthenticationException('Development authenticator cannot authenticate another mode.');
        }

        return match ($packet->authentication->type) {
            AuthenticationType::Full => $this->full->authenticate($packet, AuthenticationMode::FULL),
            AuthenticationType::SelfSigned => $this->selfSigned->authenticate($packet, AuthenticationMode::SELF_SIGNED),
            AuthenticationType::Guest => throw new AuthenticationException('Guest authentication is not supported.'),
        };
    }
}
