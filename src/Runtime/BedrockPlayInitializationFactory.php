<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Player\GameMode;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\AvailableActorIdentifiersPacket;
use Bedriox\Protocol\Packet\BiomeDefinitionListPacket;
use Bedriox\Protocol\Packet\BlockPropertyData;
use Bedriox\Protocol\Packet\ChunkRadiusUpdatedPacket;
use Bedriox\Protocol\Packet\CraftingDataPacket;
use Bedriox\Protocol\Packet\GameRulesChangedPacket;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\ItemRegistryPacket;
use Bedriox\Protocol\Packet\JigsawStructureDataPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\PlayerAttribute;
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
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\VoxelShapesPacket;
use Bedriox\Protocol\ProtocolVersion;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Provider\WorldData;
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
        private int $worldTime = 0,
        private string $generatorName = 'flat',
        private int $rewindHistorySize = 40,
        private string $defaultGamemode = 'survival',
    ) {
        if ($this->difficulty < 0 || $this->difficulty > 3) {
            throw new \InvalidArgumentException('Difficulty must be a Bedrock value between 0 and 3.');
        }
        if (!in_array($this->generatorName, ['default', 'flat'], true)) {
            throw new \InvalidArgumentException('Play initialization received an unsupported world generator.');
        }
        if ($this->rewindHistorySize < 1 || $this->rewindHistorySize > 1_200) {
            throw new \InvalidArgumentException('Movement rewind history size must be between 1 and 1200 ticks.');
        }
        if (GameMode::tryFrom($this->defaultGamemode) === null) {
            throw new \InvalidArgumentException('Play initialization received an unsupported default gamemode.');
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

    public static function forWorld(
        BedrockDataSet $data,
        RuntimeLimits $limits,
        WorldData $world,
        int $rewindHistorySize = 40,
        string $defaultGamemode = 'survival',
    ): self {
        return new self(
            $data,
            $limits,
            $world->metadata->name,
            $world->spawn,
            $world->difficulty,
            $world->metadata->seed,
            $world->time,
            $world->generatorName,
            $rewindHistorySize,
            $defaultGamemode,
        );
    }

    public function create(AuthenticatedLogin $login, UnsignedLong $runtimeEntityId, ?PlayerBootstrap $bootstrap = null): array
    {
        $radius = $this->limits->preloadedChunkRadius;
        $gameMode = GameMode::from($bootstrap === null ? $this->defaultGamemode : $bootstrap->gamemode);
        $gameModePackets = new GameModePacketProjector();
        $initialInventory = $bootstrap === null
            ? PlayerInventory::starter($this->fixedFlatBlockPalette)
            : PlayerInventory::restore($bootstrap->inventory, $this->fixedFlatBlockPalette);
        if ($bootstrap === null) {
            $positionX = (float) $this->spawn->x;
            $positionY = (float) $this->spawn->y;
            $positionZ = (float) $this->spawn->z;
            $playerPitch = 0.0;
            $playerYaw = 0.0;
        } else {
            $positionX = $bootstrap->position->x;
            $positionY = $bootstrap->position->y;
            $positionZ = $bootstrap->position->z;
            $playerPitch = $bootstrap->pitch;
            $playerYaw = $bootstrap->yaw;
        }
        $mainInventory = array_map(
            fn($stack) => $stack === null
                ? InventoryContentPacket::emptySlot()
                : $this->inventoryProjector->toProtocol($stack),
            $initialInventory->slots(),
        );
        $heldItem = $this->inventoryProjector->toProtocol($initialInventory->selectedStack());
        $packets = [
            new JigsawStructureDataPacket(),
            new VoxelShapesPacket(),
            StartGamePacket::fixedFlat(
                $runtimeEntityId->toSignedBits(),
                $runtimeEntityId,
                $positionX,
                PlayerPositionProjection::feetToWireY($positionY),
                $positionZ,
                'bedriox:' . $this->generatorName,
                $this->levelName,
                gameVersion: ProtocolVersion::GAME_VERSION,
                blockProperties: $this->blockProperties,
                worldSeed: $this->worldSeed,
                worldSpawnX: $this->spawn->x,
                worldSpawnY: $this->spawn->y,
                worldSpawnZ: $this->spawn->z,
                playerPitch: $playerPitch,
                playerYaw: $playerYaw,
                rewindHistorySize: $this->rewindHistorySize,
                playerGameType: $gameModePackets->gameType($gameMode),
                levelGameType: $gameModePackets->gameType(GameMode::from($this->defaultGamemode)),
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
            new SetTimePacket($this->worldTime),
            new SetDifficultyPacket($this->difficulty),
            new SetCommandsEnabledPacket(false),
            $gameModePackets->abilities($gameMode, $runtimeEntityId->toSignedBits()),
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
            $this->survivalAttributes($runtimeEntityId, $bootstrap === null ? 20.0 : $bootstrap->health),
            $this->inventoryProjector->creativeContent(),
            new InventoryContentPacket(0, $mainInventory),
            new InventoryContentPacket(120, 4),
            new InventoryContentPacket(119, 1),
            new MobEquipmentPacket(
                $runtimeEntityId,
                $initialInventory->selectedHotbarSlot(),
                $initialInventory->selectedHotbarSlot(),
                0,
                $heldItem,
            ),
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

    private function survivalAttributes(UnsignedLong $runtimeEntityId, float $health): UpdateAttributesPacket
    {
        $maximum = 3.4028234663852886e38;

        return new UpdateAttributesPacket($runtimeEntityId, [
            new PlayerAttribute('minecraft:health', 0.0, 20.0, $health, 0.0, 20.0, 20.0),
            new PlayerAttribute('minecraft:player.hunger', 0.0, 20.0, 20.0, 0.0, 20.0, 20.0),
            new PlayerAttribute('minecraft:movement', 0.0, $maximum, 0.1, 0.0, $maximum, 0.1),
            new PlayerAttribute('minecraft:player.level', 0.0, 24_791.0, 0.0, 0.0, 24_791.0, 0.0),
            new PlayerAttribute('minecraft:player.experience', 0.0, 1.0, 0.0, 0.0, 1.0, 0.0),
        ], UnsignedLong::fromInt(0));
    }
}
