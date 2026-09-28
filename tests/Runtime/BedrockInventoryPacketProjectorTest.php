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

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\ItemDefinition;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Nbt\Tag;
use Bedriox\Api\Nbt\TagType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CreativeInventoryCategory as DataCreativeInventoryCategory;
use Bedriox\Data\CreativeInventoryItem as DataCreativeInventoryItem;
use Bedriox\Data\ItemNetworkRegistry;
use Bedriox\Data\NetworkBlockStateRegistry;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\CreativeItemCategory as ProtocolCreativeItemCategory;
use Bedriox\Protocol\Packet\CreativeItemEntry as ProtocolCreativeItemEntry;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\OwnedItemRegistrar;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\ItemExtraDataCodec;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use PHPUnit\Framework\TestCase;

final class BedrockInventoryPacketProjectorTest extends TestCase
{
    public function testCreativeContentProjectsEveryAdmittedGroupAndEntryExactly(): void
    {
        $data = BedrockDataSet::bundled();
        $projector = $this->projector($data);
        $content = $projector->creativeContent();

        $admitted = $data->creativeInventoryRegistry();
        $items = $data->itemNetworkRegistry();
        $blocks = $data->blockStateRegistry();
        self::assertCount(count($admitted->groups()), $content->groups);
        self::assertCount(count($admitted->entries()), $content->entries);

        $groupIndexes = [];
        foreach ($admitted->groups() as $index => $group) {
            $groupIndexes[$group->id()] = $index;
            self::assertSame(self::protocolCategory($group->category()), $content->groups[$index]->category);
            self::assertSame($group->name(), $content->groups[$index]->name);
            self::assertCreativeItemEquals($group->icon(), $content->groups[$index]->icon, $items, $blocks);
        }

        foreach ($admitted->entries() as $index => $entry) {
            $groupId = $entry->groupId();
            self::assertNotNull($groupId);
            self::assertSame($entry->creativeNetworkId(), $content->entries[$index]->networkId);
            self::assertSame($groupIndexes[$groupId], $content->entries[$index]->groupId);
            self::assertCreativeItemEquals($entry->item(), $content->entries[$index]->item, $items, $blocks);
        }

        self::assertEquals($content, CreativeContentPacket::decode($content->encode()));
    }

    public function testDuplicateCreativeVariantsKeepTheirAdmittedIdsAndVariantData(): void
    {
        $data = BedrockDataSet::bundled();
        $items = $data->itemNetworkRegistry();
        $blocks = $data->blockStateRegistry();
        $variants = [];
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            $item = $entry->item();
            $variants[$item->identifier()][] = $entry;
        }
        $duplicateIdentifier = null;
        foreach ($variants as $identifier => $entries) {
            $signatures = [];
            foreach ($entries as $entry) {
                $item = $entry->item();
                $signatures[implode('|', [
                    (string) $item->damage(),
                    $item->blockState()?->canonicalKey() ?? '',
                    $item->nbt() === null ? '' : base64_encode($item->nbt()),
                ])] = true;
            }
            if (count($signatures) > 1) {
                $duplicateIdentifier = $identifier;
                break;
            }
        }
        self::assertNotNull($duplicateIdentifier, 'The admitted creative catalog must preserve at least one variant family.');

        $projector = $this->projector($data);
        $first = $projector->creativeContent();
        $second = $projector->creativeContent();
        $firstById = [];
        $secondById = [];
        foreach ($first->entries as $entry) {
            $firstById[$entry->networkId] = $entry;
        }
        foreach ($second->entries as $entry) {
            $secondById[$entry->networkId] = $entry;
        }
        self::assertSame(array_keys($firstById), array_keys($secondById));

        foreach ($variants[$duplicateIdentifier] as $entry) {
            $networkId = $entry->creativeNetworkId();
            self::assertArrayHasKey($networkId, $firstById);
            self::assertCreativeItemEquals($entry->item(), $firstById[$networkId]->item, $items, $blocks);
            self::assertEquals($firstById[$networkId], $secondById[$networkId]);
        }
    }

    public function testCreativeVariantSelectionPreservesAuxNbtAndBlockStateAuthoritatively(): void
    {
        $data = BedrockDataSet::bundled();
        $projector = $this->projector($data);
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $examples = ['aux' => null, 'nbt' => null, 'block' => null];
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            $item = $entry->item();
            $examples['aux'] ??= $item->damage() > 0 ? $entry : null;
            $examples['nbt'] ??= $item->nbt() !== null ? $entry : null;
            $examples['block'] ??= $item->blockState() !== null ? $entry : null;
        }
        foreach ($examples as $kind => $entry) {
            self::assertNotNull($entry, sprintf('The admitted creative catalog has no %s variant.', $kind));
            $item = $entry->item();
            $selected = $projector->creativeStack($entry->creativeNetworkId(), 731);

            self::assertSame($item->identifier(), $selected->identifier);
            self::assertSame($item->damage(), $selected->auxValue);
            self::assertSame($item->nbt(), $selected->nbt?->toBinary());
            self::assertSame(
                $item->blockState()?->canonicalKey(),
                $selected->placedBlockState === null
                    ? null
                    : $internal->state($selected->placedBlockState)->canonicalKey(),
            );

            $roundTrip = $projector->fromProtocol($projector->toProtocol($selected));
            self::assertSame($selected->identifier, $roundTrip->identifier);
            self::assertSame($selected->auxValue, $roundTrip->auxValue);
            self::assertSame($selected->nbt?->toBinary(), $roundTrip->nbt?->toBinary());
            self::assertSame($selected->placedBlockState?->value, $roundTrip->placedBlockState?->value);
        }
    }

    public function testUnknownCreativeNetworkIdsAreRejected(): void
    {
        $data = BedrockDataSet::bundled();
        $maximum = 0;
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            $maximum = max($maximum, $entry->creativeNetworkId());
        }
        self::assertGreaterThan(0, $maximum);
        $projector = $this->projector($data);

        foreach ([0, $maximum + 1] as $networkId) {
            try {
                $projector->creativeStack($networkId, 1);
                self::fail('An unadvertised creative network ID was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Creative item network ID is not advertised by Bedriox.', $error->getMessage());
            }
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

    public function testLargeInventoryBearingNbtStillLeavesRoomForTheWireEnvelopeAndDamage(): void
    {
        $nbt = ItemNbt::empty()->withTag(
            'contents',
            Tag::list(TagType::STRING, array_fill(0, 90, Tag::string(str_repeat('x', 1_024)))),
        );
        $wire = $this->projector()->toProtocol(new InventoryStack(
            'minecraft:iron_pickaxe',
            1,
            9,
            damage: 17,
            nbt: $nbt,
        ));

        self::assertLessThanOrEqual(ProtocolInventoryItemStack::MAXIMUM_USER_DATA_BYTES, strlen($wire->userData));
        $roundTrip = $this->projector()->fromProtocol($wire);
        self::assertSame(17, $roundTrip->damage);
        self::assertTrue($nbt->equals($roundTrip->nbt ?? ItemNbt::empty()));
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
        $catalog = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
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

    public function testPluginCreativeExtensionKeepsItsAssignedIdAcrossHideAndRestore(): void
    {
        $data = BedrockDataSet::bundled();
        $catalog = ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $baseIdentifiers = [];
        foreach ($data->creativeInventoryRegistry()->entries() as $entry) {
            $baseIdentifiers[$entry->item()->identifier()] = true;
        }
        $extensionIdentifier = null;
        foreach ($data->itemNetworkRegistry()->definitions() as $identifier => $_definition) {
            if (!isset($baseIdentifiers[$identifier])) {
                $extensionIdentifier = $identifier;
                break;
            }
        }
        self::assertNotNull($extensionIdentifier, 'The admitted item registry has no non-creative extension candidate.');

        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $projector = BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
            $catalog,
        );
        $before = $projector->creativeContent();
        $registrar = new OwnedItemRegistrar('TestPlugin', $catalog);
        $registrar->register(new ItemDefinition($extensionIdentifier), true);
        $shown = $projector->creativeContent();
        $extensionRuntimeId = $data->itemNetworkRegistry()
            ->definitionForIdentifier($extensionIdentifier)->networkRuntimeId();
        $extensionId = self::creativeIdForRuntimeId($shown, $extensionRuntimeId);
        self::assertNotNull($extensionId);
        self::assertCount(count($before->entries) + 1, $shown->entries);

        $registrar->register(new ItemDefinition($extensionIdentifier, creative: false), true);
        self::assertNull(self::creativeIdForRuntimeId($projector->creativeContent(), $extensionRuntimeId));

        $registrar->register(new ItemDefinition($extensionIdentifier), true);
        $restored = $projector->creativeContent();
        self::assertSame($extensionId, self::creativeIdForRuntimeId($restored, $extensionRuntimeId));
        foreach ($before->entries as $entry) {
            $restoredEntry = self::creativeEntry($restored, $entry->networkId);
            self::assertNotNull($restoredEntry);
            self::assertEquals($entry, $restoredEntry);
        }
    }

    private function projector(?BedrockDataSet $data = null): BedrockInventoryPacketProjector
    {
        $data ??= BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());

        return BedrockInventoryPacketProjector::fromData(
            $data,
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        );
    }

    private static function assertCreativeItemEquals(
        DataCreativeInventoryItem $expected,
        ProtocolInventoryItemStack $actual,
        ItemNetworkRegistry $items,
        NetworkBlockStateRegistry $blocks,
    ): void {
        self::assertSame(
            $items->definitionForIdentifier($expected->identifier())->networkRuntimeId(),
            $actual->runtimeId,
        );
        self::assertSame($expected->count(), $actual->count);
        self::assertSame($expected->damage(), $actual->aux);
        self::assertNull($actual->stackNetworkId);
        self::assertSame(
            $expected->blockState() === null
                ? 0
                : $blocks->networkRuntimeId($expected->blockState()),
            $actual->blockRuntimeId,
        );
        self::assertSame($expected->nbt(), ItemExtraDataCodec::decode($actual->userData)?->toBinary());
    }

    private static function protocolCategory(DataCreativeInventoryCategory $category): ProtocolCreativeItemCategory
    {
        return match ($category) {
            DataCreativeInventoryCategory::Construction => ProtocolCreativeItemCategory::Construction,
            DataCreativeInventoryCategory::Nature => ProtocolCreativeItemCategory::Nature,
            DataCreativeInventoryCategory::Equipment => ProtocolCreativeItemCategory::Equipment,
            DataCreativeInventoryCategory::Items => ProtocolCreativeItemCategory::Items,
        };
    }

    private static function creativeIdForRuntimeId(CreativeContentPacket $content, int $runtimeId): ?int
    {
        foreach ($content->entries as $entry) {
            if ($entry->item->runtimeId === $runtimeId) {
                return $entry->networkId;
            }
        }

        return null;
    }

    private static function creativeEntry(CreativeContentPacket $content, int $networkId): ?ProtocolCreativeItemEntry
    {
        foreach ($content->entries as $entry) {
            if ($entry->networkId === $networkId) {
                return $entry;
            }
        }

        return null;
    }
}
