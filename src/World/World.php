<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;

final readonly class World
{
    private BlockOverrideStore $overrides;

    public function __construct(
        public WorldMetadata $metadata,
        private WorldGenerator $generator,
        private ChunkRepository $chunks,
        private ?SpawnPosition $spawnOverride = null,
        ?BlockOverrideStore $overrides = null,
    ) {
        $this->overrides = $overrides ?? new BlockOverrideStore();
    }

    public function chunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->get($position, $this->loadChunk(...));
    }

    public function retainChunk(ChunkPosition $position): Chunk
    {
        return $this->chunks->retain($position, $this->loadChunk(...));
    }

    public function releaseChunk(ChunkPosition $position): void
    {
        $this->chunks->release($position);
    }

    public function blockStateAt(int $x, int $y, int $z): InternalBlockStateId
    {
        $position = self::chunkPosition($x, $z);

        return $this->chunk($position)->blockStateAt(self::localCoordinate($x), $y, self::localCoordinate($z));
    }

    /** Atomically replaces one process-local block state and returns its previous value. */
    public function setBlockState(int $x, int $y, int $z, InternalBlockStateId $state): InternalBlockStateId
    {
        $position = self::chunkPosition($x, $z);
        $chunk = $this->chunk($position);
        $localX = self::localCoordinate($x);
        $localZ = self::localCoordinate($z);
        $previous = $chunk->blockStateAt($localX, $y, $localZ);
        if ($previous->value !== $state->value) {
            $this->overrides->set($position, $localX, $y, $localZ, $state);
            $this->chunks->replace($chunk->withBlockState($localX, $y, $localZ, $state));
        }

        return $previous;
    }

    public function spawn(): SpawnPosition
    {
        return WorldSpawnResolver::resolve($this->generator, $this->spawnOverride);
    }

    public function generatorName(): string
    {
        return $this->generator->name();
    }

    private static function chunkPosition(int $x, int $z): ChunkPosition
    {
        return new ChunkPosition((int) floor($x / 16.0), (int) floor($z / 16.0));
    }

    private static function localCoordinate(int $coordinate): int
    {
        return (($coordinate % 16) + 16) % 16;
    }

    private function loadChunk(ChunkPosition $position): Chunk
    {
        $chunk = $this->generator->generate($position);
        foreach ($this->overrides->forChunk($position) as $override) {
            $chunk = $chunk->withBlockState(
                $override['x'],
                $override['y'],
                $override['z'],
                $override['state'],
            );
        }

        return $chunk;
    }
}
