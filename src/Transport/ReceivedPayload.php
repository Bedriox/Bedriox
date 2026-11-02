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

namespace Bedriox\Server\Transport;

use Bedriox\NetherNet\Protocol\Reliability;

final readonly class ReceivedPayload
{
    public function __construct(
        public int $sessionId,
        public string $payload,
        public Reliability $reliability,
    ) {
        if ($sessionId < 0) {
            throw new \InvalidArgumentException('Transport session ID must be nonnegative.');
        }
        if ($payload === '') {
            throw new \InvalidArgumentException('Received transport payload must not be empty.');
        }
    }
}
