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

namespace Bedriox\Server\World;

use InvalidArgumentException;

/** @internal Compact memory-order palette indices for immutable section storage. */
final class PackedPaletteIndices
{
    private function __construct() {}

    public static function pack(string $indices, int $bits, bool $validate = true): string
    {
        if (strlen($indices) !== SubChunkBlockStorage::BLOCK_COUNT || $bits < 0 || $bits > 8) {
            throw new InvalidArgumentException('Palette indices or bit width are invalid.');
        }
        if ($bits === 0) {
            if ($validate && trim($indices, "\x00") !== '') {
                throw new InvalidArgumentException('Uniform palette storage may contain only index zero.');
            }

            return '';
        }
        if ($bits === 8) {
            return $indices;
        }

        $mask = (1 << $bits) - 1;
        $output = '';
        $accumulator = 0;
        $availableBits = 0;
        for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
            $index = ord($indices[$offset]);
            if ($validate && $index > $mask) {
                throw new InvalidArgumentException('Palette index does not fit the selected bit width.');
            }
            $accumulator |= ($index & $mask) << $availableBits;
            $availableBits += $bits;
            while ($availableBits >= 8) {
                $output .= chr($accumulator & 0xff);
                $accumulator >>= 8;
                $availableBits -= 8;
            }
        }
        if ($availableBits > 0) {
            $output .= chr($accumulator & 0xff);
        }

        return $output;
    }

    public static function unpack(string $packed, int $bits): string
    {
        self::validatePackedLength($packed, $bits);
        if ($bits === 0) {
            return str_repeat("\x00", SubChunkBlockStorage::BLOCK_COUNT);
        }
        if ($bits === 8) {
            return $packed;
        }

        $indices = '';
        for ($offset = 0; $offset < SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
            $indices .= chr(self::indexAtUnchecked($packed, $bits, $offset));
        }

        return $indices;
    }

    public static function indexAt(string $packed, int $bits, int $offset): int
    {
        self::validatePackedLength($packed, $bits);
        if ($offset < 0 || $offset >= SubChunkBlockStorage::BLOCK_COUNT) {
            throw new InvalidArgumentException('Palette index offset is outside section storage.');
        }

        return self::indexAtUnchecked($packed, $bits, $offset);
    }

    /** @internal The caller owns the packed-width and offset invariants. */
    public static function trustedIndexAt(string $packed, int $bits, int $offset): int
    {
        return self::indexAtUnchecked($packed, $bits, $offset);
    }

    public static function replace(string $packed, int $bits, int $offset, int $index): string
    {
        self::validatePackedLength($packed, $bits);
        if ($bits === 0 || $offset < 0 || $offset >= SubChunkBlockStorage::BLOCK_COUNT
            || $index < 0 || $index >= (1 << $bits)) {
            throw new InvalidArgumentException('Packed palette replacement is invalid.');
        }
        if ($bits === 8) {
            $packed[$offset] = chr($index);

            return $packed;
        }

        $bitOffset = $offset * $bits;
        $byteOffset = intdiv($bitOffset, 8);
        $shift = $bitOffset % 8;
        $combined = ord($packed[$byteOffset]);
        if ($shift + $bits > 8) {
            $combined |= ord($packed[$byteOffset + 1]) << 8;
        }
        $mask = ((1 << $bits) - 1) << $shift;
        $combined = ($combined & ~$mask) | ($index << $shift);
        $packed[$byteOffset] = chr($combined & 0xff);
        if ($shift + $bits > 8) {
            $packed[$byteOffset + 1] = chr(($combined >> 8) & 0xff);
        }

        return $packed;
    }

    public static function bytesFor(int $bits): int
    {
        if ($bits < 0 || $bits > 8) {
            throw new InvalidArgumentException('Packed palette bit width is invalid.');
        }

        return intdiv((SubChunkBlockStorage::BLOCK_COUNT * $bits) + 7, 8);
    }

    private static function indexAtUnchecked(string $packed, int $bits, int $offset): int
    {
        if ($bits === 0) {
            return 0;
        }
        if ($bits === 8) {
            return ord($packed[$offset]);
        }
        $bitOffset = $offset * $bits;
        $byteOffset = intdiv($bitOffset, 8);
        $shift = $bitOffset % 8;
        $combined = ord($packed[$byteOffset]);
        if ($shift + $bits > 8) {
            $combined |= ord($packed[$byteOffset + 1]) << 8;
        }

        return ($combined >> $shift) & ((1 << $bits) - 1);
    }

    private static function validatePackedLength(string $packed, int $bits): void
    {
        if (strlen($packed) !== self::bytesFor($bits)) {
            throw new InvalidArgumentException('Packed palette storage has an invalid length.');
        }
    }
}
