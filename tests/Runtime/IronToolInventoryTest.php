<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerInventoryEntry;
use Bedriox\Server\Player\PlayerInventoryStackState;
use Bedriox\Server\Player\PlayerInventoryState;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use PHPUnit\Framework\TestCase;

final class IronToolInventoryTest extends TestCase
{
    public function testEveryEssentialsToolRestoresAndProjectsAsARealBedrockItem(): void
    {
        $data = BedrockDataSet::bundled();
        $network = $data->blockStateRegistry();
        $internal = new BlockStateRegistry($network->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $network),
        );
        $identifiers = [
            'minecraft:iron_sword',
            'minecraft:iron_pickaxe',
            'minecraft:iron_axe',
            'minecraft:iron_shovel',
            'minecraft:iron_hoe',
        ];
        $entries = [];
        foreach ($identifiers as $slot => $identifier) {
            $entries[] = new PlayerInventoryEntry($slot, new PlayerInventoryStackState($identifier, 1));
        }

        $inventory = PlayerInventory::restore(new PlayerInventoryState($entries, 0), $palette);
        self::assertEquals(new PlayerInventoryState($entries, 0), $inventory->exportState());
        foreach ($identifiers as $slot => $identifier) {
            $stack = $inventory->stackAt($slot);
            self::assertNotNull($stack);
            self::assertNull($stack->placedBlockState);
            $packet = $projector->toProtocol($stack);
            self::assertSame($data->requiredItems()[$identifier]['runtime_id'], $packet->runtimeId);
            self::assertSame(1, $packet->count);
            self::assertSame(0, $packet->blockRuntimeId);
        }
    }

    public function testToolsCannotRestoreAsStackedItems(): void
    {
        $data = BedrockDataSet::bundled();
        $palette = FixedFlatBlockPalette::fromRegistry(new BlockStateRegistry($data->blockStateRegistry()->states()));
        $state = new PlayerInventoryState([
            new PlayerInventoryEntry(0, new PlayerInventoryStackState('minecraft:iron_sword', 2)),
        ], 0);

        $this->expectException(\InvalidArgumentException::class);
        PlayerInventory::restore($state, $palette);
    }
}
