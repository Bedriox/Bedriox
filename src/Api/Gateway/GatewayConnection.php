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

namespace Bedriox\Api\Gateway;

use Closure;

/** Public owner-scoped access to the authenticated Gateway control link. */
final readonly class GatewayConnection
{
    /**
     * @param Closure(): bool $enabled
     * @param Closure(): bool $connected
     * @param Closure(string): GatewayPluginChannel $register
     * @param Closure(): list<GatewayPluginChannel> $channels
     * @internal The server owns connection construction.
     */
    public function __construct(
        private Closure $enabled,
        private Closure $connected,
        private Closure $register,
        private Closure $channels,
    ) {}

    public function isEnabled(): bool
    {
        return ($this->enabled)();
    }

    public function isConnected(): bool
    {
        return ($this->connected)();
    }

    public function registerChannel(string $name): GatewayPluginChannel
    {
        return ($this->register)($name);
    }

    /** @return list<GatewayPluginChannel> */
    public function getChannels(): array
    {
        return ($this->channels)();
    }

    /** @internal Used by detached plugin contexts. */
    public static function unavailable(): self
    {
        return new self(
            static fn(): bool => false,
            static fn(): bool => false,
            static fn(string $name): GatewayPluginChannel => GatewayPluginChannel::unavailable($name),
            static fn(): array => [],
        );
    }
}
