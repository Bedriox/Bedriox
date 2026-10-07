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
    private float $envelopeTokens;
    private float $byteTokens;
    private float $packetTokens;
    private int $lastRefillNanoseconds;

    public function __construct(
        private readonly int $envelopesPerSecond = 400,
        private readonly int $envelopeBurst = 100,
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
            return false;
        }
        $this->refill($nowNanoseconds);
        if ($this->envelopeTokens < 1.0 || $this->byteTokens < $bytes) {
            return false;
        }
        $this->envelopeTokens -= 1.0;
        $this->byteTokens -= $bytes;

        return true;
    }

    public function admitPackets(int $packets, int $nowNanoseconds): bool
    {
        if ($packets < 1 || $nowNanoseconds < $this->lastRefillNanoseconds) {
            return false;
        }
        $this->refill($nowNanoseconds);
        if ($this->packetTokens < $packets) {
            return false;
        }
        $this->packetTokens -= $packets;

        return true;
    }

    private function refill(int $nowNanoseconds): void
    {
        if ($nowNanoseconds === $this->lastRefillNanoseconds) {
            return;
        }
        $seconds = ($nowNanoseconds - $this->lastRefillNanoseconds) / 1_000_000_000;
        $this->envelopeTokens = min($this->envelopeBurst, $this->envelopeTokens + $seconds * $this->envelopesPerSecond);
        $this->byteTokens = min($this->byteBurst, $this->byteTokens + $seconds * $this->bytesPerSecond);
        $this->packetTokens = min($this->packetBurst, $this->packetTokens + $seconds * $this->packetsPerSecond);
        $this->lastRefillNanoseconds = $nowNanoseconds;
    }
}
