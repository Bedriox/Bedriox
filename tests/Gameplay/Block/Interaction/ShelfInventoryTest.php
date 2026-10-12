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

namespace Bedriox\Server\Tests\Gameplay\Block\Interaction;

use Bedriox\Server\Gameplay\Block\Interaction\ShelfInventory;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ShelfInventoryTest extends TestCase
{
    public function testExchangeAtomicallyReturnsDisplacedStack(): void
    {
        $book = new ContainerItemStack('minecraft:book', 32);
        $sword = new ContainerItemStack('minecraft:diamond_sword', 1);
        $placed = (new ShelfInventory())->exchange(1, $book);
        self::assertNull($placed->returnedStack);
        self::assertSame($book, $placed->inventory->stackAt(1));

        $swapped = $placed->inventory->exchange(1, $sword);
        self::assertSame($book, $swapped->returnedStack);
        self::assertSame($sword, $swapped->inventory->stackAt(1));
        self::assertSame(2, $swapped->inventory->revision);
    }

    public function testHitSelectionIsFacingAwareAndBounded(): void
    {
        self::assertSame(0, ShelfInventory::slotFromHit('south', 0.1, 0.5));
        self::assertSame(2, ShelfInventory::slotFromHit('north', 0.1, 0.5));
        self::assertSame(0, ShelfInventory::slotFromHit('west', 0.5, 0.1));
        self::assertSame(2, ShelfInventory::slotFromHit('east', 0.5, 0.1));
        self::assertSame(2, ShelfInventory::slotFromHit('south', 1.0, 0.5));
    }

    public function testInvalidSlotCannotExpandShelfStorage(): void
    {
        $this->expectException(InvalidArgumentException::class);
        (new ShelfInventory())->exchange(3, null);
    }

    public function testNoOpExchangeDoesNotAdvanceRevision(): void
    {
        $inventory = new ShelfInventory();
        $exchange = $inventory->exchange(0, null);
        self::assertSame($inventory, $exchange->inventory);
        self::assertSame(0, $exchange->inventory->revision);
    }
}
