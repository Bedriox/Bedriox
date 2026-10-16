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

namespace Bedriox\Server\Runtime;

/** Protects authoritative ticks by reducing optional chunk work after measured pressure. */
final class ChunkStreamingGovernor
{
    private const int ELEVATED_NANOSECONDS = 35_000_000;
    private const int HIGH_NANOSECONDS = 50_000_000;
    private const int CRITICAL_NANOSECONDS = 100_000_000;
    private const int RECOVERY_NANOSECONDS = 25_000_000;
    private const int RECOVERY_TICKS = 20;

    private int $level = 0;
    private int $healthyTicks = 0;

    public function observeTickPoll(int $elapsedNanoseconds): void
    {
        if ($elapsedNanoseconds < 0) {
            throw new \InvalidArgumentException('Chunk pressure observation cannot be negative.');
        }
        $observed = match (true) {
            $elapsedNanoseconds >= self::CRITICAL_NANOSECONDS => 3,
            $elapsedNanoseconds >= self::HIGH_NANOSECONDS => 2,
            $elapsedNanoseconds >= self::ELEVATED_NANOSECONDS => 1,
            default => 0,
        };
        if ($observed > 0) {
            $this->level = max($this->level, $observed);
            $this->healthyTicks = 0;

            return;
        }
        if ($this->level === 0 || $elapsedNanoseconds >= self::RECOVERY_NANOSECONDS) {
            $this->healthyTicks = 0;

            return;
        }
        if (++$this->healthyTicks >= self::RECOVERY_TICKS) {
            --$this->level;
            $this->healthyTicks = 0;
        }
    }

    public function generationLimit(int $configured): int
    {
        self::validateConfiguredLimit($configured);

        return match ($this->level) {
            0 => $configured,
            1 => max(1, intdiv($configured + 1, 2)),
            default => 1,
        };
    }

    public function preparationLimit(int $configured): int
    {
        self::validateConfiguredLimit($configured);

        return match ($this->level) {
            0 => $configured,
            1 => max(1, intdiv($configured + 1, 2)),
            default => max(1, min(2, $configured)),
        };
    }

    public function deliveryLimit(int $configured): int
    {
        self::validateConfiguredLimit($configured);

        return match ($this->level) {
            0, 1 => $configured,
            default => max(1, min(4, $configured)),
        };
    }

    public function allowsPrefetch(): bool
    {
        return $this->level === 0;
    }

    public function level(): int
    {
        return $this->level;
    }

    private static function validateConfiguredLimit(int $configured): void
    {
        if ($configured < 1 || $configured > 64) {
            throw new \InvalidArgumentException('Configured chunk limit must be between 1 and 64.');
        }
    }
}
