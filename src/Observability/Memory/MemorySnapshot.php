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

final readonly class MemorySnapshot
{
    public function __construct(
        public int $usedBytes,
        public int $allocatedBytes,
        public int $peakAllocatedBytes,
        public int $limitBytes,
        public int $sampledAtNanoseconds,
    ) {
        if ($usedBytes < 0 || $allocatedBytes < 0 || $peakAllocatedBytes < 0 || $limitBytes < 0
            || $sampledAtNanoseconds < 0 || $usedBytes > $allocatedBytes || $allocatedBytes > $peakAllocatedBytes) {
            throw new InvalidArgumentException('Memory snapshot values are invalid.');
        }
    }

    public function utilization(): float
    {
        if ($this->limitBytes === 0) {
            return 0.0;
        }

        return $this->allocatedBytes / $this->limitBytes;
    }

    public function utilizationPercent(): float
    {
        return $this->utilization() * 100.0;
    }

    public function availableBytes(): ?int
    {
        if ($this->limitBytes === 0) {
            return null;
        }

        return max(0, $this->limitBytes - $this->allocatedBytes);
    }
}
