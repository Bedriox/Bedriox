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

namespace Bedriox\Api\Player;

use InvalidArgumentException;

/** Title animation durations measured in 20 Hz game ticks. */
final readonly class TitleTimes
{
    public function __construct(
        public int $fadeIn,
        public int $stay,
        public int $fadeOut,
    ) {
        if ($fadeIn < 0 || $stay < 0 || $fadeOut < 0 || $fadeIn > 72_000 || $stay > 72_000 || $fadeOut > 72_000) {
            throw new InvalidArgumentException('Title times must be between 0 and 72000 ticks.');
        }
    }
}
