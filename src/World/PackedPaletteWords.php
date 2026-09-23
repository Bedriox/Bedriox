<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

/** @internal Packs byte palette indexes into Bedrock's X-Z-Y little-endian word layout. */
final class PackedPaletteWords
{
    private const array ALLOWED_BITS = [0, 1, 2, 3, 4, 5, 6, 8];

    private function __construct() {}

    public static function bitsForPaletteSize(int $paletteSize, int $minimumBits = 0): int
    {
        if ($paletteSize < 1 || $paletteSize > 256 || !in_array($minimumBits, self::ALLOWED_BITS, true)) {
            throw new InvalidArgumentException('Palette size or minimum bit width is invalid.');
        }
        foreach (self::ALLOWED_BITS as $bits) {
            if ($bits < $minimumBits) {
                continue;
            }
            if ($bits === 0 ? $paletteSize === 1 : $paletteSize <= (1 << $bits)) {
                return $bits;
            }
        }

        throw new InvalidArgumentException('Palette cannot be represented by a supported word width.');
    }

    public static function packNetworkOrder(string $memoryOrderedIndices, int $bits): string
    {
        if (strlen($memoryOrderedIndices) !== SubChunkBlockStorage::BLOCK_COUNT
            || !in_array($bits, self::ALLOWED_BITS, true)) {
            throw new InvalidArgumentException('Palette indices or packed bit width are invalid.');
        }
        if ($bits === 0) {
            if (trim($memoryOrderedIndices, "\x00") !== '') {
                throw new InvalidArgumentException('Zero-bit palette storage may contain only index zero.');
            }

            return '';
        }

        $entriesPerWord = intdiv(32, $bits);
        $output = '';
        $word = 0;
        $entry = 0;
        for ($x = 0; $x < 16; ++$x) {
            for ($z = 0; $z < 16; ++$z) {
                for ($y = 0; $y < 16; ++$y) {
                    $index = ord($memoryOrderedIndices[$x + ($z * 16) + ($y * 256)]);
                    if ($index >= (1 << $bits)) {
                        throw new InvalidArgumentException('Palette index does not fit the selected word width.');
                    }
                    $word |= $index << ($entry * $bits);
                    if (++$entry !== $entriesPerWord) {
                        continue;
                    }
                    $output .= pack('V', $word & 0xffff_ffff);
                    $word = 0;
                    $entry = 0;
                }
            }
        }
        if ($entry !== 0) {
            $output .= pack('V', $word & 0xffff_ffff);
        }

        return $output;
    }

    public static function replaceNetworkIndex(
        string $wordArray,
        int $bits,
        int $localX,
        int $localY,
        int $localZ,
        int $paletteIndex,
    ): string {
        if ($bits === 0 || !in_array($bits, self::ALLOWED_BITS, true)
            || $localX < 0 || $localX >= 16 || $localY < 0 || $localY >= 16 || $localZ < 0 || $localZ >= 16
            || $paletteIndex < 0 || $paletteIndex >= (1 << $bits)) {
            throw new InvalidArgumentException('Packed palette replacement is invalid.');
        }
        $entriesPerWord = intdiv(32, $bits);
        $expectedBytes = intdiv(SubChunkBlockStorage::BLOCK_COUNT + $entriesPerWord - 1, $entriesPerWord) * 4;
        if (strlen($wordArray) !== $expectedBytes) {
            throw new InvalidArgumentException('Packed palette word array has an invalid length.');
        }
        $networkOffset = ($localX * 256) + ($localZ * 16) + $localY;
        $wordIndex = intdiv($networkOffset, $entriesPerWord);
        $byteOffset = $wordIndex * 4;
        $decoded = unpack('Vvalue', substr($wordArray, $byteOffset, 4));
        $word = is_array($decoded) ? ($decoded['value'] ?? null) : null;
        if (!is_int($word)) {
            throw new InvalidArgumentException('Packed palette word is truncated.');
        }
        $shift = ($networkOffset % $entriesPerWord) * $bits;
        $mask = ((1 << $bits) - 1) << $shift;
        $word = ($word & ~$mask) | ($paletteIndex << $shift);

        return substr_replace($wordArray, pack('V', $word & 0xffff_ffff), $byteOffset, 4);
    }
}
