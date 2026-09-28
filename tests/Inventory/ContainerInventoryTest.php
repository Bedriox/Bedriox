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

namespace Bedriox\Server\Tests\Inventory;

use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Inventory\CombinedContainerInventory;
use Bedriox\Server\Inventory\ContainerInventoryTransaction;
use Bedriox\Server\Inventory\ContainerRevisionMismatchException;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContainerInventoryTest extends TestCase
{
    public function testSimpleInventoryTracksExactMutationsAndPersistenceAcknowledgement(): void
    {
        $inventory = new SimpleContainerInventory('world:0:64:0', 3);
        $revision = $inventory->revision();

        self::assertFalse($inventory->isDirty());
        self::assertTrue($inventory->setStack(1, new ItemStack('minecraft:stone', 12), $revision));
        self::assertSame('minecraft:stone', $inventory->stackAt(1)?->identifier);
        self::assertNotSame($revision, $inventory->revision());
        self::assertTrue($inventory->isDirty());
        self::assertFalse($inventory->acknowledgePersistedRevision($revision));
        self::assertTrue($inventory->acknowledgePersistedRevision($inventory->revision()));
        self::assertFalse($inventory->isDirty());
        self::assertFalse($inventory->setStack(1, new ItemStack('minecraft:stone', 12)));
    }

    public function testSimpleInventoryRejectsStaleRevisionWithoutMutation(): void
    {
        $inventory = new SimpleContainerInventory('world:0:64:0', 1);
        $stale = $inventory->revision();
        $inventory->setStack(0, new ItemStack('minecraft:stone', 1));

        try {
            $inventory->setStack(0, new ItemStack('minecraft:dirt', 1), $stale);
            self::fail('Expected a stale revision to be rejected.');
        } catch (ContainerRevisionMismatchException) {
            self::assertSame('minecraft:stone', $inventory->stackAt(0)?->identifier);
        }
    }

    public function testCombinedInventoryMapsLeftThenRightAndPreservesChildOwnership(): void
    {
        $left = new SimpleContainerInventory('world:left', 2);
        $right = new SimpleContainerInventory('world:right', 2);
        $combined = new CombinedContainerInventory('world:double', $left, $right);
        $originalRevision = $combined->revision();

        $combined->setStack(0, new ItemStack('minecraft:apple', 1));
        $combined->setStack(3, new ItemStack('minecraft:stone', 2));

        self::assertSame('minecraft:apple', $left->stackAt(0)?->identifier);
        self::assertSame('minecraft:stone', $right->stackAt(1)?->identifier);
        self::assertSame(['minecraft:apple', null, null, 'minecraft:stone'], array_map(
            static fn(?ItemStack $stack): ?string => $stack?->identifier,
            $combined->contents(),
        ));
        self::assertNotSame($originalRevision, $combined->revision());
        self::assertTrue($combined->isDirty());
        self::assertTrue($combined->acknowledgePersistedRevision($combined->revision()));
        self::assertFalse($combined->isDirty());
    }

    public function testContainerTransactionStagesAllChangesBeforeCommit(): void
    {
        $chest = new SimpleContainerInventory('world:chest', 2, [new ItemStack('minecraft:stone', 4), null]);
        $barrel = new SimpleContainerInventory('world:barrel', 2);
        $transaction = new ContainerInventoryTransaction($chest, $barrel);
        $transaction->stage($chest, 0, null);
        $transaction->stage($barrel, 1, new ItemStack('minecraft:stone', 4));

        self::assertSame('minecraft:stone', $chest->stackAt(0)?->identifier);
        self::assertNull($barrel->stackAt(1));
        self::assertTrue($transaction->commit());
        self::assertNull($chest->stackAt(0));
        $moved = $barrel->stackAt(1);
        self::assertInstanceOf(ItemStack::class, $moved);
        self::assertSame('minecraft:stone', $moved->identifier);
    }

    public function testContainerTransactionRejectsAnyStaleParticipantBeforeCommit(): void
    {
        $first = new SimpleContainerInventory('world:first', 1);
        $second = new SimpleContainerInventory('world:second', 1);
        $transaction = new ContainerInventoryTransaction($first, $second);
        $transaction->stage($first, 0, new ItemStack('minecraft:stone', 1));
        $second->setStack(0, new ItemStack('minecraft:dirt', 1));

        $this->expectException(ContainerRevisionMismatchException::class);
        try {
            $transaction->commit();
        } finally {
            self::assertNull($first->stackAt(0));
        }
    }

    public function testViewerTrackingIsUniqueDeterministicAndBoundedByUuidShape(): void
    {
        $inventory = new SimpleContainerInventory('world:chest', 1);
        $second = '00000000-0000-0000-0000-000000000002';
        $first = '00000000-0000-0000-0000-000000000001';
        $inventory->addViewer($second);
        $inventory->addViewer($first);
        $inventory->addViewer(strtoupper($first));

        self::assertSame([$first, $second], $inventory->viewerUuids());
        $inventory->removeViewer($first);
        self::assertSame([$second], $inventory->viewerUuids());

        $this->expectException(InvalidArgumentException::class);
        $inventory->addViewer('not-a-uuid');
    }

    public function testRejectsUnboundedContainerSizesAndMalformedContents(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new SimpleContainerInventory('world:chest', 257);
    }
}
