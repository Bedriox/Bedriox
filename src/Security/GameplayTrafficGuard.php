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

/** Bounded sustained-traffic admission for one authenticated play session. */
final class GameplayTrafficGuard
{
    private const int DELAYED_INPUT_THRESHOLD_NANOSECONDS = 250_000_000;
    private const int MOVEMENT_INTERVAL_NANOSECONDS = 50_000_000;
    private const int MAXIMUM_DELAYED_MOVEMENT_CREDIT = 256;

    private float $envelopeTokens;
    private float $byteTokens;
    private float $packetTokens;
    private int $delayedMovementTokens = 0;
    private int $lastRefillNanoseconds;
    private int $lastObservationGapNanoseconds = 0;
    private string $lastDecision = 'admitted';

    public function __construct(
        private readonly int $envelopesPerSecond = 400,
        // A retail client may queue one movement envelope per tick while the main
        // process is occupied by a cold teleport. Keep enough bounded headroom to
        // accept that catch-up traffic without weakening the sustained-rate cap.
        private readonly int $envelopeBurst = 512,
        private readonly int $bytesPerSecond = 8_388_608,
        private readonly int $byteBurst = 2_097_152,
        private readonly int $packetsPerSecond = 1_200,
        private readonly int $packetBurst = 300,
        int $startedNanoseconds = 0,
    ) {
        $this->envelopeTokens = $this->envelopeBurst;
        $this->byteTokens = $this->byteBurst;
        $this->packetTokens = $this->packetBurst;
        $this->lastRefillNanoseconds = $startedNanoseconds;
    }

    public function admitEnvelope(int $bytes, int $nowNanoseconds): bool
    {
        if ($bytes < 1 || $nowNanoseconds < $this->lastRefillNanoseconds) {
            $this->lastDecision = 'invalid_envelope';
            return false;
        }
        $this->refill($nowNanoseconds);
        if ($this->envelopeTokens < 1.0) {
            $this->lastDecision = 'envelope_budget';
            return false;
        }
        if ($this->byteTokens < $bytes) {
            $this->lastDecision = 'byte_budget';
            return false;
        }
        $this->envelopeTokens -= 1.0;
        $this->byteTokens -= $bytes;
        $this->lastDecision = 'admitted';

        return true;
    }

    public function admitPackets(int $packets, int $nowNanoseconds): bool
    {
        return $this->admitPacketBatch($packets, 0, $nowNanoseconds);
    }

    /**
     * Admits a decoded batch while allowing only delayed movement packets to
     * consume bounded credit earned during an observed processing stall.
     */
    public function admitPacketBatch(int $packets, int $movementPackets, int $nowNanoseconds): bool
    {
        if ($packets < 1 || $movementPackets < 0 || $movementPackets > $packets
            || $nowNanoseconds < $this->lastRefillNanoseconds) {
            $this->lastDecision = 'invalid_packet_batch';
            return false;
        }
        $this->refill($nowNanoseconds);
        $ordinaryPackets = $packets - $movementPackets;
        $delayedMovement = min($this->delayedMovementTokens, $movementPackets);
        $ordinaryMovement = $movementPackets - $delayedMovement;
        $ordinaryTotal = $ordinaryPackets + $ordinaryMovement;
        if ($this->packetTokens < $ordinaryTotal) {
            $this->lastDecision = 'packet_budget';
            return false;
        }
        $this->packetTokens -= $ordinaryTotal;
        $this->delayedMovementTokens -= $delayedMovement;
        $this->lastDecision = 'admitted';

        return true;
    }

    /**
     * @return array{decision: string, envelope_budget: int, byte_budget: int, packet_budget: int,
     *     delayed_movement_budget: int, observation_gap_ms: int}
     */
    public function diagnosticSnapshot(): array
    {
        return [
            'decision' => $this->lastDecision,
            'envelope_budget' => max(0, (int) floor($this->envelopeTokens)),
            'byte_budget' => max(0, (int) floor($this->byteTokens)),
            'packet_budget' => max(0, (int) floor($this->packetTokens)),
            'delayed_movement_budget' => $this->delayedMovementTokens,
            'observation_gap_ms' => min(60_000, intdiv($this->lastObservationGapNanoseconds, 1_000_000)),
        ];
    }

    private function refill(int $nowNanoseconds): void
    {
        if ($nowNanoseconds === $this->lastRefillNanoseconds) {
            return;
        }
        $elapsedNanoseconds = $nowNanoseconds - $this->lastRefillNanoseconds;
        $this->lastObservationGapNanoseconds = $elapsedNanoseconds;
        $seconds = $elapsedNanoseconds / 1_000_000_000;
        $this->envelopeTokens = min($this->envelopeBurst, $this->envelopeTokens + $seconds * $this->envelopesPerSecond);
        $this->byteTokens = min($this->byteBurst, $this->byteTokens + $seconds * $this->bytesPerSecond);
        $this->packetTokens = min($this->packetBurst, $this->packetTokens + $seconds * $this->packetsPerSecond);
        if ($elapsedNanoseconds >= self::DELAYED_INPUT_THRESHOLD_NANOSECONDS) {
            $earned = intdiv($elapsedNanoseconds, self::MOVEMENT_INTERVAL_NANOSECONDS);
            $this->delayedMovementTokens = min(
                self::MAXIMUM_DELAYED_MOVEMENT_CREDIT,
                $this->delayedMovementTokens + $earned,
            );
        }
        $this->lastRefillNanoseconds = $nowNanoseconds;
    }
}
