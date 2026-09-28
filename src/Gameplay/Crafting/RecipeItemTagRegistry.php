<?php

/*
 *  ____           _      _
 * | __ )  ___  __| |_ __(_) _____  __
 * |  _ \ / _ \/ _` | '__| |/ _ \ \/ /
 * | |_) |  __/ (_| | |  | | (_) >  <
 * |____/ \___|\__,_|_|  |_|\___/_/\_\
 *
 * Bedriox - Minecraft: Bedrock Edition Server Software
 * Copyright (C) 2026 Veno Ninja LLC
 *
 * Website: https://bedriox.com
 * Source: https://github.com/Bedriox/Bedriox
 *
 * SPDX-License-Identifier: GPL-3.0-only
 */

declare(strict_types=1);

namespace Bedriox\Server\Gameplay\Crafting;

use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;
use InvalidArgumentException;

/** Bounded authoritative membership for item tags used by the admitted recipe catalog. */
final readonly class RecipeItemTagRegistry
{
    /** @var array<string, list<string>> */
    private array $members;

    /** @param array<string, list<string>> $members */
    public function __construct(array $members)
    {
        $validated = [];
        foreach ($members as $tag => $identifiers) {
            if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $tag) !== 1
                || $identifiers === [] || count($identifiers) > RecipeIngredient::MAXIMUM_ALTERNATIVES) {
                throw new InvalidArgumentException('Recipe item tag is invalid or outside its bounds.');
            }
            $unique = [];
            foreach ($identifiers as $identifier) {
                if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.-]+$/D', $identifier) !== 1) {
                    throw new InvalidArgumentException('Recipe item tag contains an invalid identifier.');
                }
                $unique[$identifier] = true;
            }
            ksort($unique, SORT_STRING);
            $validated[$tag] = array_keys($unique);
        }
        ksort($validated, SORT_STRING);
        $this->members = $validated;
    }

    public static function vanilla(ItemCatalog $items): self
    {
        $identifiers = array_map(static fn(ItemType $type): string => $type->identifier, $items->all());
        $has = array_fill_keys($identifiers, true);
        /**
         * @param callable(string): bool $predicate
         * @return list<string>
         */
        $matching = static function (callable $predicate) use ($identifiers): array {
            $matched = [];
            foreach ($identifiers as $identifier) {
                if ($predicate($identifier)) {
                    $matched[] = $identifier;
                }
            }

            return $matched;
        };
        /**
         * @param list<string> $values
         * @return list<string>
         */
        $present = static function (array $values) use ($has): array {
            $matched = [];
            foreach ($values as $identifier) {
                if (!is_string($identifier)) {
                    throw new InvalidArgumentException('Recipe item tag candidate must be an identifier.');
                }
                if (isset($has[$identifier])) {
                    $matched[] = $identifier;
                }
            }

            return $matched;
        };
        $woodPrefixes = [
            'oak', 'spruce', 'birch', 'jungle', 'acacia', 'dark_oak', 'mangrove', 'cherry', 'pale_oak',
            'crimson', 'warped', 'bamboo',
        ];
        $woodenSlabs = [];
        foreach ($woodPrefixes as $prefix) {
            foreach (['slab', 'double_slab'] as $suffix) {
                $woodenSlabs[] = 'minecraft:' . $prefix . '_' . $suffix;
            }
        }

        return new self([
            'minecraft:coals' => $present(['minecraft:coal', 'minecraft:charcoal']),
            'minecraft:egg' => $present(['minecraft:egg', 'minecraft:blue_egg', 'minecraft:brown_egg']),
            'minecraft:logs' => $matching(static fn(string $id): bool => self::isLog($id)),
            'minecraft:logs_that_burn' => $matching(static fn(string $id): bool => self::isLog($id)
                && !str_contains($id, 'crimson_') && !str_contains($id, 'warped_')),
            'minecraft:metal_nuggets' => $present([
                'minecraft:iron_nugget', 'minecraft:gold_nugget', 'minecraft:copper_nugget',
            ]),
            'minecraft:mushrooms_for_stew' => $present([
                'minecraft:red_mushroom', 'minecraft:brown_mushroom',
            ]),
            'minecraft:planks' => $matching(static fn(string $id): bool => str_ends_with($id, '_planks')),
            'minecraft:soul_fire_base_blocks' => $present(['minecraft:soul_sand', 'minecraft:soul_soil']),
            'minecraft:stone_crafting_materials' => $present([
                'minecraft:cobblestone', 'minecraft:blackstone', 'minecraft:cobbled_deepslate',
            ]),
            'minecraft:stone_tool_materials' => $present([
                'minecraft:cobblestone', 'minecraft:blackstone', 'minecraft:cobbled_deepslate',
            ]),
            'minecraft:trim_materials' => $present([
                'minecraft:amethyst_shard', 'minecraft:copper_ingot', 'minecraft:diamond',
                'minecraft:emerald', 'minecraft:gold_ingot', 'minecraft:iron_ingot',
                'minecraft:lapis_lazuli', 'minecraft:netherite_ingot', 'minecraft:quartz',
                'minecraft:redstone', 'minecraft:resin_brick',
            ]),
            'minecraft:trim_templates' => $matching(
                static fn(string $id): bool => str_ends_with($id, '_armor_trim_smithing_template'),
            ),
            'minecraft:trimmable_armors' => $matching(
                static fn(string $id): bool => self::isTrimmableArmor($id),
            ),
            'minecraft:wooden_slabs' => $present($woodenSlabs),
            'minecraft:wool' => $matching(static fn(string $id): bool => str_ends_with($id, '_wool')),
        ]);
    }

    /** @return list<string> */
    public function members(string $tag): array
    {
        return $this->members[$tag]
            ?? throw new InvalidArgumentException('Recipe item tag is not defined.');
    }

    private static function isLog(string $identifier): bool
    {
        return str_ends_with($identifier, '_log') || str_ends_with($identifier, '_wood')
            || str_ends_with($identifier, '_stem') || str_ends_with($identifier, '_hyphae');
    }

    private static function isTrimmableArmor(string $identifier): bool
    {
        foreach (['_helmet', '_chestplate', '_leggings', '_boots'] as $suffix) {
            if (str_ends_with($identifier, $suffix) && !str_contains($identifier, 'leather_')) {
                return true;
            }
        }

        return false;
    }
}
