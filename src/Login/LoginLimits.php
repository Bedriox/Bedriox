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

use InvalidArgumentException;

final readonly class LoginLimits
{
    public function __construct(
        public int $maximumQueuedPackets = 32,
        public int $maximumQueuedInputBytes = 1_048_576,
        public int $maximumPacketBytes = 1_048_576,
        public int $maximumQueuedEffects = 32,
    ) {
        if ($maximumQueuedPackets < 1 || $maximumQueuedInputBytes < 1 || $maximumPacketBytes < 1 || $maximumQueuedEffects < 1) {
            throw new InvalidArgumentException('Login limits must be positive.');
        }
    }
}
