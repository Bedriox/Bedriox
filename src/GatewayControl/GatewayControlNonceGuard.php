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

namespace Bedriox\Server\GatewayControl;

final class GatewayControlNonceGuard
{
    /** @var array<string, int> */
    private array $nonces = [];

    public function __construct(private readonly int $maximumEntries = 4_096) {}

    public function accept(string $nonce, int $expiresAt, int $now): bool
    {
        foreach ($this->nonces as $known => $expiry) {
            if ($expiry < $now) {
                unset($this->nonces[$known]);
            }
        }
        $key = hash('sha256', $nonce);
        if (isset($this->nonces[$key]) || count($this->nonces) >= $this->maximumEntries) {
            return false;
        }
        $this->nonces[$key] = $expiresAt;

        return true;
    }
}
