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

namespace Bedriox\Api\Event\Entity;

use Bedriox\Api\Entity\LivingEntity;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final class EntityPickupItemEvent extends CancellableEvent
{
    public function __construct(
        public readonly LivingEntity $entity,
        public readonly ItemStack $item,
        private int $count,
    ) {
        $this->validate($count);
    }

    public function count(): int
    {
        return $this->count;
    }

    public function setCount(int $count): void
    {
        $this->assertMutable();
        $this->validate($count);
        $this->count = $count;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->count];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_int($state[1])) {
            throw new InvalidArgumentException('Invalid entity pickup event state.');
        }
        parent::replaceState($state[0]);
        $this->validate($state[1]);
        $this->count = $state[1];
    }

    private function validate(int $count): void
    {
        if ($count < 1 || $count > $this->item->count) {
            throw new InvalidArgumentException('Entity pickup count must be within the dropped stack.');
        }
    }
}
