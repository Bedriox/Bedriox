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

final readonly class GarbageCollectionReport
{
    public function __construct(
        public bool $collected,
        public bool $forced,
        public bool $allocatorCachesReleased,
        public int $rootsBefore,
        public int $rootsAfter,
        public int $cyclesCollected,
        public int $allocatorBytesReleased,
        public int $durationNanoseconds,
        public int $thresholdBefore,
        public int $thresholdAfter,
    ) {
        if ($rootsBefore < 0 || $rootsAfter < 0 || $cyclesCollected < 0 || $allocatorBytesReleased < 0
            || $durationNanoseconds < 0 || $thresholdBefore < 1 || $thresholdAfter < 1) {
            throw new InvalidArgumentException('Garbage collection report values are invalid.');
        }
        if (!$collected && ($cyclesCollected !== 0 || $allocatorBytesReleased !== 0 || $durationNanoseconds !== 0
            || $allocatorCachesReleased || $rootsBefore !== $rootsAfter)) {
            throw new InvalidArgumentException('A skipped garbage collection report cannot contain collection results.');
        }
    }
}
