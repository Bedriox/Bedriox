<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn\Natural;

use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\World\ChunkPosition;
use InvalidArgumentException;

final readonly class NaturalSpawnContext
{
    public function __construct(
        public string $worldName,
        public ChunkPosition $chunk,
        public EntityType $type,
        public EntityCategory $category,
        public Position $position,
        public string $dimension,
        public string $biome,
        public NaturalSpawnMedium $medium,
        public int $lightLevel,
        public float $nearestPlayerDistanceSquared,
        public float $worldSpawnDistanceSquared,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $dimension) !== 1
            || preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $biome) !== 1
            || $lightLevel < 0 || $lightLevel > 15
            || !is_finite($nearestPlayerDistanceSquared) || $nearestPlayerDistanceSquared < 0.0
            || !is_finite($worldSpawnDistanceSquared) || $worldSpawnDistanceSquared < 0.0) {
            throw new InvalidArgumentException('Natural-spawn context is invalid.');
        }
    }
}
