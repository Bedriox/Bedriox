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

namespace Bedriox\Server\ResourcePack;

use SplQueue;

/** Fair process-wide admission for low-priority resource-pack output. */
final class ResourcePackTransferGovernor
{
    /** @var SplQueue<string> */
    private SplQueue $waiting;
    /** @var array<string, true> */
    private array $waitingSet = [];
    /** @var array<string, int> */
    private array $inFlight = [];
    private float $tokens;
    private int $lastRefillNanoseconds;
    private int $bytesAdmitted = 0;
    private int $throttled = 0;

    public function __construct(
        private readonly int $maximumConcurrentTransfers = 16,
        private readonly int $bytesPerSecond = 67_108_864,
        private readonly int $burstBytes = 8_388_608,
        int $startedNanoseconds = 0,
    ) {
        if ($maximumConcurrentTransfers < 1 || $maximumConcurrentTransfers > 1_024
            || $bytesPerSecond < 1_048_576 || $bytesPerSecond > 1_073_741_824
            || $burstBytes < ResourcePackArchive::CHUNK_BYTES || $burstBytes > 134_217_728) {
            throw new \InvalidArgumentException('Resource-pack transfer limits are invalid.');
        }
        $this->waiting = new SplQueue();
        $this->tokens = $burstBytes;
        $this->lastRefillNanoseconds = $startedNanoseconds;
    }

    public function acquire(string $session, int $bytes, int $nowNanoseconds): bool
    {
        if ($session === '' || $bytes < 1 || $bytes > ResourcePackArchive::CHUNK_BYTES
            || $nowNanoseconds < $this->lastRefillNanoseconds || isset($this->inFlight[$session])) {
            return false;
        }
        if (!isset($this->waitingSet[$session])) {
            $this->waitingSet[$session] = true;
            $this->waiting->enqueue($session);
        }
        $this->discardCancelledHead();
        $this->refill($nowNanoseconds);
        if ($this->waiting->isEmpty() || $this->waiting->bottom() !== $session
            || count($this->inFlight) >= $this->maximumConcurrentTransfers || $this->tokens < $bytes) {
            ++$this->throttled;

            return false;
        }
        $this->waiting->dequeue();
        unset($this->waitingSet[$session]);
        $this->tokens -= $bytes;
        $this->inFlight[$session] = $bytes;
        $this->bytesAdmitted += $bytes;

        return true;
    }

    public function complete(string $session): void
    {
        unset($this->inFlight[$session]);
    }

    public function cancel(string $session): void
    {
        unset($this->inFlight[$session], $this->waitingSet[$session]);
        $this->discardCancelledHead();
    }

    /** @return array{waiting: int, in_flight: int, admitted_bytes: int, throttled: int} */
    public function snapshot(): array
    {
        return [
            'waiting' => count($this->waitingSet),
            'in_flight' => count($this->inFlight),
            'admitted_bytes' => $this->bytesAdmitted,
            'throttled' => $this->throttled,
        ];
    }

    private function refill(int $nowNanoseconds): void
    {
        if ($nowNanoseconds === $this->lastRefillNanoseconds) {
            return;
        }
        $seconds = ($nowNanoseconds - $this->lastRefillNanoseconds) / 1_000_000_000;
        $this->tokens = min($this->burstBytes, $this->tokens + $seconds * $this->bytesPerSecond);
        $this->lastRefillNanoseconds = $nowNanoseconds;
    }

    private function discardCancelledHead(): void
    {
        while (!$this->waiting->isEmpty() && !isset($this->waitingSet[$this->waiting->bottom()])) {
            $this->waiting->dequeue();
        }
    }
}
