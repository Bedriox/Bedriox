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

use Bedriox\Protocol\Packet\LoginPacket;
use Bedriox\Server\Authentication\AuthenticationException;
use Closure;

/** Defers the online key refresh until a development server receives a retail login. */
final class LazyFullLoginAuthenticator implements LoginAuthenticator
{
    private ?LoginAuthenticator $authenticator = null;

    /** @param Closure(): LoginAuthenticator $factory */
    public function __construct(private readonly Closure $factory) {}

    public function authenticate(LoginPacket $packet, AuthenticationMode $mode): AuthenticatedLogin
    {
        if ($mode !== AuthenticationMode::FULL) {
            throw new AuthenticationException('Lazy FULL authenticator cannot authenticate another mode.');
        }

        $this->authenticator ??= ($this->factory)();

        return $this->authenticator->authenticate($packet, $mode);
    }
}
