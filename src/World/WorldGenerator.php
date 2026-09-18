<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

interface WorldGenerator
{
    public function name(): string;

    public function generate(ChunkPosition $position): Chunk;

    public function defaultSpawn(): SpawnPosition;
}
