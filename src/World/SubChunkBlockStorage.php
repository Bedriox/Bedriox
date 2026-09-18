<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Immutable palette-backed block storage for one subchunk layer. */
final readonly class SubChunkBlockStorage
{
    public const BLOCK_COUNT = 4096;
    private const MAX_PALETTE_SIZE = 256;

    /** @var list<InternalBlockStateId> */
    private array $palette;

    /** One unsigned byte per block, indexed as x + (z * 16) + (y * 256). */
    private string $paletteIndices;

    /** @param list<mixed> $palette */
    private function __construct(array $palette, string $paletteIndices)
    {
        if ($palette === [] || count($palette) > self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Block-storage palette must contain between 1 and 256 states.');
        }
        if (strlen($paletteIndices) !== self::BLOCK_COUNT) {
            throw new InvalidArgumentException('Block-storage palette indices must contain exactly 4096 bytes.');
        }
        foreach ($palette as $state) {
            if (!$state instanceof InternalBlockStateId) {
                throw new InvalidArgumentException('Block-storage palette contains an invalid internal state ID.');
            }
        }
        $paletteSize = count($palette);
        for ($offset = 0; $offset < self::BLOCK_COUNT; ++$offset) {
            if (ord($paletteIndices[$offset]) >= $paletteSize) {
                throw new InvalidArgumentException('Block storage contains an out-of-range palette index.');
            }
        }
        $this->palette = $palette;
        $this->paletteIndices = $paletteIndices;
    }

    public static function uniform(InternalBlockStateId $state): self
    {
        return new self([$state], str_repeat("\x00", self::BLOCK_COUNT));
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
        return $this->paletteIndices;
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return ord($this->paletteIndices[$localX + ($localZ * SubChunk::EDGE_LENGTH) + ($localY * 256)]);
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
        if ($this->palette[ord($this->paletteIndices[$offset])]->value === $state->value) {
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
        $indices = $this->paletteIndices;
        $indices[$offset] = self::encodePaletteIndex($paletteIndex);

        return new self($palette, $indices);
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
