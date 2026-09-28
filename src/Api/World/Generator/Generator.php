<?php

declare(strict_types=1);

namespace Bedriox\Api\World\Generator;

/**
 * A deterministic, side-effect-free world generator.
 *
 * Implementations must depend only on the supplied context and chunk position.
 * They must not access players, live worlds, sockets, global mutable state, files,
 * clocks, or random sources not derived from the supplied seed.
 */
interface Generator
{
    public function generate(GeneratorContext $context, ChunkPosition $position): GeneratedChunk;

    public function defaultSpawn(GeneratorContext $context): GeneratorSpawn;
}
