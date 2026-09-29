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

namespace Bedriox\Server\Gameplay\Processing;

use Bedriox\Server\World\BlockEntity\ContainerItemStack;

final readonly class FurnaceProcessor
{
    public function __construct(private FurnaceRecipeCatalog $recipes) {}

    public function tick(FurnaceBlockEntity $state): FurnaceTickResult
    {
        $inventory = $state->inventory;
        $input = $inventory->stackAt(FurnaceBlockEntity::SLOT_INPUT);
        $recipe = $input === null ? null : $this->recipes->match($state->furnaceType, $input);
        $canCook = $recipe !== null && self::acceptsOutput($inventory->stackAt(FurnaceBlockEntity::SLOT_RESULT), $recipe->output);
        $burn = $state->burnTime;
        $duration = $state->burnDuration;
        $cook = $state->cookTime;
        $xp = $state->storedExperienceMilli;
        $changed = [];
        $fuelConsumed = $started = $completed = false;

        if ($burn === 0 && $canCook) {
            $fuel = $inventory->stackAt(FurnaceBlockEntity::SLOT_FUEL);
            $fuelTicks = $fuel === null ? null : FurnaceGameplayRules::fuelTicks($fuel->identifier);
            if ($fuel !== null && $fuelTicks !== null) {
                $inventory = $inventory->withStack(FurnaceBlockEntity::SLOT_FUEL, self::consumeFuel($fuel));
                $changed[] = FurnaceBlockEntity::SLOT_FUEL;
                $burn = $duration = $fuelTicks;
                $fuelConsumed = true;
            }
        }
        if ($burn > 0) {
            --$burn;
        }
        if ($canCook && $burn > 0) {
            $started = $cook === 0;
            ++$cook;
            if ($cook >= $state->cookDuration) {
                $inventory = $inventory->withStack(FurnaceBlockEntity::SLOT_INPUT, self::decrement($input));
                $inventory = $inventory->withStack(
                    FurnaceBlockEntity::SLOT_RESULT,
                    self::mergeOutput($inventory->stackAt(FurnaceBlockEntity::SLOT_RESULT), $recipe->output),
                );
                $changed[] = FurnaceBlockEntity::SLOT_INPUT;
                $changed[] = FurnaceBlockEntity::SLOT_RESULT;
                $xp = min(
                    FurnaceBlockEntity::MAXIMUM_STORED_EXPERIENCE_MILLI,
                    $xp + (int) round($recipe->experience * 1000),
                );
                $cook = 0;
                $completed = true;
            }
        } elseif (!$canCook) {
            $cook = 0;
        }
        sort($changed);
        return new FurnaceTickResult(
            $state->withState($inventory, $burn, $duration, $cook, $xp),
            $fuelConsumed,
            $started,
            $completed,
            array_values(array_unique($changed)),
        );
    }

    private static function acceptsOutput(?ContainerItemStack $current, ContainerItemStack $output): bool
    {
        return $current === null || ($current->identifier === $output->identifier && $current->damage === $output->damage
            && $current->auxValue === $output->auxValue && $current->nbt == $output->nbt
            && $current->count + $output->count <= 64);
    }

    private static function mergeOutput(?ContainerItemStack $current, ContainerItemStack $output): ContainerItemStack
    {
        return $current === null ? $output : new ContainerItemStack(
            $current->identifier,
            $current->count + $output->count,
            $current->damage,
            $current->nbt,
            $current->auxValue,
        );
    }

    private static function decrement(ContainerItemStack $stack): ?ContainerItemStack
    {
        return $stack->count === 1 ? null : new ContainerItemStack(
            $stack->identifier,
            $stack->count - 1,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        );
    }

    private static function consumeFuel(ContainerItemStack $fuel): ?ContainerItemStack
    {
        $remaining = self::decrement($fuel);
        if ($remaining !== null) {
            return $remaining;
        }
        $residue = FurnaceGameplayRules::fuelResidue($fuel->identifier);
        return $residue === null ? null : new ContainerItemStack($residue, 1);
    }
}
