<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\SupportedInventoryItem;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use InvalidArgumentException;

/** Sole translation boundary between authoritative inventory values and the active Bedrock registry. */
final readonly class BedrockInventoryPacketProjector
{
    /** @param array<string, int> $itemRuntimeIds */
    public function __construct(
        private array $itemRuntimeIds,
        private BlockNetworkTranslator $blocks,
    ) {
        foreach (SupportedInventoryItem::IDENTIFIERS as $identifier) {
            $runtimeId = $itemRuntimeIds[$identifier] ?? null;
            if (!is_int($runtimeId) || $runtimeId < -0x8000 || $runtimeId > 0x7fff) {
                throw new InvalidArgumentException('Supported item runtime ID is missing or outside the protocol range.');
            }
        }
    }

    public static function fromData(BedrockDataSet $data, BlockNetworkTranslator $blocks): self
    {
        $items = $data->requiredItems();
        $runtimeIds = [];
        foreach (SupportedInventoryItem::IDENTIFIERS as $identifier) {
            $item = $items[$identifier] ?? null;
            if (!is_array($item)) {
                throw new InvalidArgumentException('The active item registry is missing a supported inventory item.');
            }
            $runtimeIds[$identifier] = $item['runtime_id'];
        }

        return new self($runtimeIds, $blocks);
    }

    public function toProtocol(?InventoryStack $stack): ProtocolInventoryItemStack
    {
        if ($stack === null) {
            return ProtocolInventoryItemStack::empty();
        }
        if (!SupportedInventoryItem::supports($stack->identifier)
            || ($stack->identifier === 'minecraft:grass_block') !== ($stack->placedBlockState !== null)) {
            throw new InvalidArgumentException('Inventory stack has no supported Bedrock projection.');
        }

        return new ProtocolInventoryItemStack(
            $this->itemRuntimeIds[$stack->identifier],
            $stack->count,
            0,
            $stack->stackNetworkId,
            $stack->placedBlockState === null ? 0 : $this->blocks->toNetwork($stack->placedBlockState),
            '',
        );
    }

}
