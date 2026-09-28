<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use Bedriox\Server\World\Block\InternalBlockStateId;

final readonly class VoidWorldGenerator implements VersionedWorldGenerator
{
    public const int VERSION = 1;
    public const string IDENTIFIER = 'bedriox:void';

    public function __construct(private InternalBlockStateId $air) {}

    public function name(): string
    {
        return self::IDENTIFIER;
    }

    public function version(): int
    {
        return self::VERSION;
    }

    public function generate(ChunkPosition $position): Chunk
    {
        return new Chunk($position, $this->air, []);
    }

    public function defaultSpawn(): SpawnPosition
    {
        return new SpawnPosition(0, 64, 0);
    }
}
