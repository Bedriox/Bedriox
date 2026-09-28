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

use Bedriox\Protocol\Batch\BatchLimits;
use InvalidArgumentException;

final readonly class LoginChannelLimits
{
    public function __construct(
        public BatchLimits $batch = new BatchLimits(),
        public int $maximumQueuedPayloads = 32,
        public int $maximumQueuedPayloadBytes = 4_194_304,
    ) {
        if ($maximumQueuedPayloads < 1 || $maximumQueuedPayloadBytes < 1) {
            throw new InvalidArgumentException('Login channel limits must be positive.');
        }
    }
}
