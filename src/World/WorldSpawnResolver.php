<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

final class WorldSpawnResolver
{
    private function __construct() {}

    public static function resolve(WorldGenerator $generator, ?SpawnPosition $override = null): SpawnPosition
    {
        return $override ?? $generator->defaultSpawn();
    }
}
