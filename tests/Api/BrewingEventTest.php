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

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\Block\BrewingEvent;
use Bedriox\Api\Event\Block\BrewingFuelConsumeEvent;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockPosition;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(BrewingEvent::class)]
#[CoversClass(BrewingFuelConsumeEvent::class)]
final class BrewingEventTest extends TestCase
{
    public function testResultsAndFuelUsesAreBoundedMutableState(): void
    {
        $position = new BlockPosition(1, 64, 2);
        $brew = new BrewingEvent($position, [null, null, null]);
        $result = new ItemStack('minecraft:potion', 1, auxValue: 21);
        $brew->setResults([$result, null, null]);
        self::assertSame($result, $brew->results()[0]);

        $fuel = new BrewingFuelConsumeEvent($position, new ItemStack('minecraft:blaze_powder', 1), 20);
        $fuel->setFuelUses(40);
        self::assertSame(40, $fuel->fuelUses());
    }

    public function testInvalidMutationsAreRejectedBeforeCommit(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new BrewingEvent(new BlockPosition(0, 64, 0), [null, null, null]))->setResults([null]);
    }
}
