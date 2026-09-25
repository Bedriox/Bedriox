<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockEntity\BlockEntity;
use Bedriox\Server\World\BlockEntity\BlockEntityCollection;
use InvalidArgumentException;

/** Immutable overworld chunk; missing sections contain the chunk's air state. */
final readonly class Chunk
{
    public const DIRTY_NONE = 0;
    public const DIRTY_BLOCKS = 1 << 0;
    public const DIRTY_BIOMES = 1 << 1;
    public const DIRTY_FINALIZATION = 1 << 2;
    public const DIRTY_BLOCK_ENTITIES = 1 << 3;
    public const DIRTY_ALL = self::DIRTY_BLOCKS
        | self::DIRTY_BIOMES
        | self::DIRTY_FINALIZATION
        | self::DIRTY_BLOCK_ENTITIES;

    public const MIN_Y = -64;
    public const MAX_Y = 319;
    public const MIN_SECTION_Y = -4;
    public const MAX_SECTION_Y = 19;
    public const SECTION_COUNT = 24;

    /** @var array<int, SubChunk> */
    private array $sections;

    private Biome $biome;

    private BiomeStorage $defaultBiomeStorage;

    /** @var array<int, BiomeStorage> */
    private array $biomeStorages;

    private BlockEntityCollection $blockEntities;

    /**
     * @param list<mixed>       $sections
     * @param array<int, mixed> $biomeStorages
     */
    public function __construct(
        public ChunkPosition $position,
        private InternalBlockStateId $air,
        array $sections,
        ?Biome $biome = null,
        public int $revision = 0,
        public int $persistedRevision = 0,
        public int $dirtyFlags = self::DIRTY_NONE,
        public ChunkFinalizationState $finalizationState = ChunkFinalizationState::Done,
        array $biomeStorages = [],
        ?BlockEntityCollection $blockEntities = null,
    ) {
        if ($revision < 0 || $persistedRevision < 0 || $persistedRevision > $revision) {
            throw new InvalidArgumentException('Chunk revisions must be non-negative and persisted revision cannot lead revision.');
        }
        if (($dirtyFlags & ~self::DIRTY_ALL) !== 0) {
            throw new InvalidArgumentException('Chunk contains unsupported dirty flags.');
        }
        $indexed = [];
        foreach ($sections as $section) {
            if (!$section instanceof SubChunk) {
                throw new InvalidArgumentException('Chunk contains an invalid subchunk.');
            }
            if (isset($indexed[$section->sectionY])) {
                throw new InvalidArgumentException('Chunk contains a duplicate subchunk Y.');
            }
            $indexed[$section->sectionY] = $section;
        }
        ksort($indexed, SORT_NUMERIC);
        $this->sections = $indexed;
        $this->biome = $biome ?? Biome::plains();
        $this->defaultBiomeStorage = BiomeStorage::uniform($this->biome);

        $indexedBiomes = [];
        foreach ($biomeStorages as $sectionY => $storage) {
            if ($sectionY < self::MIN_SECTION_Y || $sectionY > self::MAX_SECTION_Y) {
                throw new InvalidArgumentException('Chunk contains a biome storage outside the supported height.');
            }
            if (!$storage instanceof BiomeStorage) {
                throw new InvalidArgumentException('Chunk contains an invalid biome storage.');
            }
            $indexedBiomes[$sectionY] = $storage;
        }
        ksort($indexedBiomes, SORT_NUMERIC);
        $this->biomeStorages = $indexedBiomes;
        if ($blockEntities !== null && ($blockEntities->position->x !== $position->x
            || $blockEntities->position->z !== $position->z)) {
            throw new InvalidArgumentException('Block-entity collection belongs to a different chunk.');
        }
        $this->blockEntities = $blockEntities ?? new BlockEntityCollection($position);
    }

    public function blockStateAt(int $localX, int $y, int $localZ): InternalBlockStateId
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            throw new InvalidArgumentException('Local chunk coordinates must be between 0 and 15.');
        }
        if ($y < self::MIN_Y || $y > self::MAX_Y) {
            throw new InvalidArgumentException('Block Y is outside the supported overworld height.');
        }
        $sectionY = $y >> 4;
        $section = $this->sections[$sectionY] ?? null;

        return $section?->blockStateAt($localX, $y & 0x0f, $localZ) ?? $this->air;
    }

    public function section(int $sectionY): ?SubChunk
    {
        if ($sectionY < self::MIN_SECTION_Y || $sectionY > self::MAX_SECTION_Y) {
            throw new InvalidArgumentException('Subchunk Y is outside the supported overworld height.');
        }

        return $this->sections[$sectionY] ?? null;
    }

    /** @return list<SubChunk> */
    public function populatedSections(): array
    {
        return array_values($this->sections);
    }

    public function airState(): InternalBlockStateId
    {
        return $this->air;
    }

    public function biome(): Biome
    {
        return $this->biome;
    }

    public function biomeAt(int $localX, int $y, int $localZ): Biome
    {
        self::validateCoordinates($localX, $y, $localZ);

        return $this->biomeStorage($y >> 4)->biomeAt($localX, $y & 0x0f, $localZ);
    }

    public function biomeStorage(int $sectionY): BiomeStorage
    {
        if ($sectionY < self::MIN_SECTION_Y || $sectionY > self::MAX_SECTION_Y) {
            throw new InvalidArgumentException('Biome-storage Y is outside the supported overworld height.');
        }

        return $this->biomeStorages[$sectionY] ?? $this->defaultBiomeStorage;
    }

    /** @return array<int, BiomeStorage> */
    public function biomeStorages(): array
    {
        $storages = [];
        for ($sectionY = self::MIN_SECTION_Y; $sectionY <= self::MAX_SECTION_Y; ++$sectionY) {
            $storages[$sectionY] = $this->biomeStorage($sectionY);
        }

        return $storages;
    }

    public function isDirty(): bool
    {
        return $this->dirtyFlags !== self::DIRTY_NONE;
    }

    public function hasDirtyFlag(int $flag): bool
    {
        if ($flag === self::DIRTY_NONE || ($flag & ~self::DIRTY_ALL) !== 0) {
            throw new InvalidArgumentException('Unknown chunk dirty flag.');
        }

        return ($this->dirtyFlags & $flag) !== 0;
    }

    /** Returns a new immutable chunk with exactly one local cell replaced. */
    public function withBlockState(int $localX, int $y, int $localZ, InternalBlockStateId $state): self
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            throw new InvalidArgumentException('Local chunk coordinates must be between 0 and 15.');
        }
        if ($y < self::MIN_Y || $y > self::MAX_Y) {
            throw new InvalidArgumentException('Block Y is outside the supported overworld height.');
        }
        $sectionY = $y >> 4;
        $section = $this->sections[$sectionY] ?? SubChunk::uniform($sectionY, $this->air);
        $replacement = $section->withBlockState($localX, $y & 0x0f, $localZ, $state);
        if ($replacement === $section) {
            return $this;
        }
        $sections = $this->sections;
        $sections[$sectionY] = $replacement;

        return new self(
            $this->position,
            $this->air,
            array_values($sections),
            $this->biome,
            $this->revision + 1,
            $this->persistedRevision,
            $this->dirtyFlags | self::DIRTY_BLOCKS,
            $this->finalizationState,
            $this->biomeStorages,
            $this->blockEntities,
        );
    }

    public function withBiome(int $localX, int $y, int $localZ, Biome $biome): self
    {
        self::validateCoordinates($localX, $y, $localZ);
        $sectionY = $y >> 4;
        $storage = $this->biomeStorage($sectionY);
        $replacement = $storage->withBiome($localX, $y & 0x0f, $localZ, $biome);
        if ($replacement === $storage) {
            return $this;
        }
        $biomeStorages = $this->biomeStorages;
        $biomeStorages[$sectionY] = $replacement;

        return new self(
            $this->position,
            $this->air,
            array_values($this->sections),
            $this->biome,
            $this->revision + 1,
            $this->persistedRevision,
            $this->dirtyFlags | self::DIRTY_BIOMES,
            $this->finalizationState,
            $biomeStorages,
            $this->blockEntities,
        );
    }

    public function withFinalizationState(ChunkFinalizationState $state): self
    {
        if ($state === $this->finalizationState) {
            return $this;
        }

        return new self(
            $this->position,
            $this->air,
            array_values($this->sections),
            $this->biome,
            $this->revision + 1,
            $this->persistedRevision,
            $this->dirtyFlags | self::DIRTY_FINALIZATION,
            $state,
            $this->biomeStorages,
            $this->blockEntities,
        );
    }

    public function blockEntityAt(BlockPosition $position): ?BlockEntity
    {
        return $this->blockEntities->at($position);
    }

    /** @return list<BlockEntity> */
    public function blockEntities(): array
    {
        return $this->blockEntities->all();
    }

    public function blockEntityCollection(): BlockEntityCollection
    {
        return $this->blockEntities;
    }

    public function withBlockEntity(BlockEntity $blockEntity): self
    {
        $replacement = $this->blockEntities->with($blockEntity);
        if ($replacement === $this->blockEntities) {
            return $this;
        }

        return new self(
            $this->position,
            $this->air,
            array_values($this->sections),
            $this->biome,
            self::nextRevision($this->revision),
            $this->persistedRevision,
            $this->dirtyFlags | self::DIRTY_BLOCK_ENTITIES,
            $this->finalizationState,
            $this->biomeStorages,
            $replacement,
        );
    }

    public function withoutBlockEntity(BlockPosition $position): self
    {
        $replacement = $this->blockEntities->without($position);
        if ($replacement === $this->blockEntities) {
            return $this;
        }

        return new self(
            $this->position,
            $this->air,
            array_values($this->sections),
            $this->biome,
            self::nextRevision($this->revision),
            $this->persistedRevision,
            $this->dirtyFlags | self::DIRTY_BLOCK_ENTITIES,
            $this->finalizationState,
            $this->biomeStorages,
            $replacement,
        );
    }

    /** Returns a snapshot acknowledging a successful save of the supplied revision. */
    public function withPersistedRevision(int $savedRevision): self
    {
        if ($savedRevision < $this->persistedRevision || $savedRevision > $this->revision) {
            throw new InvalidArgumentException('Saved revision is outside this chunk snapshot.');
        }
        if ($savedRevision === $this->persistedRevision) {
            return $this;
        }

        return new self(
            $this->position,
            $this->air,
            array_values($this->sections),
            $this->biome,
            $this->revision,
            $savedRevision,
            $savedRevision === $this->revision ? self::DIRTY_NONE : $this->dirtyFlags,
            $this->finalizationState,
            $this->biomeStorages,
            $this->blockEntities,
        );
    }

    private static function nextRevision(int $revision): int
    {
        if ($revision === PHP_INT_MAX) {
            throw new \OverflowException('Chunk revision space is exhausted.');
        }

        return $revision + 1;
    }

    private static function validateCoordinates(int $localX, int $y, int $localZ): void
    {
        if ($localX < 0 || $localX >= 16 || $localZ < 0 || $localZ >= 16) {
            throw new InvalidArgumentException('Local chunk coordinates must be between 0 and 15.');
        }
        if ($y < self::MIN_Y || $y > self::MAX_Y) {
            throw new InvalidArgumentException('Block Y is outside the supported overworld height.');
        }
    }
}
