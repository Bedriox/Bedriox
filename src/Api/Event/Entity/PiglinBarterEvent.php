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

use Bedriox\Api\Entity\Vanilla\Piglin;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Inventory\ItemStack;
use InvalidArgumentException;

final class PiglinBarterEvent extends CancellableEvent
{
    /** @var list<ItemStack> */
    private array $outputs;

    /** @param list<ItemStack> $outputs */
    public function __construct(public readonly Piglin $piglin, public readonly ItemStack $payment, array $outputs)
    {
        $this->setOutputs($outputs);
    }

    /** @return list<ItemStack> */
    public function outputs(): array
    {
        return $this->outputs;
    }

    /** @param array<array-key, mixed> $outputs */
    public function setOutputs(array $outputs): void
    {
        $this->assertMutable();
        if (count($outputs) > 32) {
            throw new InvalidArgumentException('Piglin barter output exceeds its supported bound.');
        }
        $validated = [];
        foreach ($outputs as $output) {
            if (!$output instanceof ItemStack) {
                throw new InvalidArgumentException('Piglin barter output must contain item stacks.');
            }
            $validated[] = $output;
        }
        $this->outputs = $validated;
    }

    protected function state(): mixed
    {
        return [parent::state(), $this->outputs];
    }

    protected function replaceState(mixed $state): void
    {
        if (!is_array($state) || count($state) !== 2 || !is_bool($state[0]) || !is_array($state[1])) {
            throw new InvalidArgumentException('Invalid piglin barter event state.');
        }
        parent::replaceState($state[0]);
        $this->setOutputs($state[1]);
    }
}
