<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerInventoryTest extends TestCase
{
    public function testStarterInventoryHasOneSelectedGrassStackAndExactlyThirtySixSlots(): void
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $inventory = PlayerInventory::starter($palette);

        self::assertCount(36, $inventory->slots());
        self::assertSame(0, $inventory->selectedHotbarSlot());
        $stack = $inventory->selectedStack();
        self::assertInstanceOf(InventoryStack::class, $stack);
        self::assertSame('minecraft:grass_block', $stack->identifier);
        self::assertSame(64, $stack->count);
        self::assertSame(1, $stack->stackNetworkId);
        self::assertSame($palette->grassBlock->value, $stack->placedBlockState?->value);
        self::assertSame(array_fill(1, 35, null), array_slice($inventory->slots(), 1, null, true));
    }

    public function testSelectionAndDecrementAreBoundedAndCannotUnderflow(): void
    {
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
        $inventory = PlayerInventory::starter($palette);
        for ($count = 63; $count >= 0; --$count) {
            $remaining = $inventory->decrementSelectedOne();
            if ($count === 0) {
                self::assertNull($remaining);
            } else {
                self::assertInstanceOf(InventoryStack::class, $remaining);
                self::assertSame($count, $remaining->count);
            }
        }
        self::assertNull($inventory->selectedStack());
        self::assertNull($inventory->decrementSelectedOne());

        $inventory->selectHotbarSlot(8);
        self::assertSame(8, $inventory->selectedHotbarSlot());
        self::assertNull($inventory->selectedStack());
    }

    public function testSelectionRejectsSlotsOutsideTheHotbar(): void
    {
        $this->expectException(InvalidArgumentException::class);
        PlayerInventory::empty()->selectHotbarSlot(9);
    }

    public function testStackRequestsSplitThroughTheCursorWithFreshServerIds(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->applyStackRequest(-5, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            32,
        )]);

        self::assertTrue($result->success);
        self::assertTrue($result->selectedStackChanged);
        self::assertSame(32, $inventory->stackAt(0)?->count);
        self::assertSame(2, $inventory->stackAt(0)->stackNetworkId);
        self::assertSame(32, $inventory->cursorStack()?->count);
        self::assertSame(3, $inventory->cursorStack()->stackNetworkId);

        $result = $inventory->applyStackRequest(-6, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Cursor, 0, 3),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
            32,
        )]);

        self::assertTrue($result->success);
        self::assertNull($inventory->cursorStack());
        self::assertSame(32, $inventory->stackAt(1)?->count);
        self::assertSame(4, $inventory->stackAt(1)->stackNetworkId);
    }

    public function testStackRequestRollsBackEveryActionWhenALaterActionFails(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->applyStackRequest(-5, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
                32,
            ),
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Place,
                new InventorySlotReference(InventoryContainer::Main, 0, -5),
                new InventorySlotReference(InventoryContainer::Main, 1, 0),
                33,
            ),
        ]);

        self::assertFalse($result->success);
        self::assertSame('source_count', $result->reason);
        self::assertSame(64, $inventory->stackAt(0)?->count);
        self::assertSame(1, $inventory->stackAt(0)->stackNetworkId);
        self::assertNull($inventory->stackAt(1));
        self::assertNull($inventory->cursorStack());
    }

    public function testStackRequestRejectsStaleIdsAndSupportsRequestIdLineage(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $split = $inventory->applyStackRequest(-5, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Cursor, 0, 0),
            16,
        )]);
        self::assertTrue($split->success);

        $stale = $inventory->applyStackRequest(-6, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Cursor, 0, 1),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
            16,
        )]);
        self::assertFalse($stale->success);
        self::assertSame('stack_network_id', $stale->reason);

        $lineage = $inventory->applyStackRequest(-6, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Place,
            new InventorySlotReference(InventoryContainer::Cursor, 0, -5),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
            16,
        )]);
        self::assertTrue($lineage->success);
        self::assertNull($inventory->cursorStack());
        self::assertSame(16, $inventory->stackAt(1)?->count);
    }

    public function testStackRequestSwapsAFullStackWithAnEmptyHotbarSlot(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->applyStackRequest(-8, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Swap,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Main, 1, 0),
        )]);

        self::assertTrue($result->success);
        self::assertTrue($result->selectedStackChanged);
        self::assertNull($inventory->stackAt(0));
        self::assertSame(64, $inventory->stackAt(1)?->count);
        self::assertSame(2, $inventory->stackAt(1)->stackNetworkId);
    }

    public function testAuthoritativeStacksCanSplitThenMergeWithoutLosingItems(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $split = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 64),
            new InventorySlotReference(InventoryContainer::Main, 1, 0, expectedCount: 0),
            32,
        )]);
        self::assertTrue($split->success);
        self::assertSame(32, $inventory->stackAt(0)?->count);
        self::assertSame(32, $inventory->stackAt(1)?->count);

        $merge = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 1, 3, expectedCount: 32),
            new InventorySlotReference(InventoryContainer::Main, 0, 2, expectedCount: 32),
            32,
        )]);
        self::assertTrue($merge->success);
        self::assertSame(64, $inventory->stackAt(0)->count);
        self::assertNull($inventory->stackAt(1));
    }

    public function testStackRequestRejectsTransfersWithinTheSameCanonicalSlot(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->applyStackRequest(-9, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1, 12),
            new InventorySlotReference(InventoryContainer::Main, 0, 1, 28),
            1,
        )]);

        self::assertFalse($result->success);
        self::assertSame('same_slot', $result->reason);
        self::assertSame(64, $inventory->stackAt(0)?->count);
        self::assertSame(1, $inventory->stackAt(0)->stackNetworkId);
    }

    public function testPredictedTransferRejectsAClientCountThatDoesNotMatchServerState(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 63),
            new InventorySlotReference(InventoryContainer::Main, 1, 0, expectedCount: 0),
            32,
        )]);

        self::assertFalse($result->success);
        self::assertSame('stack_count', $result->reason);
        self::assertSame(64, $inventory->stackAt(0)?->count);
        self::assertNull($inventory->stackAt(1));
    }

    public function testCanonicalStateRestoresWithFreshOrderedSessionIds(): void
    {
        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(8, new PlayerInventoryStackState('minecraft:grass_block', 12)),
            new PlayerInventoryEntry(2, new PlayerInventoryStackState('minecraft:grass_block', 7)),
        ], 8, new PlayerInventoryStackState('minecraft:grass_block', 3));

        $inventory = PlayerInventory::restore($state, $this->palette());

        self::assertSame(1, $inventory->stackAt(2)?->stackNetworkId);
        self::assertSame(2, $inventory->stackAt(8)?->stackNetworkId);
        self::assertSame(3, $inventory->cursorStack()?->stackNetworkId);
        self::assertSame(8, $inventory->selectedHotbarSlot());
        self::assertEquals($state, $inventory->exportState());
    }

    public function testRestoreRejectsItemsWithoutAnAuthoritativeProjection(): void
    {
        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond', 1)),
        ], 0);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported item');
        PlayerInventory::restore($state, $this->palette());
    }

    public function testIronToolsMoveButCannotBeMergedIntoStacks(): void
    {
        $inventory = PlayerInventory::restore(new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_sword', 1)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:iron_sword', 1)),
        ], 0), $this->palette());

        $merge = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Main, 1, 2),
            1,
        )]);
        self::assertFalse($merge->success);
        self::assertSame('destination_capacity', $merge->reason);
        self::assertSame(1, $inventory->stackAt(0)?->count);
        self::assertSame(1, $inventory->stackAt(1)?->count);

        $swap = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Swap,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Main, 2, 0),
        )]);
        self::assertTrue($swap->success);
        self::assertNull($inventory->stackAt(0));
        self::assertSame('minecraft:iron_sword', $inventory->stackAt(2)?->identifier);
    }

    public function testInventoryStateRejectsDuplicateSlots(): void
    {
        $stack = new PlayerInventoryStackState('minecraft:grass_block', 1);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('duplicate slot');
        new PlayerInventoryState([
            new PlayerInventoryEntry(4, $stack),
            new PlayerInventoryEntry(4, $stack),
        ], 0);
    }

    private function palette(): FixedFlatBlockPalette
    {
        return FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
    }
}
