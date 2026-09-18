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
    private const MAX_STORAGE_LAYERS = 255;

    /** @var non-empty-list<SubChunkBlockStorage> */
    private array $blockStorageLayers;

    /**
     * @param list<mixed> $blockStorageLayers
     */
    private function __construct(
        public int $sectionY,
        array $blockStorageLayers,
    ) {
        if ($sectionY < Chunk::MIN_SECTION_Y || $sectionY > Chunk::MAX_SECTION_Y) {
            throw new InvalidArgumentException('Subchunk Y is outside the supported overworld height.');
        }
        if ($blockStorageLayers === [] || count($blockStorageLayers) > self::MAX_STORAGE_LAYERS) {
            throw new InvalidArgumentException('Subchunk must contain between 1 and 255 block-storage layers.');
        }
        foreach ($blockStorageLayers as $storage) {
            if (!$storage instanceof SubChunkBlockStorage) {
                throw new InvalidArgumentException('Subchunk contains an invalid block-storage layer.');
            }
        }
        /** @var non-empty-list<SubChunkBlockStorage> $blockStorageLayers */
        $this->blockStorageLayers = $blockStorageLayers;
    }

    public static function uniform(int $sectionY, InternalBlockStateId $state): self
    {
        return new self($sectionY, [SubChunkBlockStorage::uniform($state)]);
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

        return new self($sectionY, [SubChunkBlockStorage::layered($layers)]);
    }

    /** @param list<mixed> $blockStorageLayers */
    public static function fromBlockStorageLayers(int $sectionY, array $blockStorageLayers): self
    {
        return new self($sectionY, $blockStorageLayers);
    }

    public function blockStateAt(int $localX, int $localY, int $localZ): InternalBlockStateId
    {
        return $this->blockStorageLayers[0]->blockStateAt($localX, $localY, $localZ);
    }

    /** @return list<InternalBlockStateId> */
    public function palette(): array
    {
        return $this->blockStorageLayers[0]->palette();
    }

    public function paletteIndexAt(int $localX, int $localY, int $localZ): int
    {
        return $this->blockStorageLayers[0]->paletteIndexAt($localX, $localY, $localZ);
    }

    /** @return non-empty-list<SubChunkBlockStorage> */
    public function blockStorageLayers(): array
    {
        return $this->blockStorageLayers;
    }

    public function blockStorageLayer(int $index): SubChunkBlockStorage
    {
        return $this->blockStorageLayers[$index]
            ?? throw new InvalidArgumentException('Subchunk block-storage layer does not exist.');
    }

    /** Returns a new immutable section with exactly one cell replaced. */
    public function withBlockState(
        int $localX,
        int $localY,
        int $localZ,
        InternalBlockStateId $state,
    ): self {
        return $this->withBlockStateInLayer(0, $localX, $localY, $localZ, $state);
    }

    public function withBlockStateInLayer(
        int $layer,
        int $localX,
        int $localY,
        int $localZ,
        InternalBlockStateId $state,
    ): self {
        $storage = $this->blockStorageLayer($layer);
        $replacement = $storage->withBlockState($localX, $localY, $localZ, $state);
        if ($replacement === $storage) {
            return $this;
        }
        $layers = $this->blockStorageLayers;
        $layers[$layer] = $replacement;

        return new self($this->sectionY, array_values($layers));
    }
}
