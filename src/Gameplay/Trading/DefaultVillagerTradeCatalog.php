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

namespace Bedriox\Server\Gameplay\Trading;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Entity\Value\VillagerProfession;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** Deterministic, bounded default offers selected from server-owned profession pools. */
final class DefaultVillagerTradeCatalog implements VillagerTradeCatalog
{
    public function generate(VillagerProfession $profession, int $seed): VillagerTradeList
    {
        if ($seed < -0x80000000 || $seed > 0x7fffffff) {
            throw new InvalidArgumentException('Villager trade seed is outside its supported range.');
        }
        if (!$profession->canTrade()) {
            return new VillagerTradeList([]);
        }
        $offers = self::pool($profession);
        usort($offers, static function (VillagerTradeOffer $left, VillagerTradeOffer $right) use ($profession, $seed): int {
            if ($left->level !== $right->level) {
                return $left->level->value <=> $right->level->value;
            }
            return strcmp(
                hash('sha256', $profession->value . ':' . $seed . ':' . $left->key),
                hash('sha256', $profession->value . ':' . $seed . ':' . $right->key),
            );
        });

        return new VillagerTradeList($offers);
    }

    /** Refreshes Bedriox-owned definitions without discarding persisted use and demand state. */
    public function upgradePersisted(
        VillagerProfession $profession,
        int $seed,
        VillagerTradeList $persisted,
    ): VillagerTradeList {
        if (!$profession->canTrade() || $persisted->generation >= 2_147_483_647) {
            return $persisted;
        }

        $expanded = $this->generate($profession, $seed);
        $expandedByKey = [];
        foreach ($expanded->offers as $offer) {
            $expandedByKey[$offer->key] = $offer;
        }
        $persistedByKey = [];
        foreach ($persisted->offers as $offer) {
            if (!isset($expandedByKey[$offer->key])) {
                return $persisted;
            }
            $persistedByKey[$offer->key] = $offer;
        }
        $offers = [];
        foreach ($expanded->offers as $offer) {
            $previous = $persistedByKey[$offer->key] ?? null;
            $offers[] = $previous === null ? $offer : new VillagerTradeOffer(
                $offer->key,
                $offer->level,
                $offer->firstInput,
                $offer->secondInput,
                $offer->result,
                min($previous->uses, $offer->maximumUses),
                $offer->maximumUses,
                $offer->villagerExperience,
                $offer->rewardsPlayerExperience,
                $offer->priceMultiplier,
                $previous->demand,
                $previous->specialPrice,
            );
        }

        if ($offers == $persisted->offers) {
            return $persisted;
        }

        return new VillagerTradeList($offers, $persisted->generation + 1);
    }

    /** @return list<VillagerTradeOffer> */
    private static function pool(VillagerProfession $profession): array
    {
        return match ($profession) {
            VillagerProfession::FARMER => [
                self::offer('farmer/wheat', VillagerLevel::NOVICE, 'minecraft:wheat', 20, 'minecraft:emerald'),
                self::offer('farmer/bread', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:bread', 6),
                self::offer('farmer/apple', VillagerLevel::APPRENTICE, 'minecraft:emerald', 1, 'minecraft:apple', 4),
                self::offer('farmer/pumpkin', VillagerLevel::JOURNEYMAN, 'minecraft:pumpkin', 6, 'minecraft:emerald'),
                self::offer('farmer/cake', VillagerLevel::EXPERT, 'minecraft:emerald', 1, 'minecraft:cake'),
                self::offer('farmer/golden_carrot', VillagerLevel::MASTER, 'minecraft:emerald', 3, 'minecraft:golden_carrot', 3),
            ],
            VillagerProfession::FISHERMAN => [
                self::offer('fisherman/string', VillagerLevel::NOVICE, 'minecraft:string', 20, 'minecraft:emerald'),
                self::offer('fisherman/coal', VillagerLevel::NOVICE, 'minecraft:coal', 10, 'minecraft:emerald'),
                self::offer('fisherman/cod', VillagerLevel::APPRENTICE, 'minecraft:cod', 15, 'minecraft:emerald'),
                self::offer('fisherman/salmon', VillagerLevel::JOURNEYMAN, 'minecraft:salmon', 13, 'minecraft:emerald'),
                self::offer('fisherman/campfire', VillagerLevel::EXPERT, 'minecraft:emerald', 2, 'minecraft:campfire'),
                self::offer('fisherman/name_tag', VillagerLevel::MASTER, 'minecraft:emerald', 20, 'minecraft:name_tag'),
            ],
            VillagerProfession::SHEPHERD => [
                self::offer('shepherd/wool', VillagerLevel::NOVICE, 'minecraft:white_wool', 18, 'minecraft:emerald'),
                self::offer('shepherd/shears', VillagerLevel::NOVICE, 'minecraft:emerald', 2, 'minecraft:shears'),
                self::offer('shepherd/wool_block', VillagerLevel::APPRENTICE, 'minecraft:emerald', 1, 'minecraft:white_wool'),
                self::offer('shepherd/dye', VillagerLevel::JOURNEYMAN, 'minecraft:white_dye', 12, 'minecraft:emerald'),
                self::offer('shepherd/banner', VillagerLevel::EXPERT, 'minecraft:emerald', 3, 'minecraft:white_banner'),
                self::offer('shepherd/painting', VillagerLevel::MASTER, 'minecraft:emerald', 2, 'minecraft:painting'),
            ],
            VillagerProfession::FLETCHER => [
                self::offer('fletcher/stick', VillagerLevel::NOVICE, 'minecraft:stick', 32, 'minecraft:emerald'),
                self::offer('fletcher/arrows', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:arrow', 16),
                self::offer('fletcher/bow', VillagerLevel::APPRENTICE, 'minecraft:emerald', 2, 'minecraft:bow'),
                self::offer('fletcher/flint', VillagerLevel::JOURNEYMAN, 'minecraft:gravel', 10, 'minecraft:flint', 10, 'minecraft:emerald', 1),
                self::offer('fletcher/crossbow', VillagerLevel::EXPERT, 'minecraft:emerald', 3, 'minecraft:crossbow'),
                self::offer('fletcher/arrow', VillagerLevel::MASTER, 'minecraft:emerald', 2, 'minecraft:arrow', 5),
            ],
            VillagerProfession::LIBRARIAN => [
                self::offer('librarian/paper', VillagerLevel::NOVICE, 'minecraft:paper', 24, 'minecraft:emerald'),
                self::offer('librarian/bookshelf', VillagerLevel::NOVICE, 'minecraft:emerald', 9, 'minecraft:bookshelf'),
                self::offer('librarian/lantern', VillagerLevel::APPRENTICE, 'minecraft:emerald', 1, 'minecraft:lantern'),
                self::offer('librarian/ink_sac', VillagerLevel::JOURNEYMAN, 'minecraft:ink_sac', 5, 'minecraft:emerald'),
                self::offer('librarian/clock', VillagerLevel::EXPERT, 'minecraft:emerald', 5, 'minecraft:clock'),
                self::offer('librarian/name_tag', VillagerLevel::MASTER, 'minecraft:emerald', 20, 'minecraft:name_tag'),
            ],
            VillagerProfession::CARTOGRAPHER => [
                self::offer('cartographer/paper', VillagerLevel::NOVICE, 'minecraft:paper', 24, 'minecraft:emerald'),
                self::offer('cartographer/empty_map', VillagerLevel::NOVICE, 'minecraft:emerald', 7, 'minecraft:empty_map'),
                self::offer('cartographer/glass_pane', VillagerLevel::APPRENTICE, 'minecraft:glass_pane', 11, 'minecraft:emerald'),
                self::offer('cartographer/compass', VillagerLevel::JOURNEYMAN, 'minecraft:compass', 1, 'minecraft:emerald'),
                self::offer('cartographer/item_frame', VillagerLevel::EXPERT, 'minecraft:emerald', 7, 'minecraft:frame'),
                self::offer('cartographer/banner', VillagerLevel::MASTER, 'minecraft:emerald', 3, 'minecraft:white_banner'),
            ],
            VillagerProfession::CLERIC => [
                self::offer('cleric/rotten_flesh', VillagerLevel::NOVICE, 'minecraft:rotten_flesh', 32, 'minecraft:emerald'),
                self::offer('cleric/redstone', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:redstone', 2),
                self::offer('cleric/gold_ingot', VillagerLevel::APPRENTICE, 'minecraft:gold_ingot', 3, 'minecraft:emerald'),
                self::offer('cleric/glowstone', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 4, 'minecraft:glowstone'),
                self::offer('cleric/ender_pearl', VillagerLevel::EXPERT, 'minecraft:emerald', 5, 'minecraft:ender_pearl'),
                self::offer('cleric/bottle_o_enchanting', VillagerLevel::MASTER, 'minecraft:emerald', 3, 'minecraft:experience_bottle'),
            ],
            VillagerProfession::ARMORER => [
                self::offer('armorer/coal', VillagerLevel::NOVICE, 'minecraft:coal', 15, 'minecraft:emerald'),
                self::offer('armorer/iron_boots', VillagerLevel::NOVICE, 'minecraft:emerald', 4, 'minecraft:iron_boots'),
                self::offer('armorer/iron_leggings', VillagerLevel::APPRENTICE, 'minecraft:emerald', 9, 'minecraft:iron_leggings'),
                self::offer('armorer/bell', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 36, 'minecraft:bell'),
                self::offer('armorer/diamond_leggings', VillagerLevel::EXPERT, 'minecraft:emerald', 19, 'minecraft:diamond_leggings'),
                self::offer('armorer/diamond_chestplate', VillagerLevel::MASTER, 'minecraft:emerald', 21, 'minecraft:diamond_chestplate'),
            ],
            VillagerProfession::WEAPONSMITH => [
                self::offer('weaponsmith/coal', VillagerLevel::NOVICE, 'minecraft:coal', 15, 'minecraft:emerald'),
                self::offer('weaponsmith/iron_axe', VillagerLevel::NOVICE, 'minecraft:emerald', 3, 'minecraft:iron_axe'),
                self::offer('weaponsmith/iron', VillagerLevel::APPRENTICE, 'minecraft:iron_ingot', 4, 'minecraft:emerald'),
                self::offer('weaponsmith/bell', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 36, 'minecraft:bell'),
                self::offer('weaponsmith/diamond_axe', VillagerLevel::EXPERT, 'minecraft:emerald', 17, 'minecraft:diamond_axe'),
                self::offer('weaponsmith/diamond_sword', VillagerLevel::MASTER, 'minecraft:emerald', 13, 'minecraft:diamond_sword'),
            ],
            VillagerProfession::TOOLSMITH => [
                self::offer('toolsmith/coal', VillagerLevel::NOVICE, 'minecraft:coal', 15, 'minecraft:emerald'),
                self::offer('toolsmith/stone_axe', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:stone_axe'),
                self::offer('toolsmith/iron', VillagerLevel::APPRENTICE, 'minecraft:iron_ingot', 4, 'minecraft:emerald'),
                self::offer('toolsmith/diamond_hoe', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 4, 'minecraft:diamond_hoe'),
                self::offer('toolsmith/diamond_axe', VillagerLevel::EXPERT, 'minecraft:emerald', 17, 'minecraft:diamond_axe'),
                self::offer('toolsmith/diamond_pickaxe', VillagerLevel::MASTER, 'minecraft:emerald', 18, 'minecraft:diamond_pickaxe'),
            ],
            VillagerProfession::BUTCHER => [
                self::offer('butcher/chicken', VillagerLevel::NOVICE, 'minecraft:chicken', 14, 'minecraft:emerald'),
                self::offer('butcher/rabbit_stew', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:rabbit_stew'),
                self::offer('butcher/porkchop', VillagerLevel::APPRENTICE, 'minecraft:porkchop', 7, 'minecraft:emerald'),
                self::offer('butcher/mutton', VillagerLevel::JOURNEYMAN, 'minecraft:mutton', 7, 'minecraft:emerald'),
                self::offer('butcher/dried_kelp_block', VillagerLevel::EXPERT, 'minecraft:dried_kelp_block', 10, 'minecraft:emerald'),
                self::offer('butcher/sweet_berries', VillagerLevel::MASTER, 'minecraft:sweet_berries', 10, 'minecraft:emerald'),
            ],
            VillagerProfession::LEATHERWORKER => [
                self::offer('leatherworker/leather', VillagerLevel::NOVICE, 'minecraft:leather', 6, 'minecraft:emerald'),
                self::offer('leatherworker/leather_leggings', VillagerLevel::NOVICE, 'minecraft:emerald', 3, 'minecraft:leather_leggings'),
                self::offer('leatherworker/flint', VillagerLevel::APPRENTICE, 'minecraft:flint', 26, 'minecraft:emerald'),
                self::offer('leatherworker/leather_tunic', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 7, 'minecraft:leather_chestplate'),
                self::offer('leatherworker/horse_armor', VillagerLevel::EXPERT, 'minecraft:emerald', 6, 'minecraft:leather_horse_armor'),
                self::offer('leatherworker/saddle', VillagerLevel::MASTER, 'minecraft:emerald', 6, 'minecraft:saddle'),
            ],
            VillagerProfession::MASON => [
                self::offer('mason/clay_ball', VillagerLevel::NOVICE, 'minecraft:clay_ball', 10, 'minecraft:emerald'),
                self::offer('mason/bricks', VillagerLevel::NOVICE, 'minecraft:emerald', 1, 'minecraft:brick', 10),
                self::offer('mason/stone', VillagerLevel::APPRENTICE, 'minecraft:stone', 20, 'minecraft:emerald'),
                self::offer('mason/granite', VillagerLevel::JOURNEYMAN, 'minecraft:emerald', 1, 'minecraft:granite', 4),
                self::offer('mason/terracotta', VillagerLevel::EXPERT, 'minecraft:emerald', 1, 'minecraft:white_terracotta'),
                self::offer('mason/quartz_block', VillagerLevel::MASTER, 'minecraft:emerald', 1, 'minecraft:quartz_block'),
            ],
            VillagerProfession::UNEMPLOYED, VillagerProfession::NITWIT => [],
        };
    }

    private static function offer(
        string $key,
        VillagerLevel $level,
        string $input,
        int $inputCount,
        string $output,
        int $outputCount = 1,
        ?string $secondInput = null,
        int $secondInputCount = 1,
    ): VillagerTradeOffer {
        return new VillagerTradeOffer(
            'bedriox:' . $key,
            $level,
            new ItemStack($input, $inputCount),
            $secondInput === null ? null : new ItemStack($secondInput, $secondInputCount),
            new ItemStack($output, $outputCount),
            villagerExperience: match ($level) {
                VillagerLevel::NOVICE => 1,
                VillagerLevel::APPRENTICE => 5,
                VillagerLevel::JOURNEYMAN => 10,
                VillagerLevel::EXPERT => 15,
                VillagerLevel::MASTER => 20,
            },
        );
    }
}
