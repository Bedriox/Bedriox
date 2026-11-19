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

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\AvailableActorIdentifiersPacket;
use Bedriox\Protocol\Packet\BiomeDefinitionListPacket;
use Bedriox\Protocol\Packet\BlockPropertyData;
use Bedriox\Protocol\Packet\ItemRegistryPacket;
use Bedriox\Protocol\Packet\TrimDataPacket;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;

/** Boot-scoped immutable catalogs and packets shared by every world initialization view. */
final readonly class BedrockPlayInitializationResources
{
    /**
     * @param list<array{name: string, id: int, temperature: float, downfall: float, foliage_snow: float,
     *     depth: float, scale: float, map_water_argb: int, rain: bool, tags: list<string>}> $biomeDefinitions
     * @param list<BlockPropertyData> $blockProperties
     */
    private function __construct(
        public array $biomeDefinitions,
        public BlockStateRegistry $internalBlockStates,
        public BlockNetworkTranslator $blockNetworkTranslator,
        public FixedFlatBlockPalette $fixedFlatBlockPalette,
        public ItemCatalog $itemCatalog,
        public BedrockInventoryPacketProjector $inventoryProjector,
        public CraftingCatalog $craftingCatalog,
        public array $blockProperties,
        public ReusablePlayPacket $itemRegistryPacket,
        public ReusablePlayPacket $biomeDefinitionsPacket,
        public ReusablePlayPacket $actorIdentifiersPacket,
        public ReusablePlayPacket $trimDataPacket,
    ) {}

    public static function fromData(
        BedrockDataSet $data,
        ?ItemCatalog $itemCatalog = null,
        ?CraftingCatalog $craftingCatalog = null,
        ?BlockStateRegistry $internalBlockStates = null,
        ?BlockNetworkTranslator $blockNetworkTranslator = null,
        ?FixedFlatBlockPalette $fixedFlatBlockPalette = null,
        ?BedrockInventoryPacketProjector $inventoryProjector = null,
    ): self {
        $networkBlockStates = $data->blockStateRegistry();
        $internalBlockStates ??= new BlockStateRegistry($networkBlockStates->states());
        $blockNetworkTranslator ??= new BlockNetworkTranslator($internalBlockStates, $networkBlockStates);
        $fixedFlatBlockPalette ??= FixedFlatBlockPalette::fromRegistry($internalBlockStates);
        $itemCatalog ??= ItemCatalog::vanilla(
            $data->itemNetworkRegistry(),
            creative: $data->creativeInventoryRegistry(),
            blockItems: $data->blockItemMappingRegistry(),
        );
        $inventoryProjector ??= BedrockInventoryPacketProjector::fromData(
            $data,
            $blockNetworkTranslator,
            $itemCatalog,
        );
        $craftingCatalog ??= CraftingCatalog::fromData(
            $data,
            $itemCatalog,
            $internalBlockStates,
            $inventoryProjector,
        );
        $blockProperties = [];
        foreach ($data->dataDrivenBlockProperties() as $identifier => $littleEndianNbt) {
            $blockProperties[] = BlockPropertyData::fromLittleEndianNbt($identifier, $littleEndianNbt);
        }
        $biomeDefinitions = $data->biomeDefinitions();

        return new self(
            $biomeDefinitions,
            $internalBlockStates,
            $blockNetworkTranslator,
            $fixedFlatBlockPalette,
            $itemCatalog,
            $inventoryProjector,
            $craftingCatalog,
            $blockProperties,
            new ReusablePlayPacket(
                'initialization.item_registry',
                ItemRegistryPacket::fromRequiredItems($data->requiredItems()),
            ),
            new ReusablePlayPacket(
                'initialization.biome_definitions',
                BiomeDefinitionListPacket::fromDefinitions($biomeDefinitions),
            ),
            new ReusablePlayPacket(
                'initialization.actor_identifiers',
                new AvailableActorIdentifiersPacket($data->entityIdentifiersNetworkNbt()),
            ),
            new ReusablePlayPacket('initialization.trim_data', new TrimDataPacket()),
        );
    }

    public function craftingDataPacket(): ReusablePlayPacket
    {
        return new ReusablePlayPacket(
            'initialization.crafting_data.' . $this->craftingCatalog->revision(),
            $this->craftingCatalog->protocolPacket(),
        );
    }

    /** Materializes immutable catalog projections before the first live admission tick. */
    public function warm(): void
    {
        $this->inventoryProjector->creativeContent();
        $this->craftingCatalog->protocolPacket();
    }
}
