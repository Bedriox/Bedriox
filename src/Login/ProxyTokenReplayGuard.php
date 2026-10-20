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

use Bedriox\Server\Authentication\AuthenticationException;

final class ProxyTokenReplayGuard
{
    /** @var array<string, int> */
    private array $tokens = [];

    public function __construct(private readonly int $maximumTokens = 65_536) {}

    public function accept(string $tokenId, int $expiresAt, int $now): void
    {
        foreach ($this->tokens as $id => $expiry) {
            if ($expiry < $now) {
                unset($this->tokens[$id]);
            }
        }
        if (isset($this->tokens[$tokenId])) {
            throw new AuthenticationException('Gateway forwarding token was replayed.');
        }
        if (count($this->tokens) >= $this->maximumTokens) {
            throw new AuthenticationException('Gateway forwarding replay cache is full.');
        }
        $this->tokens[$tokenId] = $expiresAt;
    }
}
