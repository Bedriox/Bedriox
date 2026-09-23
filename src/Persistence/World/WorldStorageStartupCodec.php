<?php

declare(strict_types=1);

namespace Bedriox\Server\Persistence\World;

use JsonException;
use RuntimeException;

final readonly class WorldStorageStartupCodec
{
    public function __construct(private WorldDataIpcCodec $worldData = new WorldDataIpcCodec()) {}

    public function encode(WorldStorageStartup $startup): string
    {
        try {
            return json_encode([
                'created_at' => $startup->createdAt,
                'mode' => $startup->mode,
                'path' => $startup->path,
                'version' => 1,
                'world_data' => $startup->createData === null ? null : base64_encode($this->worldData->encode($startup->createData)),
            ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $error) {
            throw new RuntimeException('World storage startup could not be encoded.', previous: $error);
        }
    }

    public function decode(string $encoded): WorldStorageStartup
    {
        if (strlen($encoded) > 12_288) {
            throw new RuntimeException('World storage startup exceeds its size limit.');
        }
        try {
            $value = json_decode($encoded, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('World storage startup is malformed.', previous: $error);
        }
        if (!is_array($value) || array_keys($value) !== ['created_at', 'mode', 'path', 'version', 'world_data']
            || $value['version'] !== 1 || !is_string($value['mode']) || !is_string($value['path'])
            || (!is_int($value['created_at']) && $value['created_at'] !== null)
            || (!is_string($value['world_data']) && $value['world_data'] !== null)) {
            throw new RuntimeException('World storage startup has an invalid shape.');
        }
        $worldData = null;
        if (is_string($value['world_data'])) {
            $bytes = base64_decode($value['world_data'], true);
            if (!is_string($bytes)) {
                throw new RuntimeException('World storage startup contains invalid world data.');
            }
            $worldData = $this->worldData->decode($bytes);
        }

        return new WorldStorageStartup($value['mode'], $value['path'], $worldData, $value['created_at']);
    }
}
