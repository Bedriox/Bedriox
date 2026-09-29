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

namespace Bedriox\Server\Tests\Gameplay\Processing;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Gameplay\Processing\FurnaceBlockEntity;
use Bedriox\Server\Gameplay\Processing\FurnaceProcessor;
use Bedriox\Server\Gameplay\Processing\FurnaceRecipeCatalog;
use Bedriox\Server\Gameplay\Processing\FurnaceType;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class FurnaceProcessorTest extends TestCase
{
    public function testFuelAndInputCommitOneOutputAfterBoundedCookTime(): void
    {
        $state = FurnaceBlockEntity::empty(FurnaceType::Furnace, new BlockPosition(1, 64, 1));
        $inventory = $state->inventory
            ->withStack(FurnaceBlockEntity::SLOT_INPUT, new ContainerItemStack('minecraft:raw_iron', 2))
            ->withStack(FurnaceBlockEntity::SLOT_FUEL, new ContainerItemStack('minecraft:coal', 1));
        $state = $state->withState($inventory, 0, 0, 0, 0);
        $processor = new FurnaceProcessor(new FurnaceRecipeCatalog(BedrockDataSet::bundled()->recipeRegistry()));

        for ($tick = 0; $tick < $state->cookDuration; ++$tick) {
            $result = $processor->tick($state);
            $state = $result->state;
        }

        self::assertSame(1, $state->inventory->stackAt(FurnaceBlockEntity::SLOT_INPUT)?->count);
        self::assertSame('minecraft:iron_ingot', $state->inventory->stackAt(FurnaceBlockEntity::SLOT_RESULT)?->identifier);
        self::assertNull($state->inventory->stackAt(FurnaceBlockEntity::SLOT_FUEL));
        self::assertGreaterThan(0, $state->storedExperienceMilli);
    }

    public function testFullOutputDoesNotConsumeFuelOrInput(): void
    {
        $state = FurnaceBlockEntity::empty(FurnaceType::Furnace, new BlockPosition(1, 64, 1));
        $inventory = $state->inventory
            ->withStack(FurnaceBlockEntity::SLOT_INPUT, new ContainerItemStack('minecraft:raw_iron', 1))
            ->withStack(FurnaceBlockEntity::SLOT_FUEL, new ContainerItemStack('minecraft:coal', 1))
            ->withStack(FurnaceBlockEntity::SLOT_RESULT, new ContainerItemStack('minecraft:iron_ingot', 64));
        $state = $state->withState($inventory, 0, 0, 0, 0);

        $result = (new FurnaceProcessor(new FurnaceRecipeCatalog(BedrockDataSet::bundled()->recipeRegistry())))->tick($state);

        self::assertSame($state, $result->state);
        self::assertFalse($result->fuelConsumed);
    }

    public function testLavaBucketLeavesBucketResidue(): void
    {
        $state = FurnaceBlockEntity::empty(FurnaceType::Furnace, new BlockPosition(1, 64, 1));
        $inventory = $state->inventory
            ->withStack(FurnaceBlockEntity::SLOT_INPUT, new ContainerItemStack('minecraft:raw_iron', 1))
            ->withStack(FurnaceBlockEntity::SLOT_FUEL, new ContainerItemStack('minecraft:lava_bucket', 1));
        $state = $state->withState($inventory, 0, 0, 0, 0);

        $result = (new FurnaceProcessor(new FurnaceRecipeCatalog(BedrockDataSet::bundled()->recipeRegistry())))->tick($state);

        self::assertSame('minecraft:bucket', $result->state->inventory->stackAt(FurnaceBlockEntity::SLOT_FUEL)?->identifier);
        self::assertTrue($result->fuelConsumed);
    }
}
