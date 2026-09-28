<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Generator;

use Bedriox\Server\World\Chunk;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\SpawnPosition;
use Bedriox\Server\World\VersionedWorldGenerator;
use Closure;
use RuntimeException;

/** Keeps normal generation on workers while retaining a lazy built-in recovery path. */
final class DeferredWorldGenerator implements VersionedWorldGenerator
{
    private ?VersionedWorldGenerator $resolved = null;

    /** @param Closure(): VersionedWorldGenerator $resolver */
    public function __construct(
        private readonly string $generatorName,
        private readonly int $generatorVersion,
        private readonly SpawnPosition $spawn,
        private readonly Closure $resolver,
    ) {}

    public function name(): string
    {
        return $this->generatorName;
    }

    public function version(): int
    {
        return $this->generatorVersion;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return $this->resolve()->generate($position);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return $this->spawn;
    }

    private function resolve(): VersionedWorldGenerator
    {
        if ($this->resolved instanceof VersionedWorldGenerator) {
            return $this->resolved;
        }
        $resolved = ($this->resolver)();
        if ($resolved->name() !== $this->generatorName || $resolved->version() !== $this->generatorVersion) {
            throw new RuntimeException('Deferred generator metadata changed after world preparation.');
        }

        return $this->resolved = $resolved;
    }
}
