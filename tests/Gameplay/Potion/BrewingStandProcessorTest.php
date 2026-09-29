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

namespace Bedriox\Server\Tests\Gameplay\Potion;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Potion\BrewingRecipeCatalog;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\Gameplay\Potion\BrewingStandProcessor;
use Bedriox\Server\World\BlockEntity\ContainerInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class BrewingStandProcessorTest extends TestCase
{
    public function testConsumesOneFuelUseAndCompletesEveryMatchingBottleAtomically(): void
    {
        $state = $this->state([
            BrewingStandBlockEntity::SLOT_INGREDIENT => new ContainerItemStack('minecraft:nether_wart', 2),
            BrewingStandBlockEntity::SLOT_BOTTLE_LEFT => new ContainerItemStack('minecraft:potion', 1, auxValue: 0),
            BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT => new ContainerItemStack('minecraft:potion', 1, auxValue: 0),
            BrewingStandBlockEntity::SLOT_FUEL => new ContainerItemStack('minecraft:blaze_powder', 2),
        ]);
        $processor = $this->processor();

        $first = $processor->tick($state);
        self::assertTrue($first->started);
        self::assertFalse($first->completed);
        self::assertSame(399, $first->state->brewTime);
        self::assertSame(19, $first->state->fuelAmount);
        self::assertSame(20, $first->state->fuelTotal);
        self::assertSame(1, $first->state->inventory->stackAt(BrewingStandBlockEntity::SLOT_FUEL)?->count);

        $result = $first;
        for ($tick = 1; $tick < BrewingStandBlockEntity::BREW_TIME_TICKS; ++$tick) {
            $result = $processor->tick($result->state);
        }
        self::assertTrue($result->completed);
        self::assertSame(0, $result->state->brewTime);
        self::assertSame(1, $result->state->inventory->stackAt(BrewingStandBlockEntity::SLOT_INGREDIENT)?->count);
        self::assertSame(4, $result->state->inventory->stackAt(BrewingStandBlockEntity::SLOT_BOTTLE_LEFT)?->auxValue);
        self::assertSame(4, $result->state->inventory->stackAt(BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT)?->auxValue);
        self::assertSame([0, 1, 3], $result->changedSlots);
    }

    public function testStopsProgressWhenRecipeDisappearsAndDoesNotConsumeFuelItemWithoutRecipe(): void
    {
        $state = $this->state([
            BrewingStandBlockEntity::SLOT_INGREDIENT => new ContainerItemStack('minecraft:diamond', 1),
            BrewingStandBlockEntity::SLOT_BOTTLE_LEFT => new ContainerItemStack('minecraft:potion', 1, auxValue: 0),
            BrewingStandBlockEntity::SLOT_FUEL => new ContainerItemStack('minecraft:blaze_powder', 1),
        ], brewTime: 120, fuelAmount: 5, fuelTotal: 20);

        $result = $this->processor()->tick($state);
        self::assertSame(0, $result->state->brewTime);
        self::assertSame(5, $result->state->fuelAmount);
        self::assertSame(1, $result->state->inventory->stackAt(BrewingStandBlockEntity::SLOT_FUEL)?->count);
        self::assertFalse($result->started);
        self::assertFalse($result->completed);
    }

    /** @param array<int, ContainerItemStack> $contents */
    private function state(array $contents, int $brewTime = 0, int $fuelAmount = 0, int $fuelTotal = 0): BrewingStandBlockEntity
    {
        return new BrewingStandBlockEntity(
            new BlockPosition(1, 64, 2),
            new ContainerInventory(BrewingStandBlockEntity::SLOT_COUNT, $contents),
            $brewTime,
            $fuelAmount,
            $fuelTotal,
        );
    }

    private function processor(): BrewingStandProcessor
    {
        $recipes = BedrockDataSet::bundled()->recipeRegistry();

        return new BrewingStandProcessor(new BrewingRecipeCatalog(
            $recipes->containerMixes(),
            $recipes->potionMixes(),
        ));
    }
}
