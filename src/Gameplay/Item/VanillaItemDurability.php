<?php

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Item;

/** Durability for damageable vanilla items that are not modeled as tools or wearable armor. */
final class VanillaItemDurability
{
    public static function maximum(string $identifier): ?int
    {
        return match ($identifier) {
            'minecraft:bow' => 385,
            'minecraft:brush' => 65,
            'minecraft:carrot_on_a_stick' => 26,
            'minecraft:crossbow' => 464,
            'minecraft:elytra' => 433,
            'minecraft:fishing_rod' => 384,
            'minecraft:flint_and_steel' => 65,
            'minecraft:mace' => 501,
            'minecraft:shield' => 337,
            'minecraft:trident' => 251,
            'minecraft:warped_fungus_on_a_stick' => 101,
            'minecraft:wolf_armor' => 64,
            'minecraft:wooden_spear' => 60,
            'minecraft:golden_spear' => 33,
            'minecraft:stone_spear' => 132,
            'minecraft:copper_spear' => 191,
            'minecraft:iron_spear' => 251,
            'minecraft:diamond_spear' => 1_562,
            'minecraft:netherite_spear' => 2_032,
            'minecraft:golden_nautilus_armor' => 33,
            'minecraft:copper_nautilus_armor' => 191,
            'minecraft:iron_nautilus_armor' => 251,
            'minecraft:diamond_nautilus_armor' => 1_562,
            'minecraft:netherite_nautilus_armor' => 2_032,
            default => null,
        };
    }
}
