<?php

declare(strict_types=1);

namespace Bedriox\Server\World;

use InvalidArgumentException;

/** Qualified legacy biome IDs used by Bedrock chunk storage and protocol 2193 columns. */
final class BiomeRuntimeIdMap
{
    private const array IDS = [
        'minecraft:ocean' => 0,
        'minecraft:plains' => 1,
        'minecraft:desert' => 2,
        'minecraft:extreme_hills' => 3,
        'minecraft:forest' => 4,
    ];

    private function __construct() {}

    public static function id(Biome $biome): int
    {
        return self::IDS[$biome->identifier]
            ?? throw new InvalidArgumentException("Biome {$biome->identifier} is not admitted by the default-world profile.");
    }

    /** @return array<int, string> */
    public static function identifiersById(): array
    {
        return array_flip(self::IDS);
    }
}
