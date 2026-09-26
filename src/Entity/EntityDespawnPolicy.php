<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\SpawnCause;

/** Durable ownership of automatic entity removal. */
enum EntityDespawnPolicy: string
{
    case EXPLICIT_ONLY = 'explicit_only';
    case NATURAL_DISTANCE = 'natural_distance';

    public static function forSpawnCause(SpawnCause $cause): self
    {
        return $cause === SpawnCause::NATURAL ? self::NATURAL_DISTANCE : self::EXPLICIT_ONLY;
    }
}
