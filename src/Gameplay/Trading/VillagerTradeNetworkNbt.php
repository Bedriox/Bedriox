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
use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtCodec;
use Bedriox\Server\World\Storage\Nbt\LittleEndianNbtTag;

/** Builds the bounded Bedrock trade-offer document sent to one trading player. */
final class VillagerTradeNetworkNbt
{
    /** @var list<int> */
    private const array TIER_EXPERIENCE_REQUIREMENTS = [0, 10, 70, 150, 250];

    public static function encode(VillagerTradeList $trades, ?VillagerLevel $availableThrough = null): string
    {
        $recipes = [];
        foreach ($trades->offers as $index => $offer) {
            if ($availableThrough !== null && $offer->level->value > $availableThrough->value) {
                continue;
            }
            $recipe = [
                'buyA' => self::item($offer->firstInput),
                'buyCountA' => LittleEndianNbtTag::int($offer->firstInput->count),
                'buyCountB' => LittleEndianNbtTag::int($offer->secondInput === null ? 0 : $offer->secondInput->count),
                'demand' => LittleEndianNbtTag::int($offer->demand),
                'maxUses' => LittleEndianNbtTag::int($offer->maximumUses),
                'netId' => LittleEndianNbtTag::int($index + 1),
                'priceMultiplierA' => LittleEndianNbtTag::float($offer->priceMultiplier),
                'priceMultiplierB' => LittleEndianNbtTag::float(0.0),
                'rewardExp' => LittleEndianNbtTag::byte($offer->rewardsPlayerExperience ? 1 : 0),
                'sell' => self::item($offer->result),
                'specialPrice' => LittleEndianNbtTag::int($offer->specialPrice),
                'tier' => LittleEndianNbtTag::int($offer->level->value - 1),
                'traderExp' => LittleEndianNbtTag::int($offer->villagerExperience),
                'uses' => LittleEndianNbtTag::int($offer->uses),
            ];
            if ($offer->secondInput !== null) {
                $recipe['buyB'] = self::item($offer->secondInput);
            }
            $recipes[] = LittleEndianNbtTag::compound($recipe);
        }
        $tiers = [];
        foreach (self::TIER_EXPERIENCE_REQUIREMENTS as $tier => $experience) {
            $tiers[] = LittleEndianNbtTag::compound([
                (string) $tier => LittleEndianNbtTag::int($experience),
            ]);
        }

        return (new LittleEndianNbtCodec())->encodeRootCompound([
            'Recipes' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $recipes),
            'TierExpRequirements' => LittleEndianNbtTag::list(LittleEndianNbtTag::COMPOUND, $tiers),
        ]);
    }

    private static function item(ItemStack $stack): LittleEndianNbtTag
    {
        $tags = [
            'Count' => LittleEndianNbtTag::byte($stack->count),
            'Damage' => new LittleEndianNbtTag(LittleEndianNbtTag::SHORT, $stack->damage),
            'Name' => LittleEndianNbtTag::string($stack->identifier),
            'WasPickedUp' => LittleEndianNbtTag::byte(0),
        ];
        if ($stack->nbt !== null) {
            $tags['tag'] = LittleEndianNbtTag::compound(
                (new LittleEndianNbtCodec())->decodeRootCompound($stack->nbt->toBinary()),
            );
        }

        return LittleEndianNbtTag::compound($tags);
    }
}
