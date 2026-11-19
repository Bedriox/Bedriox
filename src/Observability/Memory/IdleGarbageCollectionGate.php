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
use UnexpectedValueException;

/** Keeps opportunistic cyclic collection away from player disconnect bursts. */
final class IdleGarbageCollectionGate
{
    private ?int $idleSinceNanoseconds = null;
    private ?int $lastAttemptNanoseconds = null;

    public function __construct(
        private readonly int $graceNanoseconds = 5_000_000_000,
        private readonly int $minimumAttemptIntervalNanoseconds = 30_000_000_000,
    ) {
        if ($graceNanoseconds < 1 || $minimumAttemptIntervalNanoseconds < 1) {
            throw new InvalidArgumentException('Idle garbage-collection intervals must be positive.');
        }
    }

    public function markActive(): void
    {
        $this->idleSinceNanoseconds = null;
        $this->lastAttemptNanoseconds = null;
    }

    public function shouldCollect(int $nowNanoseconds, bool $idle): bool
    {
        if (!$idle) {
            $this->markActive();

            return false;
        }
        if ($this->idleSinceNanoseconds === null) {
            $this->idleSinceNanoseconds = $nowNanoseconds;

            return false;
        }
        if ($nowNanoseconds < $this->idleSinceNanoseconds
            || ($this->lastAttemptNanoseconds !== null && $nowNanoseconds < $this->lastAttemptNanoseconds)) {
            throw new UnexpectedValueException('Idle garbage-collection time must be monotonic.');
        }
        if ($nowNanoseconds - $this->idleSinceNanoseconds < $this->graceNanoseconds) {
            return false;
        }
        if ($this->lastAttemptNanoseconds !== null
            && $nowNanoseconds - $this->lastAttemptNanoseconds < $this->minimumAttemptIntervalNanoseconds) {
            return false;
        }
        $this->lastAttemptNanoseconds = $nowNanoseconds;

        return true;
    }
}
