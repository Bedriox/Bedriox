<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generator;

use Bedriox\Api\World\Generator\ChunkPosition as ApiChunkPosition;
use Bedriox\Api\World\Generator\GeneratedChunk;
use Bedriox\Api\World\Generator\Generator as ApiGenerator;
use Bedriox\Api\World\Generator\GeneratorContext as ApiGeneratorContext;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\World\Biome;
use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkFinalizationState;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\SubChunk;
use Bedriox\Server\World\SubChunkBlockStorage;
use Bedriox\Server\World\VersionedWorldGenerator;
use RuntimeException;

/** @internal Converts bounded canonical plugin output into the authoritative world model. */
final readonly class PluginWorldGeneratorAdapter implements VersionedWorldGenerator
{
    private ApiGenerator $generator;
    private ApiGeneratorContext $publicContext;

    public function __construct(
        private string $identifier,
        private int $generatorVersion,
        string $generatorClass,
        private GeneratorContext $context,
        ?WorkerGeneratorSource $workerSource = null,
    ) {
        $workerSource?->load();
        $generator = new $generatorClass();
        if (!$generator instanceof ApiGenerator) {
            throw new RuntimeException('Plugin generator class no longer implements the public generator contract.');
        }
        $this->generator = $generator;
        $this->publicContext = new ApiGeneratorContext(
            $context->seed,
            $context->dimension,
            $context->options->values(),
        );
    }

    public function name(): string
    {
        return $this->identifier;
    }

    public function version(): int
    {
        return $this->generatorVersion;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        $generated = $this->generator->generate(
            $this->publicContext,
            new ApiChunkPosition($position->x, $position->z),
        );
        $air = $this->context->blockStates->internalId(CanonicalBlockState::from('minecraft:air'));
        $sections = [];
        foreach ($generated->sections as $section) {
            $palette = [];
            foreach ($section->palette as $state) {
                $palette[] = $this->context->blockStates->internalId(CanonicalBlockState::from(
                    $state->identifier,
                    $state->properties,
                ));
            }
            $sections[] = SubChunk::fromBlockStorageLayers($section->sectionY, [
                SubChunkBlockStorage::fromPaletteIndices($palette, $section->paletteIndices),
            ]);
        }

        return new Chunk(
            $position,
            $air,
            $sections,
            new Biome($generated->biome),
            finalizationState: ChunkFinalizationState::Done,
        );
    }

    public function defaultSpawn(): SpawnPosition
    {
        $spawn = $this->generator->defaultSpawn($this->publicContext);

        return new SpawnPosition($spawn->x, $spawn->y, $spawn->z);
    }
}
