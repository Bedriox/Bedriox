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

use Bedriox\Server\Login\AuthenticationMode;
use Bedriox\Server\Login\BedrockLoginChannel;
use Bedriox\Server\Login\LoginAuthenticator;
use Bedriox\Server\Login\LoginChannelLimits;
use Bedriox\Server\Login\LoginLimits;
use Bedriox\Server\Login\LoginSession;
use Bedriox\Server\Login\MonotonicClock;
use Bedriox\Server\ResourcePack\ResourcePackCatalog;
use Bedriox\Server\Transport\SessionInfo;
use Closure;

final class ConfiguredLoginChannelFactory implements LoginChannelFactory
{
    /** @var Closure(): ResourcePackCatalog */
    private readonly Closure $resourcePacks;

    public function __construct(
        private readonly MonotonicClock $clock,
        private readonly LoginAuthenticator $authenticator,
        private readonly AuthenticationMode $mode,
        private readonly LoginLimits $loginLimits = new LoginLimits(),
        private readonly LoginChannelLimits $channelLimits = new LoginChannelLimits(),
        ResourcePackCatalog|Closure $resourcePacks = new ResourcePackCatalog(),
    ) {
        $this->resourcePacks = $resourcePacks instanceof Closure
            ? $resourcePacks
            : static fn(): ResourcePackCatalog => $resourcePacks;
    }

    public function create(SessionInfo $session): BedrockLoginChannel
    {
        $authenticator = $this->authenticator instanceof \Bedriox\Server\Login\PeerAwareLoginAuthenticator
            ? $this->authenticator->forPeer($session)
            : $this->authenticator;

        $resourcePacks = ($this->resourcePacks)();
        if (!$resourcePacks instanceof ResourcePackCatalog) {
            throw new \LogicException('Resource-pack snapshot provider returned an invalid catalog.');
        }

        return new BedrockLoginChannel(new LoginSession(
            $this->clock,
            $authenticator,
            $this->mode,
            $this->loginLimits,
            $resourcePacks,
        ), $this->channelLimits);
    }
}
