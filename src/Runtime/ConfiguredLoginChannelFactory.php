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

namespace Bedriox\Server\Runtime;

use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Login\HandshakeMaterialFactory;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginChannelLimits;
use Bedriox\Server\Login\LoginLimits;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\MonotonicClock;

final readonly class ConfiguredLoginChannelFactory implements LoginChannelFactory
{
    public function __construct(
        private MonotonicClock $clock,
        private LoginAuthenticator $authenticator,
        private HandshakeMaterialFactory $handshakes,
        private AuthenticationMode $mode,
        private LoginLimits $loginLimits = new LoginLimits(),
        private LoginChannelLimits $channelLimits = new LoginChannelLimits(),
    ) {}

    public function create(SessionInfo $session): BedrockLoginChannel
    {
        return new BedrockLoginChannel(new LoginSession(
            $this->clock,
            $this->authenticator,
            $this->handshakes,
            $this->mode,
            $this->loginLimits,
        ), $this->channelLimits);
    }
}
