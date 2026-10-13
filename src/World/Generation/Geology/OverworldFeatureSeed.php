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

namespace Bedriox\Server\World\Generation\Geology;

/** Seed derivation shared by named overworld decoration rules. */
final class OverworldFeatureSeed
{
    private const int UINT32_MASK = 0xffff_ffff;
    private const int FNV_OFFSET_LOW = 0x8422_2325;
    private const int FNV_PRIME_LOW = 0x0000_01b3;

    public static function decoration(int $worldSeed, int $chunkX, int $chunkZ): int
    {
        $worldSeed &= self::UINT32_MASK;
        $random = new MersenneTwister($worldSeed);
        $xMultiplier = ($random->nextUint32() >> 1) | 1;
        $zMultiplier = ($random->nextUint32() >> 1) | 1;

        return ((
            self::multiply32($chunkX & self::UINT32_MASK, $xMultiplier)
            + self::multiply32($chunkZ & self::UINT32_MASK, $zMultiplier)
        ) & self::UINT32_MASK ^ $worldSeed) & self::UINT32_MASK;
    }

    public static function feature(int $decorationSeed, string $identifier): int
    {
        $decorationSeed &= self::UINT32_MASK;
        $combined = (
            self::identifierHashLow($identifier)
            + 0x9e37_79b9
            + self::multiply32($decorationSeed, 64)
            + ($decorationSeed >> 2)
        ) & self::UINT32_MASK;

        return ($decorationSeed ^ $combined) & self::UINT32_MASK;
    }

    public static function identifierHashLow(string $identifier): int
    {
        $hash = self::FNV_OFFSET_LOW;
        $length = strlen($identifier);
        for ($offset = 0; $offset < $length; ++$offset) {
            $hash = (($hash * self::FNV_PRIME_LOW) & self::UINT32_MASK) ^ ord($identifier[$offset]);
        }

        return $hash;
    }

    private static function multiply32(int $left, int $right): int
    {
        $left &= self::UINT32_MASK;
        $right &= self::UINT32_MASK;
        $low = ($left & 0xffff) * $right;
        $high = (($left >> 16) * $right) & 0xffff;

        return ($low + ($high << 16)) & self::UINT32_MASK;
    }
}
