<?php

declare(strict_types=1);

namespace Bedriox\Server\Player;

/** The bounded canonical inventory set that has a qualified Bedrock projection. */
final class SupportedInventoryItem
{
    public const array IDENTIFIERS = [
        'minecraft:grass_block',
        'minecraft:iron_sword',
        'minecraft:iron_pickaxe',
        'minecraft:iron_axe',
        'minecraft:iron_shovel',
        'minecraft:iron_hoe',
    ];

    public static function supports(string $identifier): bool
    {
        return in_array($identifier, self::IDENTIFIERS, true);
    }

    public static function maximumStackSize(string $identifier): int
    {
        return $identifier === 'minecraft:grass_block' ? 64 : 1;
    }

    private function __construct() {}
}
