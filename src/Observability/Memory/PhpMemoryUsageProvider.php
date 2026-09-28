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

namespace Bedriox\Server\Observability\Memory;

use InvalidArgumentException;
use RuntimeException;

final readonly class PhpMemoryUsageProvider implements MemoryUsageProvider
{
    public function __construct(private int $limitBytes)
    {
        if ($limitBytes < 0) {
            throw new InvalidArgumentException('Memory limit cannot be negative.');
        }
    }

    public function snapshot(): MemorySnapshot
    {
        $used = memory_get_usage(false);
        $allocated = memory_get_usage(true);
        $peakAllocated = memory_get_peak_usage(true);
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new RuntimeException('Monotonic clock is unavailable.');
        }

        return new MemorySnapshot(
            usedBytes: $used,
            allocatedBytes: max($used, $allocated),
            peakAllocatedBytes: max($used, $allocated, $peakAllocated),
            limitBytes: $this->limitBytes,
            sampledAtNanoseconds: $now,
        );
    }
}
