<?php

declare(strict_types=1);

namespace Bedriox\Server\World\Storage\LevelDb;

/** Private LevelDB namespace; it deliberately does not replace Mojang's entity-NBT chunk record. */
final class BedrioxEntityKey
{
    private const string PREFIX = "bedriox.entity.v1\x00";

    private function __construct() {}

    public static function chunk(int $chunkX, int $chunkZ): string
    {
        return self::PREFIX . LevelDbChunkKey::prefix($chunkX, $chunkZ);
    }
}
