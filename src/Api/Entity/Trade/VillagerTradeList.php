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

namespace Bedriox\Api\Entity\Trade;

use InvalidArgumentException;

/** Bounded immutable offer generation exposed to plugins and trade sessions. */
final readonly class VillagerTradeList
{
    public const int MAXIMUM_OFFERS = 64;

    /** @var list<VillagerTradeOffer> */
    public array $offers;

    /** @param list<VillagerTradeOffer> $offers */
    public function __construct(array $offers, public int $generation = 0)
    {
        if (count($offers) > self::MAXIMUM_OFFERS || $generation < 0 || $generation > 2_147_483_647) {
            throw new InvalidArgumentException('Villager trade list is outside its supported bounds.');
        }
        $keys = [];
        foreach ($offers as $offer) {
            if (isset($keys[$offer->key])) {
                throw new InvalidArgumentException('Villager trade keys must be unique within one offer list.');
            }
            $keys[$offer->key] = true;
        }
        $this->offers = $offers;
    }

    public function offer(string $key): ?VillagerTradeOffer
    {
        foreach ($this->offers as $offer) {
            if ($offer->key === $key) {
                return $offer;
            }
        }

        return null;
    }
}
