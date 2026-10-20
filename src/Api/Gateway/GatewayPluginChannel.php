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

/** One plugin-owned, bounded bidirectional Gateway messaging channel. */
final readonly class GatewayPluginChannel
{
    public const int MAXIMUM_PAYLOAD_BYTES = 24_000;

    /**
     * @param Closure(): bool $registered
     * @param Closure(string): bool $send
     * @internal The server owns channel construction.
     */
    public function __construct(
        private string $name,
        private Closure $registered,
        private Closure $send,
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function isRegistered(): bool
    {
        return ($this->registered)();
    }

    /** Returns true when the message was admitted to at least one authenticated Gateway link. */
    public function send(string $payload): bool
    {
        return ($this->send)($payload);
    }

    /** @internal Used by detached plugin contexts. */
    public static function unavailable(string $name): self
    {
        return new self($name, static fn(): bool => false, static fn(string $payload): bool => false);
    }
}
