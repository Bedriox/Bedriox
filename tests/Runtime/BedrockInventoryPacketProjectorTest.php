<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\OwnedItemRegistrar;
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

    public function testCustomNbtSurvivesTheBedrockItemExtraDataEnvelope(): void
    {
        $nbt = ItemNbt::empty()
            ->withString('bedriox:feature', 'test')
            ->withTag('levels', Tag::list(TagType::INT, [Tag::int(3), Tag::int(7)]));
        $projector = $this->projector();
        $stack = new InventoryStack('minecraft:diamond', 1, 13, damage: 0, nbt: $nbt);

        $wire = $projector->toProtocol($stack);
        self::assertSame("\xff\xff\x01", substr($wire->userData, 0, 3));
        self::assertSame(str_repeat("\x00", 8), substr($wire->userData, -8));
        self::assertSame($nbt->toBinary(), $projector->fromProtocol($wire)->nbt?->toBinary());
    }

    public function testDurabilityUsesTheBedrockDamageTagWithoutPollutingCustomNbt(): void
    {
        $nbt = ItemNbt::empty()->withString('bedriox:feature', 'durable');
        $projector = $this->projector();
        $wire = $projector->toProtocol(new InventoryStack('minecraft:iron_pickaxe', 1, 9, damage: 17, nbt: $nbt));

        self::assertSame(0, $wire->aux);
        $roundTrip = $projector->fromProtocol($wire);
        self::assertSame(17, $roundTrip->damage);
        self::assertSame('durable', $roundTrip->nbt?->string('bedriox:feature'));
        self::assertNull($roundTrip->nbt->tag('Damage'));
    }

    public function testMalformedItemExtraDataIsRejected(): void
    {
        $projector = $this->projector();
        $wire = $projector->toProtocol(new InventoryStack('minecraft:diamond', 1, 1));
        $invalid = new \Bedriox\Protocol\Packet\InventoryItemStack(
            $wire->runtimeId,
            $wire->count,
            $wire->aux,
            $wire->stackNetworkId,
            $wire->blockRuntimeId,
            "\xff\xff\x01\x0a\x00\x00\x00" . str_repeat("\x00", 8),
        );
        // The encoded empty compound is accepted but does not create a distinct item.
        self::assertNull($projector->fromProtocol($invalid)->nbt);

        $truncated = new \Bedriox\Protocol\Packet\InventoryItemStack(
            $wire->runtimeId,
            $wire->count,
            $wire->aux,
            $wire->stackNetworkId,
            $wire->blockRuntimeId,
            "\xff\xff\x01\x0a" . str_repeat("\x00", 8),
        );
        $this->expectException(\InvalidArgumentException::class);
        $projector->fromProtocol($truncated);
    }

    public function testCreativeContentReflectsLiveDefinitionsWithoutRenumberingExistingItems(): void
    {
        $data = BedrockDataSet::bundled();
        $catalog = ItemCatalog::vanilla($data->itemNetworkRegistry());
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
            $catalog,
        );
        $before = $projector->creativeContent();
        $grassId = null;
        foreach ($before->entries as $entry) {
            if ($entry->item->runtimeId === $data->itemNetworkRegistry()->definitionForIdentifier('minecraft:grass_block')->networkRuntimeId()) {
                $grassId = $entry->networkId;
                break;
            }
        }
        self::assertNotNull($grassId);

        $registrar = new OwnedItemRegistrar('TestPlugin', $catalog);
        $registrar->register(new ItemDefinition('minecraft:grass_block', creative: false), true);
        self::assertCount(count($before->entries) - 1, $projector->creativeContent()->entries);
        $registrar->register(new ItemDefinition('minecraft:grass_block'), true);

        $after = $projector->creativeContent();
        self::assertCount(count($before->entries), $after->entries);
        $restoredId = null;
        foreach ($after->entries as $entry) {
            if ($entry->item->runtimeId === $data->itemNetworkRegistry()->definitionForIdentifier('minecraft:grass_block')->networkRuntimeId()) {
                $restoredId = $entry->networkId;
                break;
            }
        }
        self::assertSame($grassId, $restoredId);
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
