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

/** Immutable canonical biome storage for one 16 x 16 x 16 section. */
final readonly class BiomeStorage
{
    public const BIOME_COUNT = 4096;
    private const MAX_PALETTE_SIZE = 256;

    /** @var list<Biome> */
    private array $palette;

    /** Bit-packed x + (z * 16) + (y * 256) memory-order biome indices. */
    private string $packedPaletteIndices;

    private int $memoryBitsPerEntry;

    private int $networkBitsPerEntry;

    /** Bedrock X-Z-Y ordered little-endian words. */
    private string $networkWordArray;

    /** @param list<mixed> $palette */
    private function __construct(
        array $palette,
        ?string $paletteIndices,
        ?string $networkWordArray = null,
        ?string $packedPaletteIndices = null,
    ) {
        if ($palette === [] || count($palette) > self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Biome palette must contain between 1 and 256 biomes.');
        }
        if ($paletteIndices === null && $packedPaletteIndices === null && count($palette) !== 1) {
            throw new InvalidArgumentException('Only uniform biome storage may omit palette indices.');
        }
        if ($paletteIndices !== null && strlen($paletteIndices) !== self::BIOME_COUNT) {
            throw new InvalidArgumentException('Biome palette indices must contain exactly 4096 bytes.');
        }
        foreach ($palette as $biome) {
            if (!$biome instanceof Biome) {
                throw new InvalidArgumentException('Biome palette contains an invalid biome.');
            }
        }
        $paletteSize = count($palette);
        if ($paletteIndices !== null) {
            for ($offset = 0; $offset < self::BIOME_COUNT; ++$offset) {
                if (ord($paletteIndices[$offset]) >= $paletteSize) {
                    throw new InvalidArgumentException('Biome storage contains an out-of-range palette index.');
                }
            }
        }
        $this->palette = $palette;
        $this->memoryBitsPerEntry = PackedPaletteWords::bitsForPaletteSize($paletteSize);
        if ($packedPaletteIndices !== null
            && strlen($packedPaletteIndices) !== PackedPaletteIndices::bytesFor($this->memoryBitsPerEntry)) {
            throw new InvalidArgumentException('Packed biome indices have an invalid length.');
        }
        $this->packedPaletteIndices = $packedPaletteIndices ?? PackedPaletteIndices::pack(
            $paletteIndices ?? str_repeat("\x00", self::BIOME_COUNT),
            $this->memoryBitsPerEntry,
        );
        $this->networkBitsPerEntry = PackedPaletteWords::bitsForPaletteSize($paletteSize, 2);
        $this->networkWordArray = $networkWordArray
            ?? ($this->memoryBitsPerEntry === 0
                ? self::uniformNetworkWordArray()
                : PackedPaletteWords::packNetworkOrderFromPackedMemory(
                    $this->packedPaletteIndices,
                    $this->memoryBitsPerEntry,
                    $this->networkBitsPerEntry,
                ));
        $expectedWords = intdiv(
            self::BIOME_COUNT + intdiv(32, $this->networkBitsPerEntry) - 1,
            intdiv(32, $this->networkBitsPerEntry),
        ) * 4;
        if (strlen($this->networkWordArray) !== $expectedWords) {
            throw new InvalidArgumentException('Biome packed word array has an invalid length.');
        }
    }

    public static function uniform(Biome $biome): self
    {
        return new self([$biome], null);
    }

    /** Every uniform biome storage shares the same zero-valued packed word array. */
    private static function uniformNetworkWordArray(): string
    {
        static $words = null;
        if (!is_string($words)) {
            $words = PackedPaletteWords::packNetworkOrder(
                str_repeat("\x00", self::BIOME_COUNT),
                PackedPaletteWords::bitsForPaletteSize(1, 2),
            );
        }

        return $words;
    }

    /** @param list<mixed> $palette */
    public static function fromPaletteIndices(array $palette, string $paletteIndices): self
    {
        return new self($palette, $paletteIndices);
    }

    /** @param list<mixed> $palette */
    public static function fromPackedPaletteIndices(array $palette, string $packedPaletteIndices): self
    {
        $bits = PackedPaletteWords::bitsForPaletteSize(count($palette));
        if (strlen($packedPaletteIndices) !== PackedPaletteIndices::bytesFor($bits)) {
            throw new InvalidArgumentException('Packed biome indices have an invalid length.');
        }
        for ($offset = 0; $offset < self::BIOME_COUNT; ++$offset) {
            if (PackedPaletteIndices::trustedIndexAt($packedPaletteIndices, $bits, $offset) >= count($palette)) {
                throw new InvalidArgumentException('Packed biome storage contains an out-of-range palette index.');
            }
        }

        return new self($palette, null, packedPaletteIndices: $packedPaletteIndices);
    }

    /** @param list<mixed> $columns 256 biomes in x + z * 16 order. */
    public static function fromColumns(array $columns): self
    {
        if (count($columns) !== 256) {
            throw new InvalidArgumentException('Biome columns must contain exactly 256 entries.');
        }
        $palette = [];
        $byIdentifier = [];
        $columnIndices = '';
        foreach ($columns as $biome) {
            if (!$biome instanceof Biome) {
                throw new InvalidArgumentException('Biome columns contain an invalid biome.');
            }
            $index = $byIdentifier[$biome->identifier] ?? null;
            if (!is_int($index)) {
                $index = count($palette);
                $palette[] = $biome;
                $byIdentifier[$biome->identifier] = $index;
            }
            $columnIndices .= self::encodePaletteIndex($index);
        }
        $indices = '';
        for ($y = 0; $y < 16; ++$y) {
            $indices .= $columnIndices;
        }

        return new self($palette, $indices);
    }

    public function biomeAt(int $localX, int $localY, int $localZ): Biome
    {
        return $this->palette[$this->paletteIndexAt($localX, $localY, $localZ)];
    }

    /** @return list<Biome> */
    public function palette(): array
    {
        return $this->palette;
    }

    public function paletteIndices(): string
    {
        return PackedPaletteIndices::unpack($this->packedPaletteIndices, $this->memoryBitsPerEntry);
    }

    /** @internal Canonical worker-transfer representation. */
    public function packedPaletteIndices(): string
    {
        return $this->packedPaletteIndices;
    }

    public function networkBitsPerEntry(): int
    {
        return $this->networkBitsPerEntry;
    }

    public function networkWordArray(): string
    {
        return $this->networkWordArray;
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return PackedPaletteIndices::trustedIndexAt(
            $this->packedPaletteIndices,
            $this->memoryBitsPerEntry,
            $localX + ($localZ * 16) + ($localY * 256),
        );
    }

    public function withBiome(int $localX, int $localY, int $localZ, Biome $biome): self
    {
        $offset = $this->offset($localX, $localY, $localZ);
        if ($this->palette[PackedPaletteIndices::trustedIndexAt(
            $this->packedPaletteIndices,
            $this->memoryBitsPerEntry,
            $offset,
        )]->identifier === $biome->identifier) {
            return $this;
        }

        $palette = $this->palette;
        $paletteIndex = null;
        foreach ($palette as $index => $candidate) {
            if ($candidate->identifier === $biome->identifier) {
                $paletteIndex = $index;
                break;
            }
        }
        if ($paletteIndex === null) {
            if (count($palette) >= self::MAX_PALETTE_SIZE) {
                throw new InvalidArgumentException('Biome palette cannot accept another biome.');
            }
            $paletteIndex = count($palette);
            $palette[] = $biome;
        }
        $newMemoryBits = PackedPaletteWords::bitsForPaletteSize(count($palette));
        $newBits = PackedPaletteWords::bitsForPaletteSize(count($palette), 2);
        $indices = null;
        $packedIndices = $this->packedPaletteIndices;
        if ($newMemoryBits === $this->memoryBitsPerEntry && $newMemoryBits !== 0) {
            $packedIndices = PackedPaletteIndices::replace($packedIndices, $newMemoryBits, $offset, $paletteIndex);
        } else {
            $indices = $this->paletteIndices();
            $indices[$offset] = self::encodePaletteIndex($paletteIndex);
            $packedIndices = PackedPaletteIndices::pack($indices, $newMemoryBits, validate: false);
        }
        $words = $newBits === $this->networkBitsPerEntry
            ? PackedPaletteWords::replaceNetworkIndex(
                $this->networkWordArray,
                $newBits,
                $localX,
                $localY,
                $localZ,
                $paletteIndex,
            )
            : PackedPaletteWords::packNetworkOrder(
                $indices ?? PackedPaletteIndices::unpack($packedIndices, $newMemoryBits),
                $newBits,
            );

        return new self($palette, null, $words, $packedIndices);
    }

    private function offset(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return $localX + ($localZ * 16) + ($localY * 256);
    }

    private static function validateLocalCoordinate(int $coordinate): void
    {
        if ($coordinate < 0 || $coordinate >= 16) {
            throw new InvalidArgumentException('Local biome coordinates must be between 0 and 15.');
        }
    }

    private static function encodePaletteIndex(int $index): string
    {
        if ($index < 0 || $index >= self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Biome palette index must fit an unsigned byte.');
        }

        return chr($index);
    }
}
