<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Immutable palette-backed 16 x 16 x 16 section containing only Bedriox-owned state IDs. */
final readonly class SubChunk
{
    public const EDGE_LENGTH = 16;
    public const BLOCK_COUNT = 4096;
    private const MAX_PALETTE_SIZE = 256;

    /** @var list<InternalBlockStateId> */
    private array $palette;

    /** One unsigned byte per block, indexed as x + (z * 16) + (y * 256). */
    private string $paletteIndices;

    /**
     * @param list<mixed> $palette
     */
    private function __construct(
        public int $sectionY,
        array $palette,
        string $paletteIndices,
    ) {
        if ($sectionY < Chunk::MIN_SECTION_Y || $sectionY > Chunk::MAX_SECTION_Y) {
            throw new InvalidArgumentException('Subchunk Y is outside the supported overworld height.');
        }
        if ($palette === [] || count($palette) > self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Subchunk palette must contain between 1 and 256 states.');
        }
        if (strlen($paletteIndices) !== self::BLOCK_COUNT) {
            throw new InvalidArgumentException('Subchunk palette-index storage must contain exactly 4096 bytes.');
        }
        foreach ($palette as $state) {
            if (!$state instanceof InternalBlockStateId) {
                throw new InvalidArgumentException('Subchunk palette contains an invalid internal state ID.');
            }
        }
        $paletteSize = count($palette);
        for ($offset = 0; $offset < self::BLOCK_COUNT; ++$offset) {
            if (ord($paletteIndices[$offset]) >= $paletteSize) {
                throw new InvalidArgumentException('Subchunk contains an out-of-range palette index.');
            }
        }
        $this->palette = $palette;
        $this->paletteIndices = $paletteIndices;
    }

    public static function uniform(int $sectionY, InternalBlockStateId $state): self
    {
        return new self($sectionY, [$state], str_repeat("\x00", self::BLOCK_COUNT));
    }

    /**
     * Builds a section from 16 uniform horizontal layers ordered from local Y=0 through local Y=15.
     *
     * @param list<mixed> $layers
     */
    public static function layered(int $sectionY, array $layers): self
    {
        if (count($layers) !== self::EDGE_LENGTH) {
            throw new InvalidArgumentException('A layered subchunk must define exactly 16 layers.');
        }

        $palette = [];
        $indicesByStateId = [];
        $indices = '';
        foreach ($layers as $state) {
            if (!$state instanceof InternalBlockStateId) {
                throw new InvalidArgumentException('A subchunk layer contains an invalid internal state ID.');
            }
            $stateKey = (string) $state->value;
            $paletteIndex = $indicesByStateId[$stateKey] ?? null;
            if (!is_int($paletteIndex)) {
                $paletteIndex = count($palette);
                $palette[] = $state;
                $indicesByStateId[$stateKey] = $paletteIndex;
            }
            $indices .= str_repeat(chr($paletteIndex), self::EDGE_LENGTH * self::EDGE_LENGTH);
        }

        return new self($sectionY, $palette, $indices);
    }

    public function blockStateAt(int $localX, int $localY, int $localZ): InternalBlockStateId
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);
        $offset = $localX + ($localZ * self::EDGE_LENGTH) + ($localY * self::EDGE_LENGTH * self::EDGE_LENGTH);

        return $this->palette[ord($this->paletteIndices[$offset])];
    }

    /** @return list<InternalBlockStateId> */
    public function palette(): array
    {
        return $this->palette;
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return ord($this->paletteIndices[$localX + ($localZ * self::EDGE_LENGTH) + ($localY * 256)]);
    }

    /** Returns a new immutable section with exactly one cell replaced. */
    public function withBlockState(
        int $localX,
        int $localY,
        int $localZ,
        InternalBlockStateId $state,
    ): self {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);
        $offset = $localX + ($localZ * self::EDGE_LENGTH) + ($localY * 256);
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
                throw new InvalidArgumentException('Subchunk palette cannot accept another state.');
            }
            $paletteIndex = count($palette);
            $palette[] = $state;
        }
        if ($paletteIndex > 0xff) {
            throw new InvalidArgumentException('Subchunk palette index cannot fit its byte storage.');
        }
        $indices = $this->paletteIndices;
        $indices[$offset] = chr($paletteIndex);

        return new self($this->sectionY, $palette, $indices);
    }

    private static function validateLocalCoordinate(int $coordinate): void
    {
        if ($coordinate < 0 || $coordinate >= self::EDGE_LENGTH) {
            throw new InvalidArgumentException('Local subchunk coordinates must be between 0 and 15.');
        }
    }
}
