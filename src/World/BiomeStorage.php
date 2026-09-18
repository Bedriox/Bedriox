<?php

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

    private string $paletteIndices;

    /** @param list<mixed> $palette */
    private function __construct(array $palette, string $paletteIndices)
    {
        if ($palette === [] || count($palette) > self::MAX_PALETTE_SIZE) {
            throw new InvalidArgumentException('Biome palette must contain between 1 and 256 biomes.');
        }
        if (strlen($paletteIndices) !== self::BIOME_COUNT) {
            throw new InvalidArgumentException('Biome palette indices must contain exactly 4096 bytes.');
        }
        foreach ($palette as $biome) {
            if (!$biome instanceof Biome) {
                throw new InvalidArgumentException('Biome palette contains an invalid biome.');
            }
        }
        $paletteSize = count($palette);
        for ($offset = 0; $offset < self::BIOME_COUNT; ++$offset) {
            if (ord($paletteIndices[$offset]) >= $paletteSize) {
                throw new InvalidArgumentException('Biome storage contains an out-of-range palette index.');
            }
        }
        $this->palette = $palette;
        $this->paletteIndices = $paletteIndices;
    }

    public static function uniform(Biome $biome): self
    {
        return new self([$biome], str_repeat("\x00", self::BIOME_COUNT));
    }

    /** @param list<mixed> $palette */
    public static function fromPaletteIndices(array $palette, string $paletteIndices): self
    {
        return new self($palette, $paletteIndices);
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
        return $this->paletteIndices;
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        self::validateLocalCoordinate($localX);
        self::validateLocalCoordinate($localY);
        self::validateLocalCoordinate($localZ);

        return ord($this->paletteIndices[$localX + ($localZ * 16) + ($localY * 256)]);
    }

    public function withBiome(int $localX, int $localY, int $localZ, Biome $biome): self
    {
        $offset = $this->offset($localX, $localY, $localZ);
        if ($this->palette[ord($this->paletteIndices[$offset])]->identifier === $biome->identifier) {
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
        $indices = $this->paletteIndices;
        $indices[$offset] = self::encodePaletteIndex($paletteIndex);

        return new self($palette, $indices);
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
