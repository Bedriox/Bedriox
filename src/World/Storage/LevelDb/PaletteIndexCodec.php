<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

/** @internal Mojang persistent paletted-array word packing. */
final class PaletteIndexCodec
{
    /** @var list<int> */
    private const array ALLOWED_BITS = [0, 1, 2, 3, 4, 5, 6, 8, 16];

    private function __construct() {}

    public static function bitsForPaletteSize(int $paletteSize): int
    {
        if ($paletteSize < 1 || $paletteSize > PersistentBlockStorage::ENTRY_COUNT) {
            throw new LevelDbStorageException('Persistent palette size is outside the admitted range.');
        }
        foreach (self::ALLOWED_BITS as $bits) {
            if ($bits === 0 ? $paletteSize === 1 : $paletteSize <= (1 << $bits)) {
                return $bits;
            }
        }
        throw new LevelDbStorageException('Persistent palette cannot be represented.');
    }

    /** @param callable(int, int, int): int $indexAt */
    public static function encodeWords(int $bits, callable $indexAt): string
    {
        self::validateBits($bits);
        if ($bits === 0) {
            return '';
        }
        $perWord = intdiv(32, $bits);
        $words = array_fill(0, (int) ceil(PersistentBlockStorage::ENTRY_COUNT / $perWord), 0);
        $diskOffset = 0;
        for ($x = 0; $x < 16; ++$x) {
            for ($z = 0; $z < 16; ++$z) {
                for ($y = 0; $y < 16; ++$y, ++$diskOffset) {
                    $index = $indexAt($x, $y, $z);
                    if ($index < 0 || $index >= (1 << $bits)) {
                        throw new LevelDbStorageException('Palette index does not fit the selected persistent bit width.');
                    }
                    $words[intdiv($diskOffset, $perWord)] |= $index << (($diskOffset % $perWord) * $bits);
                }
            }
        }
        $output = '';
        foreach ($words as $word) {
            $output .= pack('V', $word & 0xffff_ffff);
        }
        return $output;
    }

    /** @return list<int> YZX-ordered indices */
    public static function decodeWords(LevelDbBinaryReader $reader, int $bits): array
    {
        self::validateBits($bits);
        if ($bits === 0) {
            return array_fill(0, PersistentBlockStorage::ENTRY_COUNT, 0);
        }
        $perWord = intdiv(32, $bits);
        $wordCount = (int) ceil(PersistentBlockStorage::ENTRY_COUNT / $perWord);
        $words = [];
        for ($i = 0; $i < $wordCount; ++$i) {
            $words[] = $reader->unsignedLittleEndian32();
        }
        $mask = (1 << $bits) - 1;
        $indices = array_fill(0, PersistentBlockStorage::ENTRY_COUNT, 0);
        $diskOffset = 0;
        for ($x = 0; $x < 16; ++$x) {
            for ($z = 0; $z < 16; ++$z) {
                for ($y = 0; $y < 16; ++$y, ++$diskOffset) {
                    $index = ($words[intdiv($diskOffset, $perWord)] >> (($diskOffset % $perWord) * $bits)) & $mask;
                    $indices[$x + ($z << 4) + ($y << 8)] = $index;
                }
            }
        }
        return array_values($indices);
    }

    private static function validateBits(int $bits): void
    {
        if (!in_array($bits, self::ALLOWED_BITS, true)) {
            throw new LevelDbStorageException("Unsupported persistent palette bit width $bits.");
        }
    }
}
