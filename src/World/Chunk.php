<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;
use InvalidArgumentException;

/** Immutable overworld chunk; missing sections contain the chunk's air state. */
final readonly class Chunk
{
    public const MIN_Y = -64;
    public const MAX_Y = 319;
    public const MIN_SECTION_Y = -4;
    public const MAX_SECTION_Y = 19;
    public const SECTION_COUNT = 24;

    /** @var array<int, SubChunk> */
    private array $sections;

    private Biome $biome;

    /** @param list<mixed> $sections */
    public function __construct(
        public ChunkPosition $position,
        private InternalBlockStateId $air,
        array $sections,
        ?Biome $biome = null,
    ) {
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

        return new self($this->position, $this->air, array_values($sections), $this->biome);
    }
}
