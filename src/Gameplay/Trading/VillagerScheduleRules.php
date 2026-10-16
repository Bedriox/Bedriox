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
use InvalidArgumentException;

/** Shared world-time and offer-generation rules for bounded villager work schedules. */
final class VillagerScheduleRules
{
    public const int WORK_START_TICK = 2_000;
    public const int WORK_END_TICK = 9_000;
    public const int REST_START_TICK = 12_000;
    public const int REST_END_TICK = 0;

    public static function isWorkTime(int $timeOfDay): bool
    {
        return $timeOfDay >= self::WORK_START_TICK && $timeOfDay < self::WORK_END_TICK;
    }

    public static function isRestTime(int $timeOfDay): bool
    {
        return $timeOfDay >= self::REST_START_TICK || $timeOfDay < self::REST_END_TICK;
    }

    public static function restocked(VillagerTradeList $trades): VillagerTradeList
    {
        if ($trades->generation === 2_147_483_647) {
            throw new InvalidArgumentException('Villager trade generation is exhausted.');
        }

        return new VillagerTradeList(array_map(
            static fn(VillagerTradeOffer $offer): VillagerTradeOffer => $offer->withRestockedUses(),
            $trades->offers,
        ), $trades->generation + 1);
    }
}
