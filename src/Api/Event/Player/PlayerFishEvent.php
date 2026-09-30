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

namespace Bedriox\Api\Event\Player;

use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use InvalidArgumentException;

/** Cancellable authoritative fishing transition; a catch may replace its bounded item and experience result. */
final class PlayerFishEvent extends CancellableEvent
{
    public function __construct(
        public readonly Player $player,
        public readonly int $hookRuntimeEntityId,
        public readonly PlayerFishState $state,
        private ?ItemStack $caughtItem = null,
        private int $experience = 0,
    ) {
        if ($hookRuntimeEntityId < 1 || $experience < 0 || $experience > 100) {
            throw new InvalidArgumentException('Fishing event state is invalid.');
        }
    }

    public function caughtItem(): ?ItemStack
    {
        return $this->caughtItem;
    }

    public function setCaughtItem(?ItemStack $caughtItem): void
    {
        $this->assertMutable();
        $this->caughtItem = $caughtItem;
    }

    public function experience(): int
    {
        return $this->experience;
    }

    public function setExperience(int $experience): void
    {
        $this->assertMutable();
        if ($experience < 0 || $experience > 100) {
            throw new InvalidArgumentException('Fishing experience must be between zero and 100.');
        }
        $this->experience = $experience;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->caughtItem, $this->experience];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 3 || !is_bool($state[0])
            || ($state[1] !== null && !$state[1] instanceof ItemStack) || !is_int($state[2])) {
            throw new InvalidArgumentException('Fishing event snapshot is invalid.');
        }
        parent::replaceState($state[0]);
        $this->caughtItem = $state[1];
        $this->experience = $state[2];
    }
}
