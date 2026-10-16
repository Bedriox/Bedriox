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

use Bedriox\Api\Entity\Value\VillagerLevel;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

/** One immutable server-owned trade, including its persisted demand state. */
final readonly class VillagerTradeOffer
{
    public function __construct(
        public string $key,
        public VillagerLevel $level,
        public ItemStack $firstInput,
        public ?ItemStack $secondInput,
        public ItemStack $result,
        public int $uses = 0,
        public int $maximumUses = 12,
        public int $villagerExperience = 1,
        public bool $rewardsPlayerExperience = true,
        public float $priceMultiplier = 0.05,
        public int $demand = 0,
        public int $specialPrice = 0,
    ) {
        if (preg_match('/^[a-z0-9_.-]+:[a-z0-9_.\/-]+$/D', $key) !== 1) {
            throw new InvalidArgumentException('Villager trade key must be canonical and namespaced.');
        }
        if ($uses < 0 || $maximumUses < 1 || $maximumUses > 1_024 || $uses > $maximumUses) {
            throw new InvalidArgumentException('Villager trade uses are outside their supported bounds.');
        }
        if ($villagerExperience < 0 || $villagerExperience > 1_000
            || !is_finite($priceMultiplier) || $priceMultiplier < 0.0 || $priceMultiplier > 10.0
            || $demand < -1_024 || $demand > 1_024 || $specialPrice < -64 || $specialPrice > 64) {
            throw new InvalidArgumentException('Villager trade economy values are outside their supported bounds.');
        }
    }

    public function isExhausted(): bool
    {
        return $this->uses >= $this->maximumUses;
    }

    public function withCompletedUse(): self
    {
        return $this->withCompletedUses(1);
    }

    public function withCompletedUses(int $count): self
    {
        if ($count < 1 || $this->uses + $count > $this->maximumUses) {
            throw new InvalidArgumentException('Villager trade completion count exceeds its remaining uses.');
        }

        return new self(
            $this->key,
            $this->level,
            $this->firstInput,
            $this->secondInput,
            $this->result,
            $this->uses + $count,
            $this->maximumUses,
            $this->villagerExperience,
            $this->rewardsPlayerExperience,
            $this->priceMultiplier,
            $this->demand,
            $this->specialPrice,
        );
    }

    public function withRestockedUses(int $uses = 0): self
    {
        return new self(
            $this->key,
            $this->level,
            $this->firstInput,
            $this->secondInput,
            $this->result,
            $uses,
            $this->maximumUses,
            $this->villagerExperience,
            $this->rewardsPlayerExperience,
            $this->priceMultiplier,
            $this->demand,
            $this->specialPrice,
        );
    }
}
