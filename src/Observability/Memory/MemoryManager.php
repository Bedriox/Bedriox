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

/** Classifies process pressure and produces bounded cleanup/backpressure decisions for the runtime. */
final class MemoryManager
{
    private MemoryPressure $pressure = MemoryPressure::NORMAL;

    public function __construct(
        private readonly MemoryUsageProvider $usageProvider,
        private readonly MemoryReserve $emergencyReserve,
        private readonly float $elevatedPercent = 70.0,
        private readonly float $highPercent = 85.0,
        private readonly float $criticalPercent = 92.0,
        private readonly float $recoveryMarginPercent = 3.0,
        private readonly int $elevatedUnloadBudget = 32,
        private readonly int $highUnloadBudget = 64,
        private readonly int $criticalUnloadBudget = 96,
    ) {
        if ($elevatedPercent <= 0.0 || $elevatedPercent >= $highPercent || $highPercent >= $criticalPercent
            || $criticalPercent > 100.0 || $recoveryMarginPercent < 0.0
            || $recoveryMarginPercent >= $elevatedPercent || $elevatedUnloadBudget < 1
            || $highUnloadBudget < $elevatedUnloadBudget || $criticalUnloadBudget < $highUnloadBudget) {
            throw new InvalidArgumentException('Memory manager limits are invalid.');
        }
    }

    public function pressure(): MemoryPressure
    {
        return $this->pressure;
    }

    public function evaluate(): MemoryManagementDecision
    {
        $snapshot = $this->usageProvider->snapshot();
        $previous = $this->pressure;
        $this->pressure = $this->classify($snapshot->utilizationPercent());
        $reserveReleased = $this->pressure === MemoryPressure::CRITICAL
            ? $this->emergencyReserve->release()
            : 0;

        return match ($this->pressure) {
            MemoryPressure::NORMAL => new MemoryManagementDecision(
                snapshot: $snapshot,
                previousPressure: $previous,
                pressure: $this->pressure,
                collectCycles: false,
                releaseAllocatorCaches: false,
                accelerateChunkUnloading: false,
                maximumChunkUnloads: 0,
                trimDisposableCaches: false,
                suspendPrefetch: false,
                prioritizePersistence: false,
                pauseOptionalGeneration: false,
                emergencyReserveBytesReleased: $reserveReleased,
            ),
            MemoryPressure::ELEVATED => new MemoryManagementDecision(
                snapshot: $snapshot,
                previousPressure: $previous,
                pressure: $this->pressure,
                collectCycles: true,
                releaseAllocatorCaches: false,
                accelerateChunkUnloading: true,
                maximumChunkUnloads: $this->elevatedUnloadBudget,
                trimDisposableCaches: true,
                suspendPrefetch: false,
                prioritizePersistence: false,
                pauseOptionalGeneration: false,
                emergencyReserveBytesReleased: $reserveReleased,
            ),
            MemoryPressure::HIGH => new MemoryManagementDecision(
                snapshot: $snapshot,
                previousPressure: $previous,
                pressure: $this->pressure,
                collectCycles: true,
                releaseAllocatorCaches: true,
                accelerateChunkUnloading: true,
                maximumChunkUnloads: $this->highUnloadBudget,
                trimDisposableCaches: true,
                suspendPrefetch: true,
                prioritizePersistence: true,
                pauseOptionalGeneration: true,
                emergencyReserveBytesReleased: $reserveReleased,
            ),
            MemoryPressure::CRITICAL => new MemoryManagementDecision(
                snapshot: $snapshot,
                previousPressure: $previous,
                pressure: $this->pressure,
                collectCycles: true,
                releaseAllocatorCaches: true,
                accelerateChunkUnloading: true,
                maximumChunkUnloads: $this->criticalUnloadBudget,
                trimDisposableCaches: true,
                suspendPrefetch: true,
                prioritizePersistence: true,
                pauseOptionalGeneration: true,
                emergencyReserveBytesReleased: $reserveReleased,
            ),
        };
    }

    private function classify(float $utilizationPercent): MemoryPressure
    {
        $candidate = match (true) {
            $utilizationPercent >= $this->criticalPercent => MemoryPressure::CRITICAL,
            $utilizationPercent >= $this->highPercent => MemoryPressure::HIGH,
            $utilizationPercent >= $this->elevatedPercent => MemoryPressure::ELEVATED,
            default => MemoryPressure::NORMAL,
        };
        if ($candidate->value >= $this->pressure->value) {
            return $candidate;
        }

        return match ($this->pressure) {
            MemoryPressure::CRITICAL => $utilizationPercent >= $this->criticalPercent - $this->recoveryMarginPercent
                ? MemoryPressure::CRITICAL
                : $candidate,
            MemoryPressure::HIGH => $utilizationPercent >= $this->highPercent - $this->recoveryMarginPercent
                ? MemoryPressure::HIGH
                : $candidate,
            MemoryPressure::ELEVATED => $utilizationPercent >= $this->elevatedPercent - $this->recoveryMarginPercent
                ? MemoryPressure::ELEVATED
                : $candidate,
            MemoryPressure::NORMAL => MemoryPressure::NORMAL,
        };
    }
}
