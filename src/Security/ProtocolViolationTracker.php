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

namespace Bedriox\Server\Security;

use InvalidArgumentException;

/**
 * Tracks repeated semantic protocol violations for one authenticated session.
 *
 * Transport framing, encryption, decompression, and flood protection remain
 * owned by RakNet and the protocol boundary. Harmless unsupported, stale, or
 * correctable gameplay input must never be recorded here.
 */
final class ProtocolViolationTracker
{
    private int $softStrikes = 0;
    private int $lastSoftStrikeNanoseconds;
    private int $hardViolations = 0;

    public function __construct(
        private readonly int $softStrikeLimit = 10,
        private readonly int $decayNanoseconds = 15_000_000_000,
        int $startedNanoseconds = 0,
    ) {
        if ($this->softStrikeLimit < 1) {
            throw new InvalidArgumentException('The soft protocol strike limit must be positive.');
        }
        if ($this->decayNanoseconds < 1) {
            throw new InvalidArgumentException('The protocol strike decay interval must be positive.');
        }
        if ($startedNanoseconds < 0) {
            throw new InvalidArgumentException('The protocol tracker start time cannot be negative.');
        }
        $this->lastSoftStrikeNanoseconds = $startedNanoseconds;
    }

    /** Returns true only after repeated semantic violations exhaust the configured allowance. */
    public function recordSoftViolation(int $nowNanoseconds): bool
    {
        $this->decay($nowNanoseconds);
        ++$this->softStrikes;

        return $this->softStrikes >= $this->softStrikeLimit;
    }

    /** Records a non-correctable protocol failure that requires immediate session closure. */
    public function recordHardViolation(): void
    {
        ++$this->hardViolations;
    }

    /** @return array{soft_strikes: int, soft_strike_limit: int, hard_violations: int} */
    public function snapshot(int $nowNanoseconds): array
    {
        $this->decay($nowNanoseconds);

        return [
            'soft_strikes' => $this->softStrikes,
            'soft_strike_limit' => $this->softStrikeLimit,
            'hard_violations' => $this->hardViolations,
        ];
    }

    private function decay(int $nowNanoseconds): void
    {
        if ($nowNanoseconds < $this->lastSoftStrikeNanoseconds) {
            throw new InvalidArgumentException('Protocol violation time must be monotonic.');
        }
        if ($this->softStrikes === 0) {
            $this->lastSoftStrikeNanoseconds = $nowNanoseconds;
            return;
        }
        $elapsed = $nowNanoseconds - $this->lastSoftStrikeNanoseconds;
        $decayed = intdiv($elapsed, $this->decayNanoseconds);
        if ($decayed === 0) {
            return;
        }
        $this->softStrikes = max(0, $this->softStrikes - $decayed);
        $this->lastSoftStrikeNanoseconds += $decayed * $this->decayNanoseconds;
    }
}
