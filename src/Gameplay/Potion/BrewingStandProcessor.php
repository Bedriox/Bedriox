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

namespace Bedriox\Server\Gameplay\Potion;

use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;

/** Stateless authoritative brewing transition evaluator. */
final class BrewingStandProcessor
{
    private int $evaluationCount = 0;

    public function __construct(private BrewingRecipeCatalog $recipes) {}

    public function tick(BrewingStandBlockEntity $state): BrewingStandTickResult
    {
        ++$this->evaluationCount;
        $inventory = $state->inventory;
        $fuelAmount = $state->fuelAmount;
        $fuelTotal = $state->fuelTotal;
        $brewTime = $state->brewTime;
        $changed = [];
        $started = false;
        $completed = false;
        $matches = $this->matches($inventory);

        if ($fuelAmount <= 0 && $matches !== []) {
            $fuel = $inventory->stackAt(BrewingStandBlockEntity::SLOT_FUEL);
            if ($fuel?->identifier === 'minecraft:blaze_powder') {
                $inventory = $inventory->withStack(
                    BrewingStandBlockEntity::SLOT_FUEL,
                    self::decrement($fuel),
                );
                $changed[] = BrewingStandBlockEntity::SLOT_FUEL;
                $fuelAmount = $fuelTotal = BrewingStandBlockEntity::BLAZE_POWDER_FUEL_USES;
            }
        }

        if ($fuelAmount > 0) {
            if ($matches === []) {
                $brewTime = 0;
            } else {
                if ($brewTime === 0) {
                    $brewTime = BrewingStandBlockEntity::BREW_TIME_TICKS;
                    --$fuelAmount;
                    $started = true;
                }
                --$brewTime;
                if ($brewTime <= 0) {
                    foreach ($matches as $slot => $result) {
                        $input = $inventory->stackAt($slot);
                        if ($input === null) {
                            continue;
                        }
                        $inventory = $inventory->withStack($slot, new ContainerItemStack(
                            $result->itemIdentifier,
                            $input->count,
                            $input->damage,
                            $input->nbt,
                            $result->auxValue,
                        ));
                        $changed[] = $slot;
                    }
                    $ingredient = $inventory->stackAt(BrewingStandBlockEntity::SLOT_INGREDIENT);
                    if ($ingredient !== null) {
                        $inventory = $inventory->withStack(
                            BrewingStandBlockEntity::SLOT_INGREDIENT,
                            self::decrement($ingredient),
                        );
                        $changed[] = BrewingStandBlockEntity::SLOT_INGREDIENT;
                    }
                    $brewTime = 0;
                    $completed = true;
                }
            }
        } else {
            $brewTime = $fuelAmount = $fuelTotal = 0;
        }

        sort($changed, SORT_NUMERIC);
        return new BrewingStandTickResult(
            $state->withState($inventory, $brewTime, $fuelAmount, $fuelTotal),
            $started,
            $completed,
            array_values(array_unique($changed)),
        );
    }

    public function evaluationCount(): int
    {
        return $this->evaluationCount;
    }

    /** @return array<int, BrewingResult> */
    private function matches(ContainerInventory $inventory): array
    {
        $ingredient = $inventory->stackAt(BrewingStandBlockEntity::SLOT_INGREDIENT);
        if ($ingredient === null) {
            return [];
        }
        $matches = [];
        foreach ([
            BrewingStandBlockEntity::SLOT_BOTTLE_LEFT,
            BrewingStandBlockEntity::SLOT_BOTTLE_MIDDLE,
            BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT,
        ] as $slot) {
            $input = $inventory->stackAt($slot);
            if ($input === null) {
                continue;
            }
            $result = $this->recipes->match(
                $input->identifier,
                $input->auxValue,
                $ingredient->identifier,
                $ingredient->auxValue,
            );
            if ($result !== null) {
                $matches[$slot] = $result;
            }
        }

        return $matches;
    }

    private static function decrement(ContainerItemStack $stack): ?ContainerItemStack
    {
        return $stack->count === 1
            ? null
            : new ContainerItemStack(
                $stack->identifier,
                $stack->count - 1,
                $stack->damage,
                $stack->nbt,
                $stack->auxValue,
            );
    }
}
