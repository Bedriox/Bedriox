<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\InventoryItemStack as ProtocolInventoryItemStack;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use InvalidArgumentException;

/** Sole translation boundary between authoritative inventory values and the active Bedrock registry. */
final readonly class BedrockInventoryPacketProjector
{
    private const string GRASS_BLOCK = 'minecraft:grass_block';

    public function __construct(
        private int $grassItemRuntimeId,
        private BlockNetworkTranslator $blocks,
    ) {
        if ($grassItemRuntimeId < -0x8000 || $grassItemRuntimeId > 0x7fff) {
            throw new InvalidArgumentException('Grass item runtime ID is outside the protocol range.');
        }
    }

    public static function fromData(BedrockDataSet $data, BlockNetworkTranslator $blocks): self
    {
        $grass = $data->requiredItems()[self::GRASS_BLOCK] ?? null;
        if (!is_array($grass)) {
            throw new InvalidArgumentException('The active item registry does not contain grass blocks.');
        }

        return new self($grass['runtime_id'], $blocks);
    }

    public function toProtocol(?InventoryStack $stack): ProtocolInventoryItemStack
    {
        if ($stack === null) {
            return ProtocolInventoryItemStack::empty();
        }
        if ($stack->identifier !== self::GRASS_BLOCK || $stack->placedBlockState === null) {
            throw new InvalidArgumentException('Inventory stack has no supported Bedrock projection.');
        }

        return new ProtocolInventoryItemStack(
            $this->grassItemRuntimeId,
            $stack->count,
            0,
            $stack->stackNetworkId,
            $this->blocks->toNetwork($stack->placedBlockState),
            '',
        );
    }

}
