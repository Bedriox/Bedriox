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

/** One aggregate ceiling for rebuildable caches across every loaded world. */
final readonly class RuntimeCacheBudget
{
    public function __construct(public int $maximumBytes)
    {
        if ($maximumBytes < 8_388_608 || $maximumBytes > 536_870_912) {
            throw new InvalidArgumentException('Runtime cache budget must be between 8 MiB and 512 MiB.');
        }
    }

    public static function fromMemoryLimit(int $memoryLimitBytes): self
    {
        if ($memoryLimitBytes < 0) {
            throw new InvalidArgumentException('Memory limit cannot be negative.');
        }
        $effectiveLimit = $memoryLimitBytes === 0 ? 536_870_912 : $memoryLimitBytes;

        return new self(min(134_217_728, max(16_777_216, intdiv($effectiveLimit, 4))));
    }

    /**
     * @param list<DisposableByteCache|DisposableCacheAllocation> $caches
     * @return array{retained_before: int, retained_after: int, released: int}
     */
    public function enforce(array $caches): array
    {
        $allocations = array_map(
            static fn(DisposableByteCache|DisposableCacheAllocation $cache): DisposableCacheAllocation =>
                $cache instanceof DisposableCacheAllocation ? $cache : new DisposableCacheAllocation($cache),
            $caches,
        );
        usort(
            $allocations,
            static fn(DisposableCacheAllocation $left, DisposableCacheAllocation $right): int =>
                ($left->priority <=> $right->priority)
                ?: ($right->cache->retainedBytes() <=> $left->cache->retainedBytes()),
        );
        $before = array_sum(array_map(
            static fn(DisposableCacheAllocation $allocation): int => $allocation->cache->retainedBytes(),
            $allocations,
        ));
        $excess = max(0, $before - $this->maximumBytes);
        $released = 0;
        foreach ($allocations as $allocation) {
            if ($excess === 0) {
                break;
            }
            $cache = $allocation->cache;
            $retained = $cache->retainedBytes();
            $trimmed = $cache->trimToBytes(max(0, $retained - $excess));
            $released += $trimmed;
            $excess = max(0, $excess - $trimmed);
        }

        return [
            'retained_before' => $before,
            'retained_after' => max(0, $before - $released),
            'released' => $released,
        ];
    }
}
