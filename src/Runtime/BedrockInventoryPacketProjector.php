<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\ItemNetworkRegistry;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\CreativeItemCategory;
use Bedriox\Protocol\Packet\CreativeItemEntry;
use Bedriox\Protocol\Packet\CreativeItemGroup;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\BlockNetworkTranslator;

/** Sole translation boundary between authoritative inventory values and the active Bedrock registry. */
final class BedrockInventoryPacketProjector
{
    /** @var array<int, string> */
    private array $creativeIdentifiers;
    private int $creativeRevision = -1;

    public function __construct(
        private ItemNetworkRegistry $items,
        private ItemCatalog $gameplayItems,
        private BlockNetworkTranslator $blocks,
    ) {
        $this->creativeIdentifiers = [];
        $this->synchronizeCreativeIdentifiers();
    }

    public static function fromData(BedrockDataSet $data, BlockNetworkTranslator $blocks, ?ItemCatalog $gameplayItems = null): self
    {
        $items = $data->itemNetworkRegistry();

        return new self($items, $gameplayItems ?? ItemCatalog::vanilla($items), $blocks);
    }

    public function toProtocol(?InventoryStack $stack): ProtocolInventoryItemStack
    {
        if ($stack === null) {
            return ProtocolInventoryItemStack::empty();
        }
        $type = $this->gameplayItems->type($stack->identifier);
        $definition = $this->items->definitionForIdentifier($stack->identifier);
        $itemBlockState = $type->itemBlockState();
        $blockRuntimeId = $itemBlockState === null
            ? 0
            : $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId($itemBlockState));

        return new ProtocolInventoryItemStack(
            $definition->networkRuntimeId(),
            $stack->count,
            0,
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
        $damage = $stack->aux;
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
        $placed = $type->placedBlockState === null
            ? null
            : $this->blocks->internalRegistry()->internalId($type->placedBlockState);
        $itemBlockState = $type->itemBlockState();
        $expectedBlockRuntimeId = $itemBlockState === null
            ? 0 : $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId($itemBlockState));
        if ($stack->blockRuntimeId !== $expectedBlockRuntimeId) {
            throw new \InvalidArgumentException('Client item block runtime ID does not match the admitted item.');
        }

        return new InventoryStack(
            $identifier,
            $stack->count,
            $stack->stackNetworkId ?? 1,
            $placed,
            $damage,
            $nbt,
        );
    }

    public function creativeContent(): CreativeContentPacket
    {
        $this->synchronizeCreativeIdentifiers();
        $groups = [
            new CreativeItemGroup(CreativeItemCategory::Construction, 'itemGroup.name.buildingBlocks', $this->creativeProtocolStack('minecraft:grass_block')),
            new CreativeItemGroup(CreativeItemCategory::Nature, 'itemGroup.name.nature', $this->creativeProtocolStack('minecraft:oak_leaves')),
            new CreativeItemGroup(CreativeItemCategory::Equipment, 'itemGroup.name.tools', $this->creativeProtocolStack('minecraft:diamond_pickaxe')),
            new CreativeItemGroup(CreativeItemCategory::Items, 'itemGroup.name.items', $this->creativeProtocolStack('minecraft:diamond')),
        ];
        $entries = [];
        foreach ($this->creativeIdentifiers as $networkId => $identifier) {
            $type = $this->gameplayItems->type($identifier);
            if (!$type->creative) {
                continue;
            }
            $groupId = match (true) {
                $type->tool !== null => 2,
                $type->placedBlockState !== null && (str_contains($identifier, 'leaves')
                    || str_contains($identifier, 'log') || str_contains($identifier, 'ore')) => 1,
                $type->placedBlockState !== null => 0,
                default => 3,
            };
            $entries[] = new CreativeItemEntry($networkId, $this->creativeProtocolStack($identifier), $groupId);
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
        $this->synchronizeCreativeIdentifiers();
        $identifier = $this->creativeIdentifiers[$networkId]
            ?? throw new \InvalidArgumentException('Creative item network ID is not advertised by Bedriox.');
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

    private function creativeProtocolStack(string $identifier): ProtocolInventoryItemStack
    {
        $type = $this->gameplayItems->type($identifier);
        $definition = $this->items->definitionForIdentifier($identifier);

        return new ProtocolInventoryItemStack(
            $definition->networkRuntimeId(),
            1,
            0,
            null,
            $type->itemBlockState() === null
                ? 0
                : $this->blocks->toNetwork($this->blocks->internalRegistry()->internalId($type->itemBlockState())),
            ItemExtraDataCodec::encode(null),
        );
    }

    private function synchronizeCreativeIdentifiers(): void
    {
        if ($this->creativeRevision === $this->gameplayItems->revision()) {
            return;
        }
        $known = array_flip($this->creativeIdentifiers);
        $next = $this->creativeIdentifiers === [] ? 1 : max(array_keys($this->creativeIdentifiers)) + 1;
        foreach ($this->gameplayItems->creativeItems() as $type) {
            if (!isset($known[$type->identifier])) {
                $this->creativeIdentifiers[$next++] = $type->identifier;
            }
        }
        $this->creativeRevision = $this->gameplayItems->revision();
    }

}
