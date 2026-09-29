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
use Bedriox\Server\Gameplay\Processing\CampfireBlockEntity;
use Bedriox\Server\Gameplay\Processing\CampfireProcessor;
use Bedriox\Server\Gameplay\Processing\CampfireType;
use Bedriox\Server\Gameplay\Processing\FurnaceRecipeCatalog;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class CampfireProcessorTest extends TestCase
{
    public function testEachPositionAdvancesIndependentlyAndCompletesAsWorldOutput(): void
    {
        $state = CampfireBlockEntity::empty(CampfireType::Campfire, new BlockPosition(2, 64, 2));
        $state = $state->withState(
            $state->inventory->withStack(0, new ContainerItemStack('minecraft:beef', 1)),
            [0 => 599],
            [0 => 600],
        );
        $processor = new CampfireProcessor(new FurnaceRecipeCatalog(BedrockDataSet::bundled()->recipeRegistry()));

        $result = $processor->tick($state, true);

        self::assertNull($result->state->inventory->stackAt(0));
        self::assertSame('minecraft:cooked_beef', $result->completed[0]->identifier);
        self::assertFalse($result->state->active());
    }

    public function testExtinguishedCampfireDoesNotAdvance(): void
    {
        $state = CampfireBlockEntity::empty(CampfireType::Campfire, new BlockPosition(2, 64, 2));
        $state = $state->withState(
            $state->inventory->withStack(0, new ContainerItemStack('minecraft:beef', 1)),
            [0 => 10],
            [0 => 600],
        );
        $result = (new CampfireProcessor(new FurnaceRecipeCatalog(BedrockDataSet::bundled()->recipeRegistry())))
            ->tick($state, false);

        self::assertSame($state, $result->state);
        self::assertSame([], $result->completed);
    }
}
