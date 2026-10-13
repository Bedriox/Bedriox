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

/** xoroshiro128++ stream with deterministic 64-bit arithmetic. */
final class Xoroshiro128PlusPlus
{
    private const string SILVER_RATIO = '6a09e667f3bcc909';
    private const string GOLDEN_RATIO = '9e3779b97f4a7c15';
    private const string MIX_MULTIPLIER_A = 'bf58476d1ce4e5b9';
    private const string MIX_MULTIPLIER_B = '94d049bb133111eb';

    private function __construct(
        private UnsignedLong $stateLow,
        private UnsignedLong $stateHigh,
    ) {}

    public static function fromSeed(int $seed): self
    {
        $low = UnsignedLong::fromInt($seed)->xor(UnsignedLong::fromHex(self::SILVER_RATIO));
        $high = $low->add(UnsignedLong::fromHex(self::GOLDEN_RATIO));

        return new self(self::mixStafford13($low), self::mixStafford13($high));
    }

    public static function fromStates(UnsignedLong $low, UnsignedLong $high): self
    {
        return new self($low, $high);
    }

    public function nextLong(): UnsignedLong
    {
        $low = $this->stateLow;
        $high = $this->stateHigh;
        $result = $low->add($high)->rotateLeft(17)->add($low);
        $mixed = $high->xor($low);
        $this->stateLow = $low->rotateLeft(49)->xor($mixed)->xor($mixed->shiftLeft(21));
        $this->stateHigh = $mixed->rotateLeft(28);

        return $result;
    }

    public function nextDouble(): float
    {
        return $this->nextLong()->high53Fraction();
    }

    public function nextInt(int $bound): int
    {
        if ($bound < 1 || $bound > 0x7fff_ffff) {
            throw new \InvalidArgumentException('Xoroshiro integer bound must be between 1 and 2147483647.');
        }
        $threshold = (4_294_967_296 - $bound) % $bound;
        do {
            $unsigned = $this->nextLong()->low;
            $product = $unsigned * $bound;
            $low = $product & 0xffff_ffff;
        } while ($low < $threshold);

        return intdiv($product, 4_294_967_296);
    }

    public function forkPositional(): XoroshiroPositionalRandomFactory
    {
        return new XoroshiroPositionalRandomFactory($this->nextLong(), $this->nextLong());
    }

    private static function mixStafford13(UnsignedLong $value): UnsignedLong
    {
        $value = $value->xor($value->shiftRight(30))->multiply(UnsignedLong::fromHex(self::MIX_MULTIPLIER_A));
        $value = $value->xor($value->shiftRight(27))->multiply(UnsignedLong::fromHex(self::MIX_MULTIPLIER_B));

        return $value->xor($value->shiftRight(31));
    }
}
