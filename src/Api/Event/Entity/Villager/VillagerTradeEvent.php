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

use Bedriox\Api\Entity\Trade\VillagerTradeOffer;
use Bedriox\Api\Entity\Vanilla\Villager;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable, adjustable trade before inputs are consumed and output is granted. */
final class VillagerTradeEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly Villager $villager,
        private VillagerTradeOffer $offer,
        public readonly int $tradeCount = 1,
    ) {
        if ($tradeCount < 1 || $tradeCount > 64) {
            throw new InvalidArgumentException('Villager trade count is outside its supported range.');
        }
    }

    public function offer(): VillagerTradeOffer
    {
        return $this->offer;
    }

    public function setOffer(VillagerTradeOffer $offer): void
    {
        $this->assertMutable();
        if ($offer->key !== $this->offer->key) {
            throw new InvalidArgumentException('A villager trade replacement must preserve the offer key.');
        }
        $this->offer = $offer;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->offer];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0])
            || !$state[1] instanceof VillagerTradeOffer) {
            throw new InvalidArgumentException('Invalid villager trade event state.');
        }
        parent::replaceState($state[0]);
        $this->offer = $state[1];
    }
}
