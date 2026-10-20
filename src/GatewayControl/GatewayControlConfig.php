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

use InvalidArgumentException;

final readonly class GatewayControlConfig
{
    public function __construct(
        #[\SensitiveParameter]
        public string $secret,
        public string $issuer,
        public string $audience,
        public string $keyId = 'primary',
        public int $maximumHandshakeAgeSeconds = 30,
        public int $maximumSessions = 16,
    ) {
        if (strlen($secret) < 32 || strlen($secret) > 1_024) {
            throw new InvalidArgumentException('Gateway control secret must contain between 32 and 1024 bytes.');
        }
        foreach (['issuer' => $issuer, 'audience' => $audience, 'key ID' => $keyId] as $name => $value) {
            if ($value === '' || strlen($value) > 128 || preg_match('/\A[\x21-\x7e]+\z/D', $value) !== 1) {
                throw new InvalidArgumentException("Gateway control {$name} is invalid.");
            }
        }
        if ($maximumHandshakeAgeSeconds < 1 || $maximumHandshakeAgeSeconds > 60
            || $maximumSessions < 1 || $maximumSessions > 256) {
            throw new InvalidArgumentException('Gateway control limits are outside their supported range.');
        }
    }
}
