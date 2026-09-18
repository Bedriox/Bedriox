<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\FixedFlatBlockPalette;

/** Deterministic fixed-flat generator: bedrock at 60, dirt at 61-62, grass at 63. */
final readonly class FlatWorldGenerator implements WorldGenerator
{
    public function __construct(private FixedFlatBlockPalette $palette) {}

    public function name(): string
    {
        return 'flat';
    }

    public function generate(ChunkPosition $position): Chunk
    {
        $layers = array_fill(0, 16, $this->palette->air);
        $layers[12] = $this->palette->bedrock;
        $layers[13] = $this->palette->dirt;
        $layers[14] = $this->palette->dirt;
        $layers[15] = $this->palette->grassBlock;

        return new Chunk($position, $this->palette->air, [SubChunk::layered(3, $layers)]);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}
