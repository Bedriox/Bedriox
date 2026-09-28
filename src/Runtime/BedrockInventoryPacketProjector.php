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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CreativeInventoryCategory as DataCreativeInventoryCategory;
use Bedriox\Data\CreativeInventoryEntry as DataCreativeInventoryEntry;
use Bedriox\Data\CreativeInventoryItem as DataCreativeInventoryItem;
use Bedriox\Data\CreativeInventoryRegistry;
use Bedriox\Data\ItemNetworkRegistry;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\CreativeItemCategory;
use Bedriox\Protocol\Packet\CreativeItemEntry;
use Bedriox\Protocol\Packet\CreativeItemGroup;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemType;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\InternalBlockStateId;

/** Sole translation boundary between authoritative inventory values and the active Bedrock registry. */
final class BedrockInventoryPacketProjector
{
    /** @var array<int, DataCreativeInventoryEntry|ItemType> */
    private array $creativeEntries = [];

    /** @var array<string, int> Stable IDs assigned to plugin-advertised entries outside the vanilla catalog. */
    private array $extensionCreativeIds = [];

    /** @var array<string, array<string, true>> item identifier => admitted canonical block-state keys */
    private array $creativeBlockStates = [];

    /** @var array<string, true> */
    private array $baseCreativeIdentifiers = [];

    private int $creativeRevision = -1;

    public function __construct(
        private ItemNetworkRegistry $items,
        private ItemCatalog $gameplayItems,
        private BlockNetworkTranslator $blocks,
        private CreativeInventoryRegistry $creative,
    ) {
        foreach ($this->creative->entries() as $entry) {
            $item = $entry->item();
            $this->baseCreativeIdentifiers[$item->identifier()] = true;
            if ($item->blockState() !== null) {
                $this->creativeBlockStates[$item->identifier()][$item->blockState()->canonicalKey()] = true;
            }
        }
        $this->synchronizeCreativeEntries();
    }

    public static function fromData(BedrockDataSet $data, BlockNetworkTranslator $blocks, ?ItemCatalog $gameplayItems = null): self
    {
        $items = $data->itemNetworkRegistry();
        $creative = $data->creativeInventoryRegistry();

        return new self(
            $items,
            $gameplayItems ?? ItemCatalog::vanilla(
                $items,
                creative: $creative,
                blockItems: $data->blockItemMappingRegistry(),
            ),
            $blocks,
            $creative,
        );
    }

    public function toProtocol(?InventoryStack $stack): ProtocolInventoryItemStack
    {
        if ($stack === null) {
            return ProtocolInventoryItemStack::empty();
        }
        $type = $this->gameplayItems->type($stack->identifier);
        $definition = $this->items->definitionForIdentifier($stack->identifier);
        $itemBlockState = $stack->placedBlockState ?? ($type->itemBlockState() === null
            ? null
            : $this->blocks->internalRegistry()->internalId($type->itemBlockState()));
        $blockRuntimeId = $itemBlockState === null
            ? 0
            : $this->blocks->toNetwork($itemBlockState);

        return new ProtocolInventoryItemStack(
            $definition->networkRuntimeId(),
            $stack->count,
            $stack->auxValue,
            $stack->stackNetworkId,
            $blockRuntimeId,
            ItemExtraDataCodec::encode($stack->nbt, $stack->damage),
        );
    }

    public function fromProtocol(ProtocolInventoryItemStack $stack): InventoryStack
    {
        if ($stack->runtimeId === 0 || $stack->count < 1) {
            throw new \InvalidArgumentException('Client item descriptor is unsupported.');
        }
        $nbt = ItemExtraDataCodec::decode($stack->userData);
        $damage = 0;
        if ($nbt?->tag('Damage') !== null) {
            $damage = $nbt->int('Damage')
                ?? throw new \InvalidArgumentException('Client item damage tag must be an integer.');
            $nbt = $nbt->withoutTag('Damage');
            if ($nbt->isEmpty()) {
                $nbt = null;
            }
        }
        $identifier = $this->items->definitionForNetworkRuntimeId($stack->runtimeId)->identifier();
        $type = $this->gameplayItems->type($identifier);
        $placed = $stack->blockRuntimeId === 0 ? null : $this->blocks->fromNetwork($stack->blockRuntimeId);
        if (!$this->isAdmittedBlockState($identifier, $type, $placed)) {
            throw new \InvalidArgumentException('Client item block runtime ID does not match the admitted item.');
        }

        return new InventoryStack(
            $identifier,
            $stack->count,
            $stack->stackNetworkId ?? 1,
            $placed,
            $damage,
            $nbt,
            $stack->aux,
        );
    }

    public function creativeContent(): CreativeContentPacket
    {
        $this->synchronizeCreativeEntries();
        $groups = [];
        $groupIndexes = [];
        $firstItemsGroup = null;
        foreach ($this->creative->groups() as $group) {
            $groupIndexes[$group->id()] = count($groups);
            if ($firstItemsGroup === null && $group->category() === DataCreativeInventoryCategory::Items) {
                $firstItemsGroup = count($groups);
            }
            $groups[] = new CreativeItemGroup(
                self::creativeCategory($group->category()),
                $group->name(),
                $this->creativeProtocolStack($group->icon()),
            );
        }
        $entries = [];
        foreach ($this->creativeEntries as $networkId => $entry) {
            if ($entry instanceof DataCreativeInventoryEntry) {
                $groupId = $entry->groupId();
                if ($groupId === null || !isset($groupIndexes[$groupId])) {
                    throw new \LogicException('Admitted creative entry does not identify a projected group.');
                }
                $entries[] = new CreativeItemEntry(
                    $networkId,
                    $this->creativeProtocolStack($entry->item()),
                    $groupIndexes[$groupId],
                );
                continue;
            }
            if ($firstItemsGroup === null) {
                throw new \LogicException('Creative catalog has no items group for plugin entries.');
            }
            $entries[] = new CreativeItemEntry(
                $networkId,
                $this->itemTypeProtocolStack($entry),
                $firstItemsGroup,
            );
        }

        return new CreativeContentPacket($groups, $entries);
    }

    public function toItemActorProtocol(InventoryStack $stack): ProtocolInventoryItemStack
    {
        $projected = $this->toProtocol($stack);

        return new ProtocolInventoryItemStack(
            $projected->runtimeId,
            $projected->count,
            $projected->aux,
            null,
            $projected->blockRuntimeId,
            $projected->userData,
        );
    }

    public function creativeStack(int $networkId, int $stackNetworkId): InventoryStack
    {
        $this->synchronizeCreativeEntries();
        $entry = $this->creativeEntries[$networkId]
            ?? throw new \InvalidArgumentException('Creative item network ID is not advertised by Bedriox.');
        if ($entry instanceof DataCreativeInventoryEntry) {
            $item = $entry->item();
            $identifier = $item->identifier();
            $type = $this->gameplayItems->type($identifier);
            if (!$type->creative) {
                throw new \InvalidArgumentException('Creative item is no longer advertised by Bedriox.');
            }

            return new InventoryStack(
                $identifier,
                min($item->count(), $type->maximumStackSize),
                $stackNetworkId,
                $item->blockState() === null
                    ? null
                    : $this->blocks->internalRegistry()->internalId($item->blockState()),
                nbt: $item->nbt() === null ? null : ItemNbt::fromBinary($item->nbt()),
                auxValue: $item->damage(),
            );
        }
        $identifier = $entry->identifier;
        $type = $this->gameplayItems->type($identifier);
        if (!$type->creative) {
            throw new \InvalidArgumentException('Creative item is no longer advertised by Bedriox.');
        }

        return new InventoryStack(
            $identifier,
            $type->maximumStackSize,
            $stackNetworkId,
            $type->placedBlockState === null
                ? null
                : $this->blocks->internalRegistry()->internalId($type->placedBlockState),
        );
    }

    private function creativeProtocolStack(DataCreativeInventoryItem $item): ProtocolInventoryItemStack
    {
        $definition = $this->items->definitionForIdentifier($item->identifier());
        $nbt = $item->nbt() === null ? null : ItemNbt::fromBinary($item->nbt());

        return new ProtocolInventoryItemStack(
            $definition->networkRuntimeId(),
            $item->count(),
            $item->damage(),
            null,
            $item->blockState() === null
                ? 0
                : $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId($item->blockState())),
            ItemExtraDataCodec::encodeCreative($nbt),
        );
    }

    private function itemTypeProtocolStack(ItemType $type): ProtocolInventoryItemStack
    {
        $definition = $this->items->definitionForIdentifier($type->identifier);

        return new ProtocolInventoryItemStack(
            $definition->networkRuntimeId(),
            1,
            0,
            null,
            $type->itemBlockState() === null
                ? 0
                : $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId($type->itemBlockState())),
            ItemExtraDataCodec::encodeCreative(null),
        );
    }

    private function synchronizeCreativeEntries(): void
    {
        if ($this->creativeRevision === $this->gameplayItems->revision()) {
            return;
        }
        $entries = [];
        $maximumId = 0;
        foreach ($this->creative->entries() as $entry) {
            $maximumId = max($maximumId, $entry->creativeNetworkId());
            if ($this->gameplayItems->type($entry->item()->identifier())->creative) {
                $entries[$entry->creativeNetworkId()] = $entry;
            }
        }
        foreach ($this->gameplayItems->all() as $type) {
            if ($type->owner === null || !$type->creative || isset($this->baseCreativeIdentifiers[$type->identifier])) {
                continue;
            }
            $id = $this->extensionCreativeIds[$type->identifier] ?? null;
            if ($id === null) {
                do {
                    ++$maximumId;
                } while (isset($entries[$maximumId]) || in_array($maximumId, $this->extensionCreativeIds, true));
                $id = $this->extensionCreativeIds[$type->identifier] = $maximumId;
            }
            $entries[$id] = $type;
        }
        ksort($entries, SORT_NUMERIC);
        $this->creativeEntries = $entries;
        $this->creativeRevision = $this->gameplayItems->revision();
    }

    private function isAdmittedBlockState(string $identifier, ItemType $type, ?InternalBlockStateId $placed): bool
    {
        if ($placed === null) {
            return $type->itemBlockState() === null && !isset($this->creativeBlockStates[$identifier]);
        }
        $state = $this->blocks->internalRegistry()->state($placed);
        if (isset($this->creativeBlockStates[$identifier][$state->canonicalKey()])) {
            return true;
        }

        return $type->itemBlockState()?->canonicalKey() === $state->canonicalKey();
    }

    private static function creativeCategory(DataCreativeInventoryCategory $category): CreativeItemCategory
    {
        return match ($category) {
            DataCreativeInventoryCategory::Construction => CreativeItemCategory::Construction,
            DataCreativeInventoryCategory::Nature => CreativeItemCategory::Nature,
            DataCreativeInventoryCategory::Equipment => CreativeItemCategory::Equipment,
            DataCreativeInventoryCategory::Items => CreativeItemCategory::Items,
        };
    }
}
