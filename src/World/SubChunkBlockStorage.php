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

use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Immutable palette-backed block storage for one subchunk layer. */
final class SubChunkBlockStorage
{
    public const BLOCK_COUNT = 4096;
    private const MAX_PALETTE_SIZE = 256;

    /** @var list<InternalBlockStateId> */
    private array $palette;

    /** Bit-packed x + (z * 16) + (y * 256) memory-order palette indices. */
    private string $packedPaletteIndices;

    private int $networkBitsPerEntry;

    /** Bedrock X-Z-Y ordered little-endian words. */
    private ?string $networkWordArray;

    /** @param list<mixed> $palette */
    private function __construct(
        array $palette,
        ?string $paletteIndices,
        ?string $networkWordArray = null,
        bool $validateIndices = true,
        ?string $packedPaletteIndices = null,
    ) {
        if ($palette === [] || count($palette) > self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Block-storage palette must contain between 1 and 256 states.');
        }
        if ($paletteIndices === null && $packedPaletteIndices === null && count($palette) !== 1) {
            throw new InvalidArgumentException('Only uniform block storage may omit palette indices.');
        }
        if ($paletteIndices !== null && strlen($paletteIndices) !== self::BLOCK_COUNT) {
            throw new InvalidArgumentException('Block-storage palette indices must contain exactly 4096 bytes.');
        }
        foreach ($palette as $state) {
            if (!$state instanceof InternalBlockStateId) {
                throw new InvalidArgumentException('Block-storage palette contains an invalid internal state ID.');
            }
        }
        $paletteSize = count($palette);
        if ($validateIndices && $paletteIndices !== null) {
            for ($offset = 0; $offset < self::BLOCK_COUNT; ++$offset) {
                if (ord($paletteIndices[$offset]) >= $paletteSize) {
                    throw new InvalidArgumentException('Block storage contains an out-of-range palette index.');
                }
            }
        }
        $this->palette = $palette;
        $this->networkBitsPerEntry = PackedPaletteWords::bitsForPaletteSize($paletteSize);
        if ($packedPaletteIndices !== null
            && strlen($packedPaletteIndices) !== PackedPaletteIndices::bytesFor($this->networkBitsPerEntry)) {
            throw new InvalidArgumentException('Packed block-storage indices have an invalid length.');
        }
        $this->packedPaletteIndices = $packedPaletteIndices ?? PackedPaletteIndices::pack(
            $paletteIndices ?? str_repeat("\x00", self::BLOCK_COUNT),
            $this->networkBitsPerEntry,
            $validateIndices,
        );
        $this->networkWordArray = $networkWordArray;
        $expectedWords = $this->networkBitsPerEntry === 0 ? 0 : intdiv(
            self::BLOCK_COUNT + intdiv(32, $this->networkBitsPerEntry) - 1,
            intdiv(32, $this->networkBitsPerEntry),
        ) * 4;
        if ($this->networkWordArray !== null && strlen($this->networkWordArray) !== $expectedWords) {
            throw new InvalidArgumentException('Block-storage packed word array has an invalid length.');
        }
    }

    public static function uniform(InternalBlockStateId $state): self
    {
        return new self([$state], null);
    }

    /** @param list<mixed> $layers */
    public static function layered(array $layers): self
    {
        if (count($layers) !== SubChunk::EDGE_LENGTH) {
            throw new InvalidArgumentException('A layered block storage must define exactly 16 layers.');
        }

        $palette = [];
        $indicesByStateId = [];
        $indices = '';
        foreach ($layers as $state) {
            if (!$state instanceof InternalBlockStateId) {
                throw new InvalidArgumentException('A block-storage layer contains an invalid internal state ID.');
            }
            $stateKey = (string) $state->value;
            $paletteIndex = $indicesByStateId[$stateKey] ?? null;
            if (!is_int($paletteIndex)) {
                $paletteIndex = count($palette);
                $palette[] = $state;
                $indicesByStateId[$stateKey] = $paletteIndex;
            }
            $indices .= str_repeat(self::encodePaletteIndex($paletteIndex), SubChunk::EDGE_LENGTH * SubChunk::EDGE_LENGTH);
        }

        return new self($palette, $indices);
    }

    /** @param list<mixed> $palette */
    public static function fromPaletteIndices(array $palette, string $paletteIndices): self
    {
        return new self($palette, $paletteIndices);
    }

    /** @param list<mixed> $palette Palette indices already validated by a trusted builder. */
    public static function fromTrustedPaletteIndices(array $palette, string $paletteIndices): self
    {
        return new self($palette, $paletteIndices, validateIndices: false);
    }

    /** @param list<mixed> $palette */
    public static function fromPackedPaletteIndices(array $palette, string $packedPaletteIndices): self
    {
        $bits = PackedPaletteWords::bitsForPaletteSize(count($palette));
        if (strlen($packedPaletteIndices) !== PackedPaletteIndices::bytesFor($bits)) {
            throw new InvalidArgumentException('Packed block-storage indices have an invalid length.');
        }
        for ($offset = 0; $offset < self::BLOCK_COUNT; ++$offset) {
            if (PackedPaletteIndices::trustedIndexAt($packedPaletteIndices, $bits, $offset) >= count($palette)) {
                throw new InvalidArgumentException('Packed block storage contains an out-of-range palette index.');
            }
        }

        return new self(
            $palette,
            null,
            validateIndices: false,
            packedPaletteIndices: $packedPaletteIndices,
        );
    }

    public function blockStateAt(int $localX, int $localY, int $localZ): InternalBlockStateId
    {
        return $this->palette[$this->paletteIndexAt($localX, $localY, $localZ)];
    }

    /** @return list<InternalBlockStateId> */
    public function palette(): array
    {
        return $this->palette;
    }

    public function paletteIndices(): string
    {
        return PackedPaletteIndices::unpack($this->packedPaletteIndices, $this->networkBitsPerEntry);
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
        return $this->networkWordArray ??= PackedPaletteWords::packNetworkOrderFromPackedMemory(
            $this->packedPaletteIndices,
            $this->networkBitsPerEntry,
            $this->networkBitsPerEntry,
        );
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return PackedPaletteIndices::trustedIndexAt(
            $this->packedPaletteIndices,
            $this->networkBitsPerEntry,
            $localX + ($localZ * SubChunk::EDGE_LENGTH) + ($localY * 256),
        );
    }

    public function withBlockState(
        int $localX,
        int $localY,
        int $localZ,
        InternalBlockStateId $state,
    ): self {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);
        $offset = $localX + ($localZ * SubChunk::EDGE_LENGTH) + ($localY * 256);
        if ($this->palette[PackedPaletteIndices::trustedIndexAt(
            $this->packedPaletteIndices,
            $this->networkBitsPerEntry,
            $offset,
        )]->value === $state->value) {
            return $this;
        }

        $palette = $this->palette;
        $paletteIndex = null;
        foreach ($palette as $index => $candidate) {
            if ($candidate->value === $state->value) {
                $paletteIndex = $index;
                break;
            }
        }
        if ($paletteIndex === null) {
            if (count($palette) >= self::MAX_PALETTE_SIZE) {
                throw new InvalidArgumentException('Block-storage palette cannot accept another state.');
            }
            $paletteIndex = count($palette);
            $palette[] = $state;
        }
        $newBits = PackedPaletteWords::bitsForPaletteSize(count($palette));
        $indices = null;
        $packedIndices = $this->packedPaletteIndices;
        if ($newBits === $this->networkBitsPerEntry && $newBits !== 0) {
            $packedIndices = PackedPaletteIndices::replace($packedIndices, $newBits, $offset, $paletteIndex);
        } else {
            $indices = $this->paletteIndices();
            $indices[$offset] = self::encodePaletteIndex($paletteIndex);
            $packedIndices = PackedPaletteIndices::pack($indices, $newBits, validate: false);
        }
        $words = $newBits === $this->networkBitsPerEntry && $newBits !== 0
            && $this->networkWordArray !== null
            ? PackedPaletteWords::replaceNetworkIndex(
                $this->networkWordArray,
                $newBits,
                $localX,
                $localY,
                $localZ,
                $paletteIndex,
            )
            : null;

        return new self(
            $palette,
            null,
            $words,
            validateIndices: false,
            packedPaletteIndices: $packedIndices,
        );
    }

    private static function validateLocalCoordinate(int $coordinate): void
    {
        if ($coordinate < 0 || $coordinate >= SubChunk::EDGE_LENGTH) {
            throw new InvalidArgumentException('Local block-storage coordinates must be between 0 and 15.');
        }
    }

    private static function encodePaletteIndex(int $index): string
    {
        if ($index < 0 || $index >= self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Block-storage palette index must fit an unsigned byte.');
        }

        return chr($index);
    }
}
