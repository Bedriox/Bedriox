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

/** Derives independent named world-generation random streams. */
final readonly class XoroshiroPositionalRandomFactory
{
    public function __construct(
        public UnsignedLong $seedLow,
        public UnsignedLong $seedHigh,
    ) {}

    public static function fromSeed(int $seed): self
    {
        return Xoroshiro128PlusPlus::fromSeed($seed)->forkPositional();
    }

    public function forString(string $name): Xoroshiro128PlusPlus
    {
        $digest = md5($name, true);
        $low = UnsignedLong::fromBigEndian(substr($digest, 0, 8))->xor($this->seedLow);
        $high = UnsignedLong::fromBigEndian(substr($digest, 8, 8))->xor($this->seedHigh);

        return Xoroshiro128PlusPlus::fromStates($low, $high);
    }

    public function forBlockCoordinates(int $x, int $y, int $z): Xoroshiro128PlusPlus
    {
        $hash = UnsignedLong::fromInt($x)->multiply(UnsignedLong::fromInt(3_129_871))
            ->xor(UnsignedLong::fromInt($z)->multiply(UnsignedLong::fromInt(116_129_781)))
            ->xor(UnsignedLong::fromInt($y));
        $hash = $hash->multiply($hash)->multiply(UnsignedLong::fromInt(42_317_861))
            ->add($hash->multiply(UnsignedLong::fromInt(11)))->shiftRightSigned(16);

        return Xoroshiro128PlusPlus::fromStates($hash->xor($this->seedLow), $this->seedHigh);
    }
}
