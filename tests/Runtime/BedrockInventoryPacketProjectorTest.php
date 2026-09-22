<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class BedrockInventoryPacketProjectorTest extends TestCase
{
    public function testCreativeContentUsesDeclaredZeroBasedGroupsAndCanonicalItemData(): void
    {
        $projector = $this->projector();
        $content = $projector->creativeContent();
        $encoded = $content->encode();
        $decoded = CreativeContentPacket::decode($encoded);

        self::assertCount(4, $decoded->groups);
        self::assertNotEmpty($decoded->entries);
        foreach ($decoded->groups as $group) {
            self::assertSame(str_repeat("\x00", 10), $group->icon->userData);
        }
        foreach ($decoded->entries as $entry) {
            self::assertGreaterThanOrEqual(0, $entry->groupId);
            self::assertLessThan(count($decoded->groups), $entry->groupId);
            self::assertSame(str_repeat("\x00", 10), $entry->item->userData);
        }
    }

    public function testCobblestoneDropCarriesItsPlaceableBlockState(): void
    {
        $projector = $this->projector();
        $stack = new InventoryStack('minecraft:cobblestone', 1, 1);
        $network = $projector->toItemActorProtocol($stack);

        self::assertSame(4, $network->runtimeId);
        self::assertSame(6777, $network->blockRuntimeId);
        self::assertNotNull($projector->fromProtocol($network)->placedBlockState);
    }

    private function projector(): BedrockInventoryPacketProjector
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());

        return BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
    }
}
