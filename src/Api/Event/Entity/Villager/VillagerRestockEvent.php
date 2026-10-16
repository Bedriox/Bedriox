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
use Bedriox\Api\Event\CancellableEvent;
use InvalidArgumentException;

/** Cancellable, adjustable workstation restock before authoritative mutation. */
final class VillagerRestockEvent extends CancellableEvent
{
    public function __construct(
        public readonly Villager $villager,
        public readonly int $worldDay,
        private VillagerTradeList $offers,
    ) {
        if ($worldDay < 0) {
            throw new InvalidArgumentException('Villager restock world day cannot be negative.');
        }
    }

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
        return [parent::state(), $this->offers];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0])
            || !$state[1] instanceof VillagerTradeList) {
            throw new InvalidArgumentException('Invalid villager restock event state.');
        }
        parent::replaceState($state[0]);
        $this->offers = $state[1];
    }
}
