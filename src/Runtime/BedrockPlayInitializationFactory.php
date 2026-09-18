<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\AvailableActorIdentifiersPacket;
use Bedriox\Protocol\Packet\BiomeDefinitionListPacket;
use Bedriox\Protocol\Packet\BlockPropertyData;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\CraftingDataPacket;
use Bedriox\Protocol\Packet\CreativeContentPacket;
use Bedriox\Protocol\Packet\GameRulesChangedPacket;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\ItemRegistryPacket;
use Bedriox\Protocol\Packet\JigsawStructureDataPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\PlayerListAddEntry;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\PlayerPositionProjection;
use Bedriox\Protocol\Packet\PlayerSkin;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetCommandsEnabledPacket;
use Bedriox\Protocol\Packet\SetDifficultyPacket;
use Bedriox\Protocol\Packet\SetSpawnPositionPacket;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\Packet\StartGamePacket;
use Bedriox\Protocol\Packet\TrimDataPacket;
use Bedriox\Protocol\Packet\UpdateAbilitiesPacket;
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\SpawnPosition;

/** Builds the complete bounded current-Bedrock fixed-flat initialization sequence. */
final readonly class BedrockPlayInitializationFactory implements PlayInitializationFactory
{
    /**
     * @var list<array{name: string, id: int, temperature: float, downfall: float, foliage_snow: float,
     *     depth: float, scale: float, map_water_argb: int, rain: bool, tags: list<string>}>
     */
    private array $biomeDefinitions;

    private BlockNetworkTranslator $blockNetworkTranslator;

    private FixedFlatBlockPalette $fixedFlatBlockPalette;

    private BedrockInventoryPacketProjector $inventoryProjector;

    /** @var list<BlockPropertyData> */
    private array $blockProperties;

    public function __construct(
        private BedrockDataSet $data,
        private RuntimeLimits $limits = new RuntimeLimits(),
        private string $levelName = 'Bedriox Flat World',
        private SpawnPosition $spawn = new SpawnPosition(0, 64, 0),
        private int $difficulty = 2,
        private int $worldSeed = 0,
    ) {
        if ($this->difficulty < 0 || $this->difficulty > 3) {
            throw new \InvalidArgumentException('Difficulty must be a Bedrock value between 0 and 3.');
        }
        $this->biomeDefinitions = $data->biomeDefinitions();
        $networkBlockStates = $data->blockStateRegistry();
        $internalBlockStates = new BlockStateRegistry($networkBlockStates->states());
        $this->blockNetworkTranslator = new BlockNetworkTranslator($internalBlockStates, $networkBlockStates);
        $this->fixedFlatBlockPalette = FixedFlatBlockPalette::fromRegistry($internalBlockStates);
        $this->inventoryProjector = BedrockInventoryPacketProjector::fromData($data, $this->blockNetworkTranslator);
        $blockProperties = [];
        foreach ($data->dataDrivenBlockProperties() as $identifier => $littleEndianNbt) {
            $blockProperties[] = BlockPropertyData::fromLittleEndianNbt($identifier, $littleEndianNbt);
        }
        $this->blockProperties = $blockProperties;
    }

    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId): array
    {
        $radius = $this->limits->preloadedChunkRadius;
        $starterInventory = PlayerInventory::starter($this->fixedFlatBlockPalette);
        $mainInventory = array_map(
            fn($stack) => $stack === null
                ? InventoryContentPacket::emptySlot()
                : $this->inventoryProjector->toProtocol($stack),
            $starterInventory->slots(),
        );
        $heldItem = $this->inventoryProjector->toProtocol($starterInventory->selectedStack());
        $packets = [
            new JigsawStructureDataPacket(),
            new VoxelShapesPacket(),
            StartGamePacket::fixedFlat(
                $runtimeEntityId->toSignedBits(),
                $runtimeEntityId,
                (float) $this->spawn->x,
                PlayerPositionProjection::feetToWireY((float) $this->spawn->y),
                (float) $this->spawn->z,
                'bedriox:flat',
                $this->levelName,
                gameVersion: ProtocolVersion::GAME_VERSION,
                blockProperties: $this->blockProperties,
                worldSeed: $this->worldSeed,
                worldSpawnX: $this->spawn->x,
                worldSpawnY: $this->spawn->y,
                worldSpawnZ: $this->spawn->z,
            ),
            ItemRegistryPacket::fromRequiredItems($this->data->requiredItems()),
            // Everything after this marker is emitted as one radius-negotiated bootstrap.
            new ChunkRadiusUpdatedPacket($radius),
            // The MVP world exposes players only. Advertise exactly the actor types that may spawn;
            // legacy vanilla definitions are added alongside their implementations in later milestones.
            BiomeDefinitionListPacket::fromDefinitions($this->biomeDefinitions),
            new AvailableActorIdentifiersPacket($this->data->entityIdentifiersNetworkNbt()),
            new SetSpawnPositionPacket(
                x: $this->spawn->x,
                y: $this->spawn->y,
                z: $this->spawn->z,
                worldX: $this->spawn->x,
                worldY: $this->spawn->y,
                worldZ: $this->spawn->z,
            ),
            new SetTimePacket(),
            new SetDifficultyPacket($this->difficulty),
            new SetCommandsEnabledPacket(false),
            UpdateAbilitiesPacket::survival($runtimeEntityId->toSignedBits()),
            new UpdateAdventureSettingsPacket(),
            GameRulesChangedPacket::survivalDefaults(),
            new PlayerListAddPacket([new PlayerListAddEntry(
                $login->identity,
                $runtimeEntityId->toSignedBits(),
                $login->displayName,
                $login->xuid,
                '',
                BuildPlatform::Unknown,
                PlayerSkin::fromVerifiedClientData($login->clientData),
                colorArgb: 0xffffffff,
            )]),
            UpdateAttributesPacket::survival($runtimeEntityId),
            new CreativeContentPacket(),
            new InventoryContentPacket(0, $mainInventory),
            new InventoryContentPacket(120, 4),
            new InventoryContentPacket(119, 1),
            new MobEquipmentPacket($runtimeEntityId, 0, 0, 0, $heldItem),
            new TrimDataPacket(),
            new CraftingDataPacket(),
            SetActorDataPacket::baselinePlayer($runtimeEntityId, UnsignedLong::fromInt(0), $login->displayName),
        ];

        return $packets;
    }

    public function fixedFlatRuntimeIds(): array
    {
        return $this->fixedFlatBlockPalette->toNetworkRuntimeIds($this->blockNetworkTranslator);
    }
}
