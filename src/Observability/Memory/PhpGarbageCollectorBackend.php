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

use RuntimeException;

final class PhpGarbageCollectorBackend implements GarbageCollectorBackend
{
    public function rootCount(): int
    {
        $status = gc_status();

        return $status['roots'];
    }

    public function collectCycles(): int
    {
        return gc_collect_cycles();
    }

    public function releaseAllocatorCaches(): int
    {
        return gc_mem_caches();
    }

    public function monotonicNanoseconds(): int
    {
        $now = hrtime(true);
        if (!is_int($now)) {
            throw new RuntimeException('Monotonic clock is unavailable.');
        }

        return $now;
    }
}
