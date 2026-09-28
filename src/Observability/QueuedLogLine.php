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

namespace Bedriox\Server\Observability;

use InvalidArgumentException;

/** Already formatted and redacted before it crosses the background-writer boundary. */
final readonly class QueuedLogLine
{
    public function __construct(
        public int $sequence,
        public LogLevel $level,
        public string $line,
    ) {
        if ($sequence < 1) {
            throw new InvalidArgumentException('Log sequence must be positive.');
        }
    }

    public function bytes(): int
    {
        return strlen($this->line) + strlen(PHP_EOL);
    }
}
