<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Encryption\BedrockDecryptor;
use Bedriox\Protocol\Encryption\BedrockEncryptor;
use Bedriox\Protocol\Identity\VerifiedClientData;
use Bedriox\Protocol\Packet\ActorEventPacket;
use Bedriox\Protocol\Packet\ActorFlag;
use Bedriox\Protocol\Packet\AddPlayerPacket;
use Bedriox\Protocol\Packet\AnimatePacket;
use Bedriox\Protocol\Packet\ChatPacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\ContainerType;
use Bedriox\Protocol\Packet\CorrectPlayerMovePredictionPacket;
use Bedriox\Protocol\Packet\EmoteFlag;
use Bedriox\Protocol\Packet\EmotePacket;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\InventoryContainerId;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\InventorySlotPacket;
use Bedriox\Protocol\Packet\ItemStackResponse;
use Bedriox\Protocol\Packet\ItemStackResponseContainer;
use Bedriox\Protocol\Packet\ItemStackResponsePacket;
use Bedriox\Protocol\Packet\ItemStackResponseSlot;
use Bedriox\Protocol\Packet\LevelEventPacket;
use Bedriox\Protocol\Packet\LevelSoundEventPacket;
use Bedriox\Protocol\Packet\MobArmorEquipmentPacket;
use Bedriox\Protocol\Packet\MobEquipmentPacket;
use Bedriox\Protocol\Packet\MoveActorAbsoluteFlag;
use Bedriox\Protocol\Packet\MoveActorAbsolutePacket;
use Bedriox\Protocol\Packet\MovePlayerMode;
use Bedriox\Protocol\Packet\MovePlayerPacket;
use Bedriox\Protocol\Packet\PlayerListAddPacket;
use Bedriox\Protocol\Packet\PlayerListRemovePacket;
use Bedriox\Protocol\Packet\PlayerSkinPacket;
use Bedriox\Protocol\Packet\PredictionType;
use Bedriox\Protocol\Packet\RemoveActorPacket;
use Bedriox\Protocol\Packet\SetActorDataPacket;
use Bedriox\Protocol\Packet\SetActorMotionPacket;
use Bedriox\Protocol\Packet\UpdateAttributesPacket;
use Bedriox\Protocol\Packet\UpdateBlockPacket;
use Bedriox\Protocol\Security\OpenSslEphemeralKeyFactory;
use Bedriox\Protocol\Value\BuildPlatform;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\SessionInfo;
use Bedriox\Server\Login\AuthenticatedLogin;
use Bedriox\Server\Login\LoginChannelReady;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockPlayChannel;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Runtime\DirectedPacket;
use Bedriox\Server\Runtime\RuntimeSession;
use Bedriox\Server\Simulation\ArmSwingSource;
use Bedriox\Server\Simulation\ClientInputTick;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CraftingTableOpened;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemConsumed;
use Bedriox\Server\Simulation\Event\ItemUseCancelled;
use Bedriox\Server\Simulation\Event\ItemUseStarted;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\ItemUseCancellationReason;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\NutritionChangeReason;
use Bedriox\Server\Simulation\PlayerSnapshot;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class BedrockWorldEventPacketEncoderTest extends TestCase
{
    public function testCraftingTableOpenUsesTheWorkbenchContainerConversation(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new CraftingTableOpened(
            'owner',
            new BlockPosition(4, 65, -3),
        ), []);

        self::assertCount(1, $packets);
        self::assertSame('owner', $packets[0]->sessionId);
        self::assertInstanceOf(ContainerOpenPacket::class, $packets[0]->packet);
        self::assertSame(1, $packets[0]->packet->containerId);
        self::assertSame(ContainerType::Workbench, $packets[0]->packet->containerType);
        self::assertSame([4, 65, -3], [
            $packets[0]->packet->position->x,
            $packets[0]->packet->position->y,
            $packets[0]->packet->position->z,
        ]);
    }

    public function testItemUseAndNutritionProjectCompleteClientFeedback(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $apple = new InventoryStack('minecraft:apple', 2, 7);
        $player = new PlayerSnapshot(
            'owner',
            'identity',
            'Player',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            MovementMode::STOPPED,
            12,
            VerticalState::GROUNDED,
            0.0,
            42,
            food: 14.0,
            saturation: 7.0,
            exhaustion: 1.0,
            selectedStack: $apple,
        );
        $started = $encoder->encode(new ItemUseStarted(
            'owner',
            42,
            0,
            $apple,
            10,
            32,
            ['owner', 'peer'],
            movementSequence: 12,
        ), []);
        self::assertCount(4, $started);
        self::assertInstanceOf(SetActorDataPacket::class, $started[0]->packet);
        self::assertIsInt($started[0]->packet->metadata[0]->value);
        self::assertNotSame(0, $started[0]->packet->metadata[0]->value & ActorFlag::UsingItem->mask());
        self::assertInstanceOf(ActorEventPacket::class, $started[1]->packet);

        $cancelled = $encoder->encode(new ItemUseCancelled(
            'owner',
            42,
            0,
            $apple,
            ItemUseCancellationReason::RELEASED,
            ['owner', 'peer'],
            movementSequence: 12,
        ), []);
        self::assertCount(2, $cancelled);
        $cancelledMetadata = $cancelled[0]->packet;
        self::assertInstanceOf(SetActorDataPacket::class, $cancelledMetadata);
        $cancelledFlags = $cancelledMetadata->metadata[0]->value;
        self::assertIsInt($cancelledFlags);
        self::assertSame(0, $cancelledFlags & ActorFlag::UsingItem->mask());

        $main = array_fill(0, 36, null);
        $main[0] = new InventoryStack('minecraft:apple', 1, 8);
        $consumed = $encoder->encode(new ItemConsumed(
            $player,
            $apple->withCountAndNetworkId(1, 7),
            0,
            $main[0],
            [new InventorySlotReference(InventoryContainer::Main, 0, 0)],
            $main,
            null,
            ['owner', 'peer'],
        ), []);
        self::assertCount(8, $consumed);
        self::assertCount(2, array_filter(
            $consumed,
            static fn(DirectedPacket $packet): bool => $packet->packet instanceof InventorySlotPacket,
        ));
        self::assertCount(0, array_filter(
            $consumed,
            static fn(DirectedPacket $packet): bool => $packet->packet instanceof InventoryContentPacket,
        ));

        $nutrition = $encoder->encode(new NutritionChanged(
            $player,
            10.0,
            5.0,
            0.0,
            NutritionChangeReason::CONSUMPTION,
            ['owner'],
        ), []);
        self::assertCount(1, $nutrition);
        self::assertInstanceOf(UpdateAttributesPacket::class, $nutrition[0]->packet);
        self::assertSame(
            ['minecraft:player.hunger', 'minecraft:player.saturation', 'minecraft:player.exhaustion'],
            array_map(static fn($attribute): string => $attribute->name, $nutrition[0]->packet->attributes),
        );
    }

    public function testAuthoritativeKnockbackProjectsMotionToEachVisibleRecipient(): void
    {
        $encoder = new BedrockWorldEventPacketEncoder();
        $player = new PlayerSnapshot(
            'target',
            'identity-target',
            'Target',
            new Position(0.0, 64.0, 2.0),
            0.0,
            0.0,
            MovementMode::WALKING,
            1,
            VerticalState::AIRBORNE,
            0.4,
            22,
            0.0,
            false,
            false,
            19.0,
            true,
        );

        $packets = $encoder->encode(new PlayerKnockedBack(
            'attacker',
            $player,
            0.0,
            0.4,
            0.4,
            new ClientInputTick(0x80000000, 42),
            ['target', 'viewer'],
        ), []);

        self::assertCount(2, $packets);
        self::assertSame(['target', 'viewer'], array_map(static fn($packet): string => $packet->sessionId, $packets));
        self::assertInstanceOf(SetActorMotionPacket::class, $packets[0]->packet);
        self::assertSame(22, $packets[0]->packet->runtimeEntityId->low);
        self::assertSame(0.4, $packets[0]->packet->motionY);
        self::assertSame(0.4, $packets[0]->packet->motionZ);
        self::assertSame(0x80000000, $packets[0]->packet->tick->high);
        self::assertSame(42, $packets[0]->packet->tick->low);
        self::assertEquals($packets[0]->packet, $packets[1]->packet);
        self::assertSame('1600000000cdcccc3ecdcccc3eaa808080808080808001', bin2hex($packets[0]->packet->encode()));
    }

    public function testAuthoritativeBlockEventsProjectOrderedCrackAndUpdatePackets(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $encoder = new BedrockWorldEventPacketEncoder(new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        ));
        $position = new BlockPosition(1, 63, -2);

        $started = $encoder->encode(new BlockBreakStarted('one', $position, 3640, ['one', 'two']), []);
        self::assertCount(2, $started);
        self::assertSame(['one', 'two'], array_map(static fn($packet): string => $packet->sessionId, $started));
        self::assertInstanceOf(LevelEventPacket::class, $started[0]->packet);
        self::assertSame(3600, $started[0]->packet->eventId);
        self::assertSame(3640, $started[0]->packet->data);

        $changed = $encoder->encode(new BlockChanged('one', $position, $palette->air, ['one', 'two'], true), []);
        self::assertCount(4, $changed);
        self::assertInstanceOf(LevelEventPacket::class, $changed[0]->packet);
        self::assertSame(3601, $changed[0]->packet->eventId);
        self::assertInstanceOf(UpdateBlockPacket::class, $changed[1]->packet);
        self::assertSame($data->fixedFlatRuntimeIds()['air'], $changed[1]->packet->blockRuntimeId);
        self::assertSame(['one', 'one', 'two', 'two'], array_map(static fn($packet): string => $packet->sessionId, $changed));
    }

    public function testBlockDestroyParticleUsesPreviousBlockBeforeAirUpdate(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $encoder = new BedrockWorldEventPacketEncoder(new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        ));
        $stone = $internal->internalId(\Bedriox\Server\World\Block\VanillaBlockStates::stone());
        $packets = $encoder->encode(new BlockChanged(
            'one',
            new BlockPosition(1, 63, 1),
            $palette->air,
            ['one'],
            true,
            $stone,
        ), []);

        self::assertCount(3, $packets);
        self::assertInstanceOf(LevelEventPacket::class, $packets[1]->packet);
        self::assertSame(2001, $packets[1]->packet->eventId);
        self::assertSame($data->blockStateRegistry()->networkRuntimeId(
            $internal->state($stone),
        ), $packets[1]->packet->data);
        self::assertInstanceOf(UpdateBlockPacket::class, $packets[2]->packet);
    }

    public function testBlockPunchUsesTargetStateAndFace(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $encoder = new BedrockWorldEventPacketEncoder(new BedrockChunkPacketSerializer(
            new BlockNetworkTranslator($internal, $data->blockStateRegistry()),
        ));
        $packets = $encoder->encode(new \Bedriox\Server\Simulation\Event\BlockPunch(
            'one',
            new BlockPosition(1, 63, 1),
            $palette->grassBlock,
            1,
            ['one'],
        ), []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(LevelEventPacket::class, $packets[0]->packet);
        self::assertSame(3604, $packets[0]->packet->eventId);
        self::assertSame($data->fixedFlatRuntimeIds()['grass_block'], $packets[0]->packet->data);
    }

    public function testPlacementProjectsWorldAndInventoryAuthorityInDeterministicOrder(): void
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $translator = new BlockNetworkTranslator($internal, $data->blockStateRegistry());
        $encoder = new BedrockWorldEventPacketEncoder(
            new BedrockChunkPacketSerializer($translator),
            BedrockInventoryPacketProjector::fromData($data, $translator),
        );
        $remaining = new InventoryStack('minecraft:grass_block', 63, 1, $palette->grassBlock);

        $placed = $encoder->encode(new BlockPlaced(
            'one',
            41,
            new BlockPosition(1, 64, 0),
            $palette->grassBlock,
            0,
            $remaining,
            ['one', 'two'],
        ), []);
        self::assertCount(6, $placed);
        self::assertSame(['one', 'one', 'two', 'two', 'one', 'two'], array_map(static fn($packet): string => $packet->sessionId, $placed));
        self::assertInstanceOf(UpdateBlockPacket::class, $placed[0]->packet);
        self::assertInstanceOf(LevelSoundEventPacket::class, $placed[1]->packet);
        self::assertInstanceOf(UpdateBlockPacket::class, $placed[2]->packet);
        self::assertInstanceOf(LevelSoundEventPacket::class, $placed[3]->packet);
        self::assertInstanceOf(InventorySlotPacket::class, $placed[4]->packet);
        self::assertSame($placed[0]->packet->encode(), $placed[2]->packet->encode());
        self::assertSame([1, 64, 0], [
            $placed[0]->packet->position->x,
            $placed[0]->packet->position->y,
            $placed[0]->packet->position->z,
        ]);
        self::assertSame($data->fixedFlatRuntimeIds()['grass_block'], $placed[0]->packet->blockRuntimeId);
        self::assertSame(63, $placed[4]->packet->item->count);
        self::assertSame(1, $placed[4]->packet->item->stackNetworkId);
        self::assertInstanceOf(MobEquipmentPacket::class, $placed[5]->packet);
        self::assertSame(63, $placed[5]->packet->item->count);

        $corrected = $encoder->encode(new BlockPlacementCorrected(
            'one',
            new BlockPosition(1, 63, 0),
            $palette->grassBlock,
            new BlockPosition(1, 64, 0),
            $palette->air,
            0,
            new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock),
        ), []);
        self::assertCount(3, $corrected);
        self::assertInstanceOf(UpdateBlockPacket::class, $corrected[0]->packet);
        self::assertInstanceOf(UpdateBlockPacket::class, $corrected[1]->packet);
        self::assertInstanceOf(InventorySlotPacket::class, $corrected[2]->packet);
        self::assertNotSame($corrected[0]->packet->encode(), $corrected[1]->packet->encode());
        self::assertSame(64, $corrected[2]->packet->item->count);

        $equipment = $encoder->encode(new HeldItemChanged('one', 41, 0, $remaining, ['two']), []);
        self::assertCount(1, $equipment);
        self::assertSame('two', $equipment[0]->sessionId);
        self::assertInstanceOf(MobEquipmentPacket::class, $equipment[0]->packet);
        self::assertSame(63, $equipment[0]->packet->item->count);

        $damagedTool = new InventoryStack('minecraft:iron_pickaxe', 1, 9, damage: 7);
        $correctedTool = $encoder->encode(new HeldItemChanged(
            'one',
            41,
            0,
            $damagedTool,
            ['two'],
            ownerSlotCorrection: true,
        ), []);
        self::assertCount(3, $correctedTool);
        self::assertSame(['one', 'one', 'two'], array_map(
            static fn($packet): string => $packet->sessionId,
            $correctedTool,
        ));
        self::assertInstanceOf(InventorySlotPacket::class, $correctedTool[0]->packet);
        self::assertSame(0, $correctedTool[0]->packet->item->count);
        self::assertInstanceOf(InventorySlotPacket::class, $correctedTool[1]->packet);
        self::assertSame(9, $correctedTool[1]->packet->item->stackNetworkId);
        self::assertInstanceOf(MobEquipmentPacket::class, $correctedTool[2]->packet);
    }

    public function testInventoryRequestProjectsSuccessAndPeerEquipmentFromAuthoritativeState(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $main = array_fill(0, 36, null);
        $main[0] = new InventoryStack('minecraft:grass_block', 32, 2, $palette->grassBlock);
        $cursor = new InventoryStack('minecraft:grass_block', 32, 3, $palette->grassBlock);
        $event = new InventoryStackRequestProcessed(
            'one',
            -5,
            true,
            [
                new InventorySlotReference(
                    InventoryContainer::Main,
                    0,
                    1,
                    FullContainerName::COMBINED_HOTBAR_AND_INVENTORY,
                ),
                new InventorySlotReference(InventoryContainer::Cursor, 0, 0, FullContainerName::CURSOR),
            ],
            $main,
            $cursor,
            0,
            $main[0],
            true,
            41,
            ['two'],
        );

        $packets = $encoder->encode($event, []);
        self::assertCount(2, $packets);
        self::assertSame(['one', 'two'], array_map(static fn($packet): string => $packet->sessionId, $packets));
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        self::assertSame((new ItemStackResponsePacket([new ItemStackResponse(
            ItemStackResponse::STATUS_SUCCESS,
            -5,
            [
                new ItemStackResponseContainer(
                    new FullContainerName(FullContainerName::COMBINED_HOTBAR_AND_INVENTORY),
                    [new ItemStackResponseSlot(0, 0, 32, 2, filteredCustomName: '')],
                ),
                new ItemStackResponseContainer(
                    new FullContainerName(FullContainerName::CURSOR),
                    [new ItemStackResponseSlot(0, 0, 32, 3, filteredCustomName: '')],
                ),
            ],
        )]))->encode(), $packets[0]->packet->encode());
        self::assertInstanceOf(MobEquipmentPacket::class, $packets[1]->packet);
        self::assertSame(32, $packets[1]->packet->item->count);
        self::assertNull($packets[1]->packet->item->stackNetworkId);
    }

    public function testMineBlockResponseCarriesAuthoritativeDurabilityWithoutFullInventoryCorrection(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $main = array_fill(0, 36, null);
        $main[0] = new InventoryStack('minecraft:iron_pickaxe', 1, 7, damage: 12);
        $event = new InventoryStackRequestProcessed(
            'one',
            -13,
            true,
            [new InventorySlotReference(
                InventoryContainer::Main,
                0,
                0,
                FullContainerName::HOTBAR,
            )],
            $main,
            null,
            0,
            $main[0],
            false,
            41,
            [],
        );

        $packets = $encoder->encode($event, []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        $slot = $packets[0]->packet->responses[0]->containers[0]->slots[0];
        self::assertSame(12, $slot->durabilityCorrection);
        self::assertSame(7, $slot->stackNetworkId);
    }

    public function testCraftingResponsePreservesTheClientGridSlotAndAuthoritativeStack(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $crafting = array_fill(0, 9, null);
        $crafting[4] = new InventoryStack('minecraft:oak_planks', 2, 19);
        $event = new InventoryStackRequestProcessed(
            'one',
            -20,
            true,
            [new InventorySlotReference(
                InventoryContainer::CraftingInput,
                4,
                18,
                FullContainerName::CRAFTING_INPUT,
                responseSlot: 36,
            )],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            craftingInventory: $crafting,
        );

        $packets = $encoder->encode($event, []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        $container = $packets[0]->packet->responses[0]->containers[0];
        self::assertSame(FullContainerName::CRAFTING_INPUT, $container->containerName->containerNameId);
        self::assertSame(36, $container->slots[0]->slot);
        self::assertSame(2, $container->slots[0]->amount);
        self::assertSame(19, $container->slots[0]->stackNetworkId);
    }

    public function testRejectedTableCraftCorrectsAllNineGridSlots(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $crafting = array_fill(0, 9, null);
        $crafting[0] = new InventoryStack('minecraft:oak_planks', 1, 23);
        $event = new InventoryStackRequestProcessed(
            'one',
            -21,
            false,
            [],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            'recipe_mismatch',
            craftingInventory: $crafting,
        );

        $packets = $encoder->encode($event, []);
        $grid = [];
        foreach ($packets as $directed) {
            $packet = $directed->packet;
            if (!$packet instanceof InventorySlotPacket
                || $packet->containerId !== InventoryContainerId::UI
                || $packet->slot < 32
                || $packet->slot > 40) {
                continue;
            }
            $grid[] = $packet;
        }

        self::assertCount(10, $grid);
        self::assertSame([32, 32, 33, 34, 35, 36, 37, 38, 39, 40], array_map(
            static fn(InventorySlotPacket $packet): int => $packet->slot,
            $grid,
        ));
        self::assertSame(0, $grid[0]->item->count);
        self::assertSame(1, $grid[1]->item->count);
        self::assertSame(23, $grid[1]->item->stackNetworkId);
    }

    public function testRejectedInventoryRequestReturnsErrorAndFullMainAndCursorCorrection(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $main = array_fill(0, 36, null);
        $main[0] = new InventoryStack('minecraft:grass_block', 64, 1, $palette->grassBlock);
        $event = new InventoryStackRequestProcessed(
            'one',
            -7,
            false,
            [],
            $main,
            null,
            0,
            $main[0],
            false,
            41,
            ['two'],
            'stack_network_id',
        );

        $packets = $encoder->encode($event, []);
        self::assertCount(8, $packets);
        self::assertSame(array_fill(0, 8, 'one'), array_map(static fn($packet): string => $packet->sessionId, $packets));
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        self::assertInstanceOf(InventoryContentPacket::class, $packets[1]->packet);
        self::assertCount(36, $packets[1]->packet->items);
        self::assertSame(0, $packets[1]->packet->items[0]->count);
        self::assertInstanceOf(InventoryContentPacket::class, $packets[2]->packet);
        self::assertSame(64, $packets[2]->packet->items[0]->count);
        self::assertInstanceOf(InventorySlotPacket::class, $packets[3]->packet);
        self::assertSame(InventoryContainerId::UI, $packets[3]->packet->containerId);
        self::assertSame(0, $packets[3]->packet->slot);
        self::assertSame(0, $packets[3]->packet->item->count);
        foreach ([[4, InventoryContainerId::ARMOR, 4], [5, InventoryContainerId::ARMOR, 4],
            [6, InventoryContainerId::OFFHAND, 1], [7, InventoryContainerId::OFFHAND, 1]] as [$index, $container, $count]) {
            self::assertInstanceOf(InventoryContentPacket::class, $packets[$index]->packet);
            self::assertSame($container, $packets[$index]->packet->windowId);
            self::assertCount($count, $packets[$index]->packet->items);
        }
    }

    public function testLegacyInventorySuccessSynchronizesChangedSlotsWithoutAStackResponse(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $main = array_fill(0, 36, null);
        $main[0] = new InventoryStack('minecraft:grass_block', 32, 2, $palette->grassBlock);
        $main[1] = new InventoryStack('minecraft:grass_block', 32, 3, $palette->grassBlock);
        $event = new InventoryStackRequestProcessed(
            'one',
            0,
            true,
            [
                new InventorySlotReference(InventoryContainer::Main, 0, 1),
                new InventorySlotReference(InventoryContainer::Main, 1, 0),
            ],
            $main,
            null,
            0,
            $main[0],
            true,
            41,
            ['two'],
            responseMode: InventoryResponseMode::LegacySlotSync,
        );

        $packets = $encoder->encode($event, []);
        self::assertCount(5, $packets);
        self::assertInstanceOf(InventorySlotPacket::class, $packets[0]->packet);
        self::assertInstanceOf(InventorySlotPacket::class, $packets[1]->packet);
        self::assertInstanceOf(InventorySlotPacket::class, $packets[2]->packet);
        self::assertInstanceOf(InventorySlotPacket::class, $packets[3]->packet);
        self::assertSame([0, 32, 0, 32], [
            $packets[0]->packet->item->count,
            $packets[1]->packet->item->count,
            $packets[2]->packet->item->count,
            $packets[3]->packet->item->count,
        ]);
        self::assertInstanceOf(MobEquipmentPacket::class, $packets[4]->packet);
        self::assertSame('two', $packets[4]->sessionId);
    }

    public function testClosingAnEmptyCraftingGridDoesNotRefreshTheWholeInventory(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $affected = [];
        for ($slot = 0; $slot < 4; ++$slot) {
            $affected[] = new InventorySlotReference(
                InventoryContainer::CraftingInput,
                $slot,
                0,
                responseSlot: 28 + $slot,
            );
        }
        $event = new InventoryStackRequestProcessed(
            'one',
            0,
            true,
            $affected,
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            responseMode: InventoryResponseMode::LegacySlotSync,
            craftingInventory: array_fill(0, 4, null),
        );

        $packets = $encoder->encode($event, []);

        self::assertCount(4, $packets);
        foreach ($packets as $index => $packet) {
            self::assertInstanceOf(InventorySlotPacket::class, $packet->packet);
            self::assertSame(InventoryContainerId::UI, $packet->packet->containerId);
            self::assertSame(28 + $index, $packet->packet->slot);
            self::assertSame(0, $packet->packet->item->count);
        }
    }

    public function testEquipmentResponsePreservesClientSlotWhileProjectingAuthoritativePeers(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $helmet = new InventoryStack('minecraft:iron_helmet', 1, 4);
        $offhand = new InventoryStack('minecraft:grass_block', 1, 5, $palette->grassBlock);
        $event = new InventoryStackRequestProcessed(
            'one',
            -11,
            true,
            [
                new InventorySlotReference(
                    InventoryContainer::Armor,
                    0,
                    0,
                    FullContainerName::ARMOR,
                    responseSlot: 0,
                ),
                new InventorySlotReference(
                    InventoryContainer::Offhand,
                    0,
                    0,
                    FullContainerName::OFFHAND,
                    responseSlot: 40,
                ),
            ],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            ['two'],
            armorInventory: [$helmet, null, null, null],
            offhandStack: $offhand,
        );

        $packets = $encoder->encode($event, []);

        self::assertCount(3, $packets);
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        $containers = $packets[0]->packet->responses[0]->containers;
        self::assertSame(FullContainerName::ARMOR, $containers[0]->containerName->containerNameId);
        self::assertSame(0, $containers[0]->slots[0]->slot);
        self::assertSame(FullContainerName::OFFHAND, $containers[1]->containerName->containerNameId);
        self::assertSame(40, $containers[1]->slots[0]->slot);
        self::assertInstanceOf(MobArmorEquipmentPacket::class, $packets[1]->packet);
        self::assertNull($packets[1]->packet->helmet->stackNetworkId);
        self::assertInstanceOf(MobEquipmentPacket::class, $packets[2]->packet);
        self::assertSame(InventoryContainerId::OFFHAND, $packets[2]->packet->windowId);
        self::assertNull($packets[2]->packet->item->stackNetworkId);
    }

    public function testLegacyOffhandCorrectionUsesClearThenRealFullContent(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $offhand = new InventoryStack('minecraft:grass_block', 1, 5, $palette->grassBlock);
        $event = new InventoryStackRequestProcessed(
            'one',
            0,
            true,
            [new InventorySlotReference(InventoryContainer::Offhand, 0, 0)],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            responseMode: InventoryResponseMode::LegacySlotSync,
            offhandStack: $offhand,
        );

        $packets = $encoder->encode($event, []);

        self::assertCount(2, $packets);
        foreach ($packets as $packet) {
            self::assertInstanceOf(InventoryContentPacket::class, $packet->packet);
            self::assertSame(InventoryContainerId::OFFHAND, $packet->packet->windowId);
            self::assertCount(1, $packet->packet->items);
        }
        $cleared = $packets[0]->packet;
        $restored = $packets[1]->packet;
        self::assertInstanceOf(InventoryContentPacket::class, $cleared);
        self::assertInstanceOf(InventoryContentPacket::class, $restored);
        self::assertSame(0, $cleared->items[0]->count);
        self::assertSame(1, $restored->items[0]->count);
        self::assertSame(5, $restored->items[0]->stackNetworkId);
    }

    public function testChatUsesAuthoritativeSimulationAttribution(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new ChatBroadcast(
            'sender-session',
            'sender-identity',
            'AuthoritativeName',
            1,
            'hello',
            ['one', 'two'],
        ), []);
        self::assertCount(2, $packets);
        self::assertSame(['one', 'two'], array_map(static fn($value): string => $value->sessionId, $packets));
        foreach ($packets as $directed) {
            self::assertInstanceOf(ChatPacket::class, $directed->packet);
            self::assertSame('AuthoritativeName', $directed->packet->sourceName);
            self::assertSame('', $directed->packet->xuid);
        }
    }

    public function testChatWirePayloadUsesOnlyAuthoritativeAttribution(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new ChatBroadcast(
            'sender-session',
            'sender-identity',
            'AuthoritativeName',
            1,
            'hello',
            ['recipient'],
        ), []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(ChatPacket::class, $packets[0]->packet);
        // Untranslated AuthorAndMessage/CHAT, authoritative name/message, empty identity fields, no filtered text.
        $expectedPayload = hex2bin('00010111417574686f72697461746976654e616d650568656c6c6f000000');
        self::assertIsString($expectedPayload);
        self::assertSame($expectedPayload, $packets[0]->packet->encode());
    }

    public function testDisconnectRemovesOnlyTheAuthenticatedIdentity(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new PlayerDisconnected(
            'gone',
            '00000000-0000-0000-0000-000000000001',
            7,
            ['remaining'],
        ), []);
        self::assertCount(1, $packets);
        self::assertSame('remaining', $packets[0]->sessionId);
        self::assertInstanceOf(PlayerListRemovePacket::class, $packets[0]->packet);
        self::assertSame(['00000000-0000-0000-0000-000000000001'], $packets[0]->packet->uuids);
    }

    public function testJoinProjectsPlayerListMembershipInBothDirections(): void
    {
        $joined = $this->authenticatedSession(
            'joined',
            7,
            '00000000-0000-0000-0000-000000000007',
            'Joined',
            'joined-xuid',
        );
        $existing = $this->authenticatedSession(
            'existing',
            9,
            '00000000-0000-0000-0000-000000000009',
            'Existing',
            'existing-xuid',
        );
        $joinedPlayer = $this->player('joined', 7, '00000000-0000-0000-0000-000000000007', 'Joined', true);
        $existingPlayer = $this->player('existing', 9, '00000000-0000-0000-0000-000000000009', 'Existing');

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new PlayerJoined($joinedPlayer, [$existingPlayer], ['joined', 'existing']),
            ['joined' => $joined, 'existing' => $existing],
        );

        self::assertCount(2, $packets);
        self::assertSame(
            ['existing', 'joined'],
            array_map(static fn($value): string => $value->sessionId, $packets),
        );
        self::assertInstanceOf(PlayerListAddPacket::class, $packets[0]->packet);
        self::assertSame(7, $packets[0]->packet->entries[0]->uniqueEntityId);
        self::assertSame(BuildPlatform::Unknown, $packets[0]->packet->entries[0]->buildPlatform);
        self::assertInstanceOf(PlayerListAddPacket::class, $packets[1]->packet);
        self::assertSame(9, $packets[1]->packet->entries[0]->uniqueEntityId);
        self::assertSame(BuildPlatform::Unknown, $packets[1]->packet->entries[0]->buildPlatform);
    }

    public function testActorVisibilityTransitionsDoNotDuplicatePlayerListMembership(): void
    {
        [$encoder, $palette] = $this->inventoryEncoder();
        $player = $this->player(
            'joined',
            7,
            '00000000-0000-0000-0000-000000000007',
            'Joined',
            true,
            [new InventoryStack('minecraft:iron_helmet', 1, 2)],
            new InventoryStack('minecraft:grass_block', 1, 3, $palette->grassBlock),
            new InventoryStack('minecraft:grass_block', 2, 1, $palette->grassBlock),
        );
        $joined = $this->authenticatedSession(
            'joined',
            7,
            '00000000-0000-0000-0000-000000000007',
            'Joined',
            'joined-xuid',
        );

        $shown = $encoder->encode(new PlayerBecameVisible($player, 'viewer'), ['joined' => $joined]);
        self::assertCount(5, $shown);
        self::assertInstanceOf(AddPlayerPacket::class, $shown[0]->packet);
        self::assertTrue($shown[0]->packet->runtimeEntityId->equals(UnsignedLong::fromInt(7)));
        self::assertSame(BuildPlatform::Unknown, $shown[0]->packet->buildPlatform);
        self::assertCount(14, $shown[0]->packet->metadata);
        $baselineFlags = $shown[0]->packet->metadata[0]->value;
        self::assertIsInt($baselineFlags);
        self::assertSame(
            ActorFlag::Sneaking->mask(),
            $baselineFlags & ActorFlag::Sneaking->mask(),
        );
        self::assertSame(130, $shown[0]->packet->metadata[13]->id);
        self::assertInstanceOf(PlayerSkinPacket::class, $shown[1]->packet);
        self::assertSame('00000000-0000-0000-0000-000000000007', $shown[1]->packet->uuid);
        self::assertSame('skin-joined', $shown[1]->packet->newSkinName);
        self::assertInstanceOf(MobEquipmentPacket::class, $shown[2]->packet);
        self::assertSame(InventoryContainerId::INVENTORY, $shown[2]->packet->windowId);
        self::assertSame(2, $shown[2]->packet->item->count);
        self::assertNull($shown[2]->packet->item->stackNetworkId);
        self::assertInstanceOf(MobArmorEquipmentPacket::class, $shown[3]->packet);
        self::assertNotSame(0, $shown[3]->packet->helmet->runtimeId);
        self::assertNull($shown[3]->packet->helmet->stackNetworkId);
        self::assertSame(0, $shown[3]->packet->body->runtimeId);
        self::assertInstanceOf(MobEquipmentPacket::class, $shown[4]->packet);
        self::assertSame(InventoryContainerId::OFFHAND, $shown[4]->packet->windowId);
        self::assertSame(1, $shown[4]->packet->item->count);
        self::assertNull($shown[4]->packet->item->stackNetworkId);

        $hidden = $encoder->encode(new PlayerBecameHidden('joined', 7, 'viewer'), []);
        self::assertCount(1, $hidden);
        self::assertInstanceOf(RemoveActorPacket::class, $hidden[0]->packet);
        self::assertSame(7, $hidden[0]->packet->actorUniqueId);
    }

    public function testEmoteRelayReconstructsAuthoritativeActorAndStripsSpoofableFields(): void
    {
        $sender = new RuntimeSession(
            new SessionInfo('127.0.0.1', 20_001, 1, 1_400, 11),
            'sender',
            UnsignedLong::fromInt(7),
            null,
        );
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new EmotePerformed(
            'sender',
            '00112233-4455-6677-8899-aabbccddeeff',
            ['peer', 'sender'],
        ), ['sender' => $sender]);

        self::assertCount(1, $packets);
        self::assertSame('peer', $packets[0]->sessionId);
        self::assertInstanceOf(EmotePacket::class, $packets[0]->packet);
        self::assertTrue($packets[0]->packet->runtimeEntityId->equals(UnsignedLong::fromInt(7)));
        self::assertSame(0, $packets[0]->packet->emoteLengthTicks);
        self::assertSame('', $packets[0]->packet->xuid);
        self::assertSame('', $packets[0]->packet->platformId);
        self::assertSame([EmoteFlag::ServerSide, EmoteFlag::MuteEmoteChat], $packets[0]->packet->flags);
    }

    public function testArmSwingIsProjectedOnlyToPeersWithItsAuthoritativeActor(): void
    {
        $packets = (new BedrockWorldEventPacketEncoder())->encode(new ArmSwung(
            'sender',
            7,
            ArmSwingSource::Mining,
            ['peer', 'sender'],
        ), []);

        self::assertCount(1, $packets);
        self::assertSame('peer', $packets[0]->sessionId);
        self::assertInstanceOf(AnimatePacket::class, $packets[0]->packet);
        self::assertSame(AnimatePacket::SWING, $packets[0]->packet->action);
        self::assertTrue($packets[0]->packet->runtimeEntityId->equals(UnsignedLong::fromInt(7)));
        self::assertNull($packets[0]->packet->swingSource);
    }

    public function testMovementProjectsAuthoritativeFeetToWireEyePosition(): void
    {
        $player = new PlayerSnapshot(
            'session',
            '00000000-0000-0000-0000-000000000001',
            'Player',
            new Position(1.0, 64.0, 2.0),
            90.0,
            10.0,
            MovementMode::WALKING,
            5,
            VerticalState::AIRBORNE,
            -0.08,
            17,
            45.0,
        );
        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new PlayerMoved($player, ['peer']),
            [],
        );
        self::assertCount(1, $packets);
        self::assertSame('peer', $packets[0]->sessionId);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[0]->packet);
        self::assertTrue($packets[0]->packet->runtimeEntityId->equals(UnsignedLong::fromInt(17)));
        self::assertSame(45.0, $packets[0]->packet->headYaw);
        self::assertEqualsWithDelta(65.621, $packets[0]->packet->y, 0.000_001);
        self::assertFalse($packets[0]->packet->hasFlag(MoveActorAbsoluteFlag::OnGround));
    }

    public function testPostureChangeFollowsEachPeerMovementWithCompleteActorFlags(): void
    {
        $player = new PlayerSnapshot(
            'session',
            '00000000-0000-0000-0000-000000000001',
            'Player',
            new Position(1.0, 64.0, 2.0),
            90.0,
            10.0,
            MovementMode::CROUCHING,
            8,
            VerticalState::GROUNDED,
            0.0,
            17,
            45.0,
            true,
            false,
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new PlayerMoved($player, ['one', 'two'], true),
            [],
        );

        self::assertCount(4, $packets);
        self::assertSame(['one', 'one', 'two', 'two'], array_map(static fn($value): string => $value->sessionId, $packets));
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[0]->packet);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[1]->packet);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[2]->packet);
        self::assertInstanceOf(SetActorDataPacket::class, $packets[3]->packet);
        self::assertTrue($packets[1]->packet->tick->equals(UnsignedLong::fromInt(8)));
        $postureFlags = $packets[1]->packet->metadata[0]->value;
        self::assertIsInt($postureFlags);
        self::assertSame(
            ActorFlag::Sneaking->mask(),
            $postureFlags & ActorFlag::Sneaking->mask(),
        );
        self::assertSame(0, $postureFlags & ActorFlag::Sprinting->mask());
    }

    public function testCorrectionTargetsOnlySenderWithExactClientInputTick(): void
    {
        $session = new RuntimeSession(
            new SessionInfo('127.0.0.1', 20_001, 1, 1_400, 11),
            'session',
            UnsignedLong::fromInt(7),
            null,
        );
        $player = new PlayerSnapshot(
            'session',
            '00000000-0000-0000-0000-000000000001',
            'Player',
            new Position(1.0, 64.0, 2.0),
            0.0,
            0.0,
            MovementMode::STOPPED,
            6,
            VerticalState::GROUNDED,
            0.0,
            7,
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new MovementCorrected(
                $player,
                'movement_rate',
                clientTick: new ClientInputTick(0x80000000, 5),
            ),
            ['session' => $session],
        );
        self::assertCount(1, $packets);
        self::assertSame('session', $packets[0]->sessionId);
        self::assertInstanceOf(CorrectPlayerMovePredictionPacket::class, $packets[0]->packet);
        self::assertSame(PredictionType::Player, $packets[0]->packet->predictionType);
        self::assertSame(0x80000000, $packets[0]->packet->tick->high);
        self::assertSame(5, $packets[0]->packet->tick->low);
        self::assertSame(0.0, $packets[0]->packet->deltaX);
        self::assertSame(0.0, $packets[0]->packet->deltaY);
        self::assertSame(0.0, $packets[0]->packet->deltaZ);
        self::assertTrue($packets[0]->packet->onGround);
    }

    public function testTerrainCorrectionResetsOwnerAndPublishesResolvedPositionToPeers(): void
    {
        $player = new PlayerSnapshot(
            'owner',
            '00000000-0000-0000-0000-000000000001',
            'Player',
            new Position(0.7, 64.0, 1.0),
            0.0,
            0.0,
            MovementMode::WALKING,
            9,
            VerticalState::GROUNDED,
            0.0,
            7,
        );

        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new MovementCorrected(
                $player,
                'terrain_collision',
                ['peer'],
                clientTick: ClientInputTick::fromInt(123),
            ),
            [],
        );

        self::assertCount(2, $packets);
        self::assertSame('owner', $packets[0]->sessionId);
        self::assertInstanceOf(CorrectPlayerMovePredictionPacket::class, $packets[0]->packet);
        self::assertTrue($packets[0]->packet->tick->equals(UnsignedLong::fromInt(123)));
        self::assertSame('peer', $packets[1]->sessionId);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[1]->packet);
        self::assertEqualsWithDelta(0.7, $packets[1]->packet->x, 0.000001);
        self::assertEqualsWithDelta(1.0, $packets[1]->packet->z, 0.000001);
    }

    public function testPluginTeleportUsesLifecycleMoveAndRejectedTeleportNeedsNoCorrection(): void
    {
        $player = $this->player(
            'owner',
            7,
            '00000000-0000-0000-0000-000000000001',
            'Player',
        );
        $encoder = new BedrockWorldEventPacketEncoder();

        $teleport = $encoder->encode(new MovementCorrected($player, 'plugin_teleport'), []);
        self::assertCount(1, $teleport);
        self::assertInstanceOf(MovePlayerPacket::class, $teleport[0]->packet);
        self::assertSame(MovePlayerMode::TELEPORT, $teleport[0]->packet->mode);

        self::assertSame([], $encoder->encode(
            new MovementCorrected($player, 'plugin_teleport_collision'),
            [],
        ));
    }

    /** @param list<InventoryStack|null> $armor */
    private function player(
        string $sessionId,
        int $runtimeActorId,
        string $identity,
        string $displayName,
        bool $sneaking = false,
        array $armor = [],
        ?InventoryStack $offhand = null,
        ?InventoryStack $selectedStack = null,
    ): PlayerSnapshot {
        return new PlayerSnapshot(
            $sessionId,
            $identity,
            $displayName,
            new Position(1.0, 64.0, 2.0),
            0.0,
            0.0,
            MovementMode::STOPPED,
            0,
            VerticalState::GROUNDED,
            0.0,
            $runtimeActorId,
            0.0,
            $sneaking,
            armor: $armor,
            offhand: $offhand,
            selectedStack: $selectedStack,
        );
    }

    public function testGameModeChangeUsesOwnerAndPeerPacketConversations(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $player = new PlayerSnapshot(
            'owner',
            'identity-owner',
            'Owner',
            new Position(1.0, 64.0, 2.0),
            30.0,
            10.0,
            MovementMode::STOPPED,
            7,
            VerticalState::AIRBORNE,
            0.0,
            41,
            30.0,
            gameMode: \Bedriox\Api\Player\GameMode::SPECTATOR,
        );

        $packets = $encoder->encode(new \Bedriox\Server\Simulation\Event\PlayerGameModeChanged(
            $player,
            \Bedriox\Api\Player\GameMode::SURVIVAL,
            \Bedriox\Api\Player\GameMode::SPECTATOR,
            99,
            ['owner', 'peer'],
        ), []);

        self::assertCount(3, $packets);
        self::assertSame(['owner', 'owner', 'peer'], array_map(static fn($packet): string => $packet->sessionId, $packets));
        self::assertInstanceOf(\Bedriox\Protocol\Packet\SetPlayerGameTypePacket::class, $packets[0]->packet);
        self::assertInstanceOf(MovePlayerPacket::class, $packets[1]->packet);
        self::assertInstanceOf(\Bedriox\Protocol\Packet\UpdatePlayerGameTypePacket::class, $packets[2]->packet);
    }

    public function testDroppedItemSpawnCarriesMetadataAndNetworkOffset(): void
    {
        [$encoder] = $this->inventoryEncoder();
        $entity = new \Bedriox\Server\Entity\Item\DroppedItemEntity(
            100,
            100,
            new InventoryStack('minecraft:diamond', 2, 1),
            new Position(2.0, 70.0, 3.0),
            new \Bedriox\Server\Entity\Item\ItemEntityMotion(0.1, 0.2, 0.3),
        );

        $packets = $encoder->encode(new \Bedriox\Server\Simulation\Event\ItemEntitySpawned($entity, ['one']), []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(\Bedriox\Protocol\Packet\AddItemActorPacket::class, $packets[0]->packet);
        self::assertSame(70.125, $packets[0]->packet->y);
        self::assertNotEmpty($packets[0]->packet->metadata);
        self::assertSame(
            $packets[0]->packet->encode(),
            \Bedriox\Protocol\Packet\AddItemActorPacket::decode($packets[0]->packet->encode())->encode(),
        );
    }

    public function testDroppedItemMovementProjectsAbsolutePositionAndCurrentMotion(): void
    {
        $entity = new \Bedriox\Server\Entity\Item\DroppedItemEntity(
            100,
            100,
            new InventoryStack('minecraft:diamond', 1, 1),
            new Position(2.0, 64.0, 3.0),
            new \Bedriox\Server\Entity\Item\ItemEntityMotion(0.0, 0.0, 0.0),
        );
        $packets = (new BedrockWorldEventPacketEncoder())->encode(
            new \Bedriox\Server\Simulation\Event\ItemEntityMoved($entity, 25_000, ['one', 'two']),
            [],
        );

        self::assertCount(4, $packets);
        self::assertSame(['one', 'one', 'two', 'two'], array_map(static fn($packet): string => $packet->sessionId, $packets));
        foreach ([0, 2] as $index) {
            self::assertInstanceOf(MoveActorAbsolutePacket::class, $packets[$index]->packet);
            self::assertSame(64.125, $packets[$index]->packet->y);
            self::assertTrue($packets[$index]->packet->onGround());
            self::assertInstanceOf(SetActorMotionPacket::class, $packets[$index + 1]->packet);
            self::assertSame(0.0, $packets[$index + 1]->packet->motionY);
            self::assertTrue($packets[$index + 1]->packet->tick->equals(UnsignedLong::fromInt(0)));
        }

        $positionOnly = (new BedrockWorldEventPacketEncoder())->encode(
            new \Bedriox\Server\Simulation\Event\ItemEntityMoved($entity, 25_001, ['one'], false),
            [],
        );
        self::assertCount(1, $positionOnly);
        self::assertInstanceOf(MoveActorAbsolutePacket::class, $positionOnly[0]->packet);
    }

    /** @return array{BedrockWorldEventPacketEncoder, FixedFlatBlockPalette} */
    private function inventoryEncoder(): array
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($internal);
        $translator = new BlockNetworkTranslator($internal, $data->blockStateRegistry());

        return [
            new BedrockWorldEventPacketEncoder(
                new BedrockChunkPacketSerializer($translator),
                BedrockInventoryPacketProjector::fromData($data, $translator),
            ),
            $palette,
        ];
    }

    private function authenticatedSession(
        string $sessionId,
        int $runtimeActorId,
        string $identity,
        string $displayName,
        string $xuid,
    ): RuntimeSession {
        $key = str_repeat("\x42", 32);
        $keys = (new OpenSslEphemeralKeyFactory(dirname(__DIR__) . '/Fixtures/openssl.cnf'))->generate();
        $clientData = new VerifiedClientData(
            64,
            32,
            str_repeat("\0", 64 * 32 * 4),
            0,
            0,
            '',
            '{}',
            [],
            skinId: 'skin-' . $sessionId,
            skinResourcePatchJson: '{"geometry":{"default":"geometry.humanoid.custom"}}',
        );
        $ready = new LoginChannelReady(
            new AuthenticatedLogin($displayName, $identity, $xuid, $keys->publicKey, $clientData),
            new BedrockEncryptor($key),
            new BedrockDecryptor($key),
            2193,
        );
        $actorId = UnsignedLong::fromInt($runtimeActorId);
        $session = new RuntimeSession(
            new SessionInfo('127.0.0.1', 20_000 + $runtimeActorId, 1, 1_400, 11),
            $sessionId,
            $actorId,
            null,
        );
        $session->play = new BedrockPlayChannel(
            $ready,
            $sessionId,
            $actorId,
            [],
            ['air' => 1, 'bedrock' => 2, 'dirt' => 3, 'grass_block' => 4],
        );

        return $session;
    }
}
