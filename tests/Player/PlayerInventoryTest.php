<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Player;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;
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
use Bedriox\Server\World\Block\VanillaBlockStates;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class PlayerInventoryTest extends TestCase
{
    public function testCraftingInputRequiresTheActiveGridWireOffset(): void
    {
        $inventory = PlayerInventory::empty();
        $inventory->replaceSlot(0, new InventoryStack('minecraft:oak_planks', 1, 1));
        $main = $inventory->stackAt(0);
        self::assertNotNull($main);

        $wrongPlayerOffset = $inventory->applyStackRequest(1, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, $main->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::CraftingInput,
                0,
                0,
                FullContainerName::CRAFTING_INPUT,
                responseSlot: 32,
            ),
            1,
        )]);
        self::assertFalse($wrongPlayerOffset->success);
        self::assertSame('slot', $wrongPlayerOffset->reason);
        self::assertNotNull($inventory->stackAt(0));
        self::assertNull($inventory->craftingStack(0));

        $inventory->setCraftingGridWidth(3);
        $wrongTableOffset = $inventory->applyStackRequest(2, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, $main->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::CraftingInput,
                0,
                0,
                FullContainerName::CRAFTING_INPUT,
                responseSlot: 28,
            ),
            1,
        )]);
        self::assertFalse($wrongTableOffset->success);
        self::assertSame('slot', $wrongTableOffset->reason);

        $accepted = $inventory->applyStackRequest(3, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, $main->stackNetworkId),
            new InventorySlotReference(
                InventoryContainer::CraftingInput,
                0,
                0,
                FullContainerName::CRAFTING_INPUT,
                responseSlot: 32,
            ),
            1,
        )]);
        self::assertTrue($accepted->success, $accepted->reason);
        self::assertNull($inventory->stackAt(0));
        self::assertSame('minecraft:oak_planks', $inventory->craftingStack(0)?->identifier);
    }

    public function testIncompleteCraftingOutputRollsBackConsumedIngredients(): void
    {
        $inventory = PlayerInventory::empty();
        $inventory->replaceSlot(0, new InventoryStack('minecraft:oak_planks', 1, 1));
        $main = $inventory->stackAt(0);
        self::assertNotNull($main);
        self::assertTrue($inventory->applyStackRequest(1, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, $main->stackNetworkId),
            new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
            1,
        )])->success);
        $input = $inventory->craftingStack(0);
        self::assertNotNull($input);

        $result = $inventory->applyStackRequest(
            2,
            [new InventoryStackRequestAction(
                InventoryStackRequestActionType::Consume,
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                1,
            )],
            createdOutputUnlimited: false,
            createdOutputs: [new InventoryStack('minecraft:oak_button', 1, 1)],
        );

        self::assertFalse($result->success);
        self::assertSame('unfinished_crafting_result', $result->reason);
        self::assertSame(1, $inventory->craftingStack(0)?->count);
        self::assertNull($inventory->stackAt(0));
    }

    public function testCraftingInputAndBoundedCreatedOutputCommitAtomically(): void
    {
        $inventory = PlayerInventory::empty();
        $inventory->replaceSlot(0, new InventoryStack('minecraft:oak_planks', 2, 1));
        $main = $inventory->stackAt(0);
        self::assertNotNull($main);
        $move = $inventory->applyStackRequest(10, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, $main->stackNetworkId),
            new InventorySlotReference(InventoryContainer::CraftingInput, 0, 0),
            2,
        )]);
        self::assertTrue($move->success);
        $input = $inventory->craftingStack(0);
        self::assertNotNull($input);

        $result = $inventory->applyStackRequest(
            11,
            [
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Consume,
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    new InventorySlotReference(InventoryContainer::CraftingInput, 0, $input->stackNetworkId),
                    2,
                ),
                new InventoryStackRequestAction(
                    InventoryStackRequestActionType::Take,
                    new InventorySlotReference(InventoryContainer::CreatedOutput, 50, 0),
                    new InventorySlotReference(InventoryContainer::Main, 1, 0),
                    4,
                ),
            ],
            createdOutputUnlimited: false,
            createdOutputs: [new InventoryStack('minecraft:stick', 4, 1)],
        );

        self::assertTrue($result->success, $result->reason);
        self::assertNull($inventory->craftingStack(0));
        $crafted = $inventory->stackAt(1);
        self::assertNotNull($crafted);
        self::assertSame('minecraft:stick', $crafted->identifier);
        self::assertSame(4, $crafted->count);
    }

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

    public function testDropRemovesOnlyTheAuthoritativeQuantityAndRefreshesTheRemainderId(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->removeForDrop(
            -7,
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 64),
            5,
            new InventoryStack('minecraft:grass_block', 64, 1, $this->palette()->grassBlock),
        );

        self::assertTrue($result->success);
        self::assertTrue($result->selectedStackChanged);
        self::assertInstanceOf(InventoryStack::class, $result->removed);
        self::assertSame(5, $result->removed->count);
        $remaining = $inventory->stackAt(0);
        self::assertInstanceOf(InventoryStack::class, $remaining);
        self::assertSame(59, $remaining->count);
        self::assertNotSame(1, $remaining->stackNetworkId);
    }

    public function testDropRejectsStaleDescriptorsWithoutMutatingInventory(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $result = $inventory->removeForDrop(
            -8,
            new InventorySlotReference(InventoryContainer::Main, 0, 99, expectedCount: 64),
            1,
            new InventoryStack('minecraft:grass_block', 64, 1, $this->palette()->grassBlock),
        );

        self::assertFalse($result->success);
        self::assertSame('stack_network_id', $result->reason);
        $remaining = $inventory->stackAt(0);
        self::assertInstanceOf(InventoryStack::class, $remaining);
        self::assertSame(64, $remaining->count);
        self::assertSame(1, $remaining->stackNetworkId);
    }

    public function testMineBlockPredictionAcknowledgesOnlyTheHotbarSlotWithoutMutatingIt(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $reference = new InventorySlotReference(InventoryContainer::Main, 0, 0);

        $result = $inventory->applyStackRequest(-13, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::MineBlock,
            $reference,
            $reference,
        )]);

        self::assertTrue($result->success);
        self::assertSame([$reference], $result->affectedSlots);
        self::assertFalse($result->selectedStackChanged);
        $stack = $inventory->selectedStack();
        self::assertNotNull($stack);
        self::assertSame(64, $stack->count);
        self::assertSame(1, $stack->stackNetworkId);
    }

    public function testAddableQuantityAccountsForCompatibleStacksAndEmptySlotsWithoutMutation(): void
    {
        $inventory = PlayerInventory::starter($this->palette());
        $incoming = new InventoryStack('minecraft:grass_block', 1, 7, $this->palette()->grassBlock);

        self::assertSame((35 * 64), $inventory->addableQuantity($incoming));
        self::assertSame(64, $inventory->stackAt(0)?->count);

        $matching = new InventoryStack('minecraft:grass_block', 1, 8, $this->palette()->grassBlock);
        $inventory->replaceSlot(0, new InventoryStack('minecraft:grass_block', 60, 1, $this->palette()->grassBlock));
        self::assertSame((35 * 64) + 4, $inventory->addableQuantity($matching));
        self::assertSame(60, $inventory->stackAt(0)?->count);
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

    public function testRestoreAcceptsArbitraryItemsAndPreservesDamageWithFreshSessionIds(): void
    {
        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond_pickaxe', 1, 915)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('example:custom_item', 12, 3)),
        ], 0, new PlayerInventoryStackState('minecraft:iron_shovel', 1, 22));

        $inventory = PlayerInventory::restore($state, $this->palette());
        $first = $inventory->stackAt(0);
        $second = $inventory->stackAt(1);
        $cursor = $inventory->cursorStack();
        self::assertInstanceOf(InventoryStack::class, $first);
        self::assertInstanceOf(InventoryStack::class, $second);
        self::assertInstanceOf(InventoryStack::class, $cursor);

        self::assertSame(1, $first->stackNetworkId);
        self::assertSame(915, $first->damage);
        self::assertSame(2, $second->stackNetworkId);
        self::assertSame(3, $second->damage);
        self::assertSame(3, $cursor->stackNetworkId);
        self::assertSame(22, $cursor->damage);
        self::assertEquals($state, $inventory->exportState());
    }

    public function testAuxIsPreservedAcrossStackCopiesInventoryStateAndCursorRestore(): void
    {
        $stack = new InventoryStack('minecraft:grass_block', 3, 7, $this->palette()->grassBlock, 11, auxValue: 42);
        self::assertSame(42, $stack->decrement()?->auxValue);
        self::assertSame(42, $stack->withCountAndNetworkId(2, 8)->auxValue);
        self::assertSame(42, $stack->withDamage(12)->auxValue);

        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:grass_block', 3, 11, auxValue: 42)),
        ], 0, new PlayerInventoryStackState('minecraft:grass_block', 1, 9, auxValue: 73));
        $inventory = PlayerInventory::restore($state, $this->palette());

        self::assertSame(42, $inventory->stackAt(0)?->auxValue);
        self::assertSame(73, $inventory->cursorStack()?->auxValue);
        self::assertEquals($state, $inventory->exportState());
    }

    public function testStacksWithDifferentAuxValuesDoNotMerge(): void
    {
        $inventory = PlayerInventory::restore(new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:grass_block', 2, auxValue: 1)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:grass_block', 2, auxValue: 2)),
        ], 0), $this->palette());

        $result = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 1, 2, expectedCount: 2),
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 2),
            1,
        )]);

        self::assertFalse($result->success);
        self::assertSame('destination_item', $result->reason);
    }

    public function testCatalogAndBlockRegistryRestorePlaceableCreativeBlockState(): void
    {
        $data = BedrockDataSet::bundled();
        $blockStates = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($blockStates);
        $catalog = new ItemCatalog([
            new ItemType('minecraft:cobblestone', placedBlockState: VanillaBlockStates::cobblestone()),
        ], $data->itemNetworkRegistry());
        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:cobblestone', 4)),
        ], 0, new PlayerInventoryStackState('minecraft:cobblestone', 1));

        $inventory = PlayerInventory::restore($state, $palette, $catalog, $blockStates);

        $expected = $blockStates->internalId(VanillaBlockStates::cobblestone())->value;
        self::assertSame($expected, $inventory->stackAt(0)?->placedBlockState?->value);
        self::assertSame($expected, $inventory->cursorStack()?->placedBlockState?->value);
        self::assertEquals($state, $inventory->exportState());
    }

    public function testStacksWithDifferentDamageDoNotMerge(): void
    {
        $inventory = PlayerInventory::restore(new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond_pickaxe', 1, 1)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:diamond_pickaxe', 1, 2)),
        ], 0), $this->palette());

        $result = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 1, 2, expectedCount: 1),
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 1),
            1,
        )]);

        self::assertFalse($result->success);
        self::assertSame('destination_item', $result->reason);
    }

    public function testStacksWithDifferentCustomNbtCannotMergeAndKeepTheirData(): void
    {
        $first = ItemNbt::empty()->withString('bedriox:feature', 'first');
        $second = ItemNbt::empty()->withString('bedriox:feature', 'second');
        $inventory = PlayerInventory::restore(new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond', 3, nbt: $first)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:diamond', 4, nbt: $second)),
        ], 0), $this->palette());

        $result = $inventory->applyStackRequest(0, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 1, 2, expectedCount: 4),
            new InventorySlotReference(InventoryContainer::Main, 0, 1, expectedCount: 3),
            1,
        )]);

        self::assertFalse($result->success);
        self::assertSame('destination_item', $result->reason);
        self::assertSame('first', $inventory->stackAt(0)?->nbt?->string('bedriox:feature'));
        self::assertSame('second', $inventory->stackAt(1)?->nbt?->string('bedriox:feature'));
    }

    public function testCreativeCreatedOutputIsAuthoritativeUnlimitedAndRequestLocal(): void
    {
        $inventory = PlayerInventory::empty();
        $created = new InventoryStack('minecraft:grass_block', 64, 1, $this->palette()->grassBlock);
        $result = $inventory->applyStackRequest(-11, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -11),
                new InventorySlotReference(InventoryContainer::Main, 0, 0),
                64,
            ),
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -11),
                new InventorySlotReference(InventoryContainer::Main, 1, 0),
                64,
            ),
        ], $created);

        self::assertTrue($result->success);
        self::assertSame(64, $inventory->stackAt(0)?->count);
        self::assertSame(64, $inventory->stackAt(1)?->count);
        self::assertSame([0, 1], array_map(
            static fn(InventorySlotReference $reference): int => $reference->slot,
            $result->affectedSlots,
        ));

        $missing = PlayerInventory::empty()->applyStackRequest(-12, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -12),
            new InventorySlotReference(InventoryContainer::Main, 0, 0),
            1,
        )]);
        self::assertFalse($missing->success);
        self::assertSame('source_count', $missing->reason);

        $swapped = PlayerInventory::empty();
        $swapResult = $swapped->applyStackRequest(-13, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Swap,
            new InventorySlotReference(InventoryContainer::CreatedOutput, 50, -13),
            new InventorySlotReference(InventoryContainer::Main, 2, 0),
        )], $created);
        self::assertTrue($swapResult->success);
        self::assertSame(64, $swapped->stackAt(2)?->count);
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

    public function testArmorAndOffhandTransfersAreAtomicAndServerValidated(): void
    {
        $data = BedrockDataSet::bundled();
        $blockStates = new BlockStateRegistry($data->blockStateRegistry()->states());
        $catalog = ItemCatalog::vanilla($data->itemNetworkRegistry());
        $inventory = PlayerInventory::restore(new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:diamond_helmet', 1)),
            new PlayerInventoryEntry(1, new PlayerInventoryStackState('minecraft:apple', 4)),
            new PlayerInventoryEntry(2, new PlayerInventoryStackState('minecraft:iron_boots', 1)),
        ], 0), $this->palette(), $catalog, $blockStates);

        $helmet = $inventory->applyStackRequest(-31, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 0, 1),
            new InventorySlotReference(InventoryContainer::Armor, 0, 0),
            1,
        )]);
        self::assertTrue($helmet->success);
        self::assertNull($inventory->stackAt(0));
        self::assertSame('minecraft:diamond_helmet', $inventory->armorStack(0)?->identifier);

        $wrongSlot = $inventory->applyStackRequest(-32, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 2, 3),
            new InventorySlotReference(InventoryContainer::Armor, 1, 0),
            1,
        )]);
        self::assertFalse($wrongSlot->success);
        self::assertSame('equipment_slot', $wrongSlot->reason);
        self::assertSame('minecraft:iron_boots', $inventory->stackAt(2)?->identifier);
        self::assertNull($inventory->armorStack(1));

        $offhand = $inventory->applyStackRequest(-33, [new InventoryStackRequestAction(
            InventoryStackRequestActionType::Take,
            new InventorySlotReference(InventoryContainer::Main, 1, 2),
            new InventorySlotReference(InventoryContainer::Offhand, 0, 0),
            4,
        )]);
        self::assertTrue($offhand->success);
        self::assertNull($inventory->stackAt(1));
        self::assertSame(4, $inventory->offhandStack()?->count);
        self::assertSame(3, $inventory->defensePoints());
    }

    public function testEquipmentStateRoundTripsWithoutSessionNetworkIds(): void
    {
        $data = BedrockDataSet::bundled();
        $blockStates = new BlockStateRegistry($data->blockStateRegistry()->states());
        $catalog = ItemCatalog::vanilla($data->itemNetworkRegistry());
        $state = new PlayerInventoryState(
            [],
            0,
            armor: [new PlayerInventoryEntry(
                3,
                new PlayerInventoryStackState('minecraft:netherite_boots', 1, damage: 7),
            )],
            offhand: new PlayerInventoryStackState('minecraft:shield', 1, damage: 3),
        );

        $inventory = PlayerInventory::restore($state, $this->palette(), $catalog, $blockStates);
        $exported = $inventory->exportState();

        $boots = $inventory->armorStack(3);
        self::assertNotNull($boots);
        self::assertSame('minecraft:netherite_boots', $boots->identifier);
        self::assertSame(7, $boots->damage);
        $offhand = $inventory->offhandStack();
        self::assertNotNull($offhand);
        self::assertSame('minecraft:shield', $offhand->identifier);
        self::assertNotNull($exported->offhand);
        self::assertSame(3, $exported->offhand->damage);
        self::assertSame(3, $exported->armor[0]->slot);
    }

    public function testEnderChestStateRestoresWithFreshSessionIds(): void
    {
        $state = new PlayerInventoryState(
            [new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:grass_block', 1))],
            0,
            enderChest: [
                new PlayerInventoryEntry(26, new PlayerInventoryStackState('minecraft:diamond', 3)),
                new PlayerInventoryEntry(2, new PlayerInventoryStackState('minecraft:ender_pearl', 8)),
            ],
        );

        $inventory = PlayerInventory::restore($state, $this->palette());

        self::assertCount(PlayerInventory::ENDER_CHEST_SLOT_COUNT, $inventory->enderChestSlots());
        self::assertSame(2, $inventory->enderChestStack(2)?->stackNetworkId);
        self::assertSame(3, $inventory->enderChestStack(26)?->stackNetworkId);
        self::assertEquals($state, $inventory->exportState());
    }

    public function testEnderChestMutationSignalsPersistenceChangesAndExportsCanonicalState(): void
    {
        $inventory = PlayerInventory::empty();
        self::assertFalse($inventory->replaceEnderChestSlot(4, null));

        self::assertTrue($inventory->replaceEnderChestSlot(
            4,
            new InventoryStack('minecraft:diamond', 5, 41),
        ));
        self::assertFalse($inventory->replaceEnderChestSlot(
            4,
            new InventoryStack('minecraft:diamond', 5, 99),
        ));

        $exported = $inventory->exportState();
        self::assertCount(1, $exported->enderChest);
        self::assertSame(4, $exported->enderChest[0]->slot);
        self::assertSame('minecraft:diamond', $exported->enderChest[0]->stack->identifier);
        self::assertSame(5, $exported->enderChest[0]->stack->count);
        self::assertTrue($inventory->replaceEnderChestSlot(4, null));
        self::assertSame([], $inventory->exportState()->enderChest);
    }

    public function testEnderChestRejectsOutOfRangeSlotsAndOversizedItemStacks(): void
    {
        $inventory = PlayerInventory::empty(ItemCatalog::vanilla(BedrockDataSet::bundled()->itemNetworkRegistry()));
        foreach ([-1, PlayerInventory::ENDER_CHEST_SLOT_COUNT] as $slot) {
            try {
                $inventory->enderChestStack($slot);
                self::fail('Out-of-range Ender Chest slot was accepted.');
            } catch (InvalidArgumentException) {
            }
        }

        $this->expectException(InvalidArgumentException::class);
        $inventory->replaceEnderChestSlot(0, new InventoryStack('minecraft:iron_pickaxe', 2, 1));
    }

    public function testInventoryStackRejectsAuxOutsideItsBoundedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new InventoryStack(
            'minecraft:grass_block',
            1,
            1,
            auxValue: PlayerInventoryStackState::MAX_AUX_VALUE + 1,
        );
    }

    public function testPersistentInventoryStackRejectsAuxOutsideItsBoundedRange(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new PlayerInventoryStackState(
            'minecraft:grass_block',
            1,
            auxValue: PlayerInventoryStackState::MAX_AUX_VALUE + 1,
        );
    }

    private function palette(): FixedFlatBlockPalette
    {
        return FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry(
            BedrockDataSet::bundled()->blockStateRegistry()->states(),
        ));
    }
}
