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

use Bedriox\Protocol\Security\P384KeyPair;
use InvalidArgumentException;

final readonly class HandshakeMaterial
{
    public function __construct(public P384KeyPair $keyPair, public string $salt)
    {
        if (strlen($salt) !== 16) {
            throw new InvalidArgumentException('Handshake salt must contain exactly 16 bytes.');
        }
    }
}
