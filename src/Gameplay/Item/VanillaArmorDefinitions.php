<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

/** Current vanilla player armor definitions verified against the primary gameplay reference. */
final class VanillaArmorDefinitions
{
    private function __construct() {}

    /** @return array<string, ArmorDefinition> */
    public static function all(): array
    {
        return [
            'minecraft:chainmail_helmet' => new ArmorDefinition(ArmorSlot::Head, 2, 166),
            'minecraft:copper_helmet' => new ArmorDefinition(ArmorSlot::Head, 2, 122),
            'minecraft:diamond_helmet' => new ArmorDefinition(ArmorSlot::Head, 3, 364),
            'minecraft:golden_helmet' => new ArmorDefinition(ArmorSlot::Head, 2, 78),
            'minecraft:iron_helmet' => new ArmorDefinition(ArmorSlot::Head, 2, 166),
            'minecraft:leather_helmet' => new ArmorDefinition(ArmorSlot::Head, 1, 56),
            'minecraft:netherite_helmet' => new ArmorDefinition(ArmorSlot::Head, 3, 408),
            'minecraft:turtle_helmet' => new ArmorDefinition(ArmorSlot::Head, 2, 276),

            'minecraft:chainmail_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 5, 241),
            'minecraft:copper_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 4, 177),
            'minecraft:diamond_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 8, 529),
            'minecraft:golden_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 5, 113),
            'minecraft:iron_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 6, 241),
            'minecraft:leather_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 3, 81),
            'minecraft:netherite_chestplate' => new ArmorDefinition(ArmorSlot::Chest, 8, 593),

            'minecraft:chainmail_leggings' => new ArmorDefinition(ArmorSlot::Legs, 4, 226),
            'minecraft:copper_leggings' => new ArmorDefinition(ArmorSlot::Legs, 3, 166),
            'minecraft:diamond_leggings' => new ArmorDefinition(ArmorSlot::Legs, 6, 496),
            'minecraft:golden_leggings' => new ArmorDefinition(ArmorSlot::Legs, 3, 106),
            'minecraft:iron_leggings' => new ArmorDefinition(ArmorSlot::Legs, 5, 226),
            'minecraft:leather_leggings' => new ArmorDefinition(ArmorSlot::Legs, 2, 76),
            'minecraft:netherite_leggings' => new ArmorDefinition(ArmorSlot::Legs, 6, 556),

            'minecraft:chainmail_boots' => new ArmorDefinition(ArmorSlot::Feet, 1, 196),
            'minecraft:copper_boots' => new ArmorDefinition(ArmorSlot::Feet, 1, 144),
            'minecraft:diamond_boots' => new ArmorDefinition(ArmorSlot::Feet, 3, 430),
            'minecraft:golden_boots' => new ArmorDefinition(ArmorSlot::Feet, 1, 92),
            'minecraft:iron_boots' => new ArmorDefinition(ArmorSlot::Feet, 2, 196),
            'minecraft:leather_boots' => new ArmorDefinition(ArmorSlot::Feet, 1, 66),
            'minecraft:netherite_boots' => new ArmorDefinition(ArmorSlot::Feet, 3, 482),
        ];
    }

    public static function definition(string $identifier): ?ArmorDefinition
    {
        return self::all()[$identifier] ?? null;
    }
}
