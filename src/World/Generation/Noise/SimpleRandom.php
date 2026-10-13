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

namespace Bedriox\Server\World\Generation\Noise;

use InvalidArgumentException;

/** Deterministic 48-bit linear-congruential stream used by biome zooming. */
final class SimpleRandom
{
    private const int MASK_24 = 0x00ff_ffff;
    private const int MASK_48 = 0xffff_ffff_ffff;
    private const int MULTIPLIER = 0x0005_deec_e66d;
    private const int MULTIPLIER_LOW = self::MULTIPLIER & self::MASK_24;
    private const int MULTIPLIER_HIGH = self::MULTIPLIER >> 24;

    private int $state;

    public function __construct(int $seed)
    {
        if ($seed < 0 || $seed > self::MASK_48) {
            throw new InvalidArgumentException('Simple random seed must fit in 48 unsigned bits.');
        }
        $this->state = ($seed ^ self::MULTIPLIER) & self::MASK_48;
    }

    public function nextFloat(): float
    {
        $fraction = $this->next(24) / 16_777_216.0;

        return self::float32($fraction);
    }

    private function next(int $bits): int
    {
        $low = $this->state & self::MASK_24;
        $high = $this->state >> 24;
        $lowProduct = ($low * self::MULTIPLIER_LOW) + 11;
        $nextLow = $lowProduct & self::MASK_24;
        $carry = intdiv($lowProduct, 1 << 24);
        $nextHigh = (($high * self::MULTIPLIER_LOW) + ($low * self::MULTIPLIER_HIGH) + $carry) & self::MASK_24;
        $this->state = ($nextHigh << 24) | $nextLow;

        return $this->state >> (48 - $bits);
    }

    public static function float32(float $value): float
    {
        /** @var array{1: float} $unpacked */
        $unpacked = unpack('g', pack('g', $value));

        return $unpacked[1];
    }
}
