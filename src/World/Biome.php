<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

/** Canonical biome identity owned by the world model, never a Bedrock network runtime ID. */
final readonly class Biome
{
    public function __construct(public string $identifier)
    {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $identifier) !== 1 || strlen($identifier) > 128) {
            throw new InvalidArgumentException('Biome identifier must be a bounded canonical namespaced identifier.');
        }
    }

    public static function plains(): self
    {
        return new self('minecraft:plains');
    }

    public static function forest(): self
    {
        return new self('minecraft:forest');
    }

    public static function desert(): self
    {
        return new self('minecraft:desert');
    }

    public static function ocean(): self
    {
        return new self('minecraft:ocean');
    }

    public static function hills(): self
    {
        return new self('minecraft:extreme_hills');
    }
}
