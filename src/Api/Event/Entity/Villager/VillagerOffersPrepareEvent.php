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

namespace Bedriox\Api\Event\Entity\Villager;

use Bedriox\Api\Entity\Trade\VillagerTradeList;
use Bedriox\Api\Entity\Vanilla\Villager;
use Bedriox\Api\Event\Event;
use InvalidArgumentException;

/** Adjustable offer list before it becomes authoritative villager state. */
final class VillagerOffersPrepareEvent extends Event
{
    public function __construct(public readonly Villager $villager, private VillagerTradeList $offers) {}

    public function offers(): VillagerTradeList
    {
        return $this->offers;
    }

    public function setOffers(VillagerTradeList $offers): void
    {
        $this->assertMutable();
        $this->offers = $offers;
    }

    protected function state(): mixed
    {
        return $this->offers;
    }

    protected function replaceState(mixed $state): void
    {
        if (!$state instanceof VillagerTradeList) {
            throw new InvalidArgumentException('Invalid villager offers-prepare event state.');
        }
        $this->offers = $state;
    }
}
