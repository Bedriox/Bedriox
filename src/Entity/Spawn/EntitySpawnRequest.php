<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity\Spawn;

use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final readonly class EntitySpawnRequest
{
    public function __construct(
        public EntityType $type,
        public SpawnCause $cause,
        public string $worldName,
        public Position $position,
        public float $yaw = 0.0,
        public float $pitch = 0.0,
        public ?string $uniqueId = null,
    ) {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('Spawn world name must be valid UTF-8 and bounded.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || $position->y < -2_048.0 || $position->y > 2_048.0
            || !is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Spawn transform is outside its supported bounds.');
        }
    }
}
