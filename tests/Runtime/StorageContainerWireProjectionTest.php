<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Runtime;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType as ApiContainerType;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Protocol\Packet\BlockActorDataPacket;
use Bedriox\Protocol\Packet\BlockEventPacket;
use Bedriox\Protocol\Packet\ContainerClosePacket;
use Bedriox\Protocol\Packet\ContainerOpenPacket;
use Bedriox\Protocol\Packet\ContainerType;
use Bedriox\Protocol\Packet\FullContainerName;
use Bedriox\Protocol\Packet\InventoryContentPacket;
use Bedriox\Protocol\Packet\InventorySlotPacket;
use Bedriox\Protocol\Packet\ItemStackResponsePacket;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Runtime\BedrockChunkPacketSerializer;
use Bedriox\Server\Runtime\BedrockInventoryPacketProjector;
use Bedriox\Server\Runtime\BedrockWorldEventPacketEncoder;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerContentsChanged;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\ContainerViewerProjection;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\World\Block\BlockNetworkTranslator;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockPosition;
use PHPUnit\Framework\TestCase;

final class StorageContainerWireProjectionTest extends TestCase
{
    public function testChestOpenProjectsWindowThenContentsAndBlockStateForBothHalves(): void
    {
        $slots = array_fill(0, 54, null);
        $slots[0] = new InventoryStack('minecraft:apple', 2, 31);
        $packets = $this->encoder()->encode(new ContainerOpened(
            'owner',
            7,
            ApiContainerType::DOUBLE_CHEST,
            new BlockPosition(4, 65, -3),
            $slots,
            ['owner', 'nearby'],
            new BlockPosition(5, 65, -3),
        ), []);

        self::assertCount(6, $packets);
        self::assertInstanceOf(ContainerOpenPacket::class, $packets[0]->packet);
        self::assertSame(7, $packets[0]->packet->containerId);
        self::assertSame(ContainerType::Container, $packets[0]->packet->containerType);
        self::assertInstanceOf(InventoryContentPacket::class, $packets[1]->packet);
        self::assertSame(7, $packets[1]->packet->windowId);
        self::assertCount(54, $packets[1]->packet->items);
        foreach (array_slice($packets, 2) as $packet) {
            self::assertInstanceOf(BlockEventPacket::class, $packet->packet);
            self::assertSame(1, $packet->packet->eventData);
        }
    }

    public function testVirtualHopperUsesItsTypedLayoutWithoutBlockAnimation(): void
    {
        $packets = $this->encoder()->encode(new ContainerOpened(
            'owner',
            8,
            ApiContainerType::VIRTUAL,
            null,
            array_fill(0, 5, null),
            ['owner'],
            title: 'Portable storage',
            layout: ContainerLayout::HOPPER,
        ), []);

        self::assertCount(2, $packets);
        self::assertInstanceOf(ContainerOpenPacket::class, $packets[0]->packet);
        self::assertSame(ContainerType::Hopper, $packets[0]->packet->containerType);
        self::assertSame([0, 0, 0], [
            $packets[0]->packet->position->x,
            $packets[0]->packet->position->y,
            $packets[0]->packet->position->z,
        ]);
        self::assertInstanceOf(InventoryContentPacket::class, $packets[1]->packet);
        self::assertCount(5, $packets[1]->packet->items);
    }

    public function testContainerChangesUseEachViewersWindowAndStackIdentity(): void
    {
        $owner = array_fill(0, 27, null);
        $owner[2] = new InventoryStack('minecraft:apple', 1, 41);
        $peer = array_fill(0, 27, null);
        $peer[2] = new InventoryStack('minecraft:apple', 1, 72);
        $packets = $this->encoder()->encode(new ContainerContentsChanged(
            'owner',
            7,
            ApiContainerType::CHEST,
            new BlockPosition(4, 65, -3),
            $owner,
            [2],
            [new ContainerViewerProjection('peer', 9, $peer)],
        ), []);

        self::assertCount(4, $packets);
        self::assertSame(['owner', 'owner', 'peer', 'peer'], array_map(
            static fn($packet): string => $packet->sessionId,
            $packets,
        ));
        foreach ($packets as $packet) {
            self::assertInstanceOf(InventorySlotPacket::class, $packet->packet);
            self::assertSame(2, $packet->packet->slot);
        }
        $ownerActual = $packets[1]->packet;
        $peerActual = $packets[3]->packet;
        self::assertInstanceOf(InventorySlotPacket::class, $ownerActual);
        self::assertInstanceOf(InventorySlotPacket::class, $peerActual);
        self::assertSame(7, $ownerActual->containerId);
        self::assertSame(41, $ownerActual->item->stackNetworkId);
        self::assertSame(9, $peerActual->containerId);
        self::assertSame(72, $peerActual->item->stackNetworkId);
    }

    public function testOpenedContainerResponseRetainsDynamicContainerName(): void
    {
        $opened = array_fill(0, 27, null);
        $opened[4] = new InventoryStack('minecraft:apple', 2, 19);
        $event = new InventoryStackRequestProcessed(
            'owner',
            -20,
            true,
            [new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                4,
                18,
                FullContainerName::DYNAMIC,
                responseSlot: 4,
                responseContainerDynamicId: 73,
            )],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            openedContainerInventory: $opened,
            openedContainerWindowId: 7,
        );

        $packets = $this->encoder()->encode($event, []);
        self::assertCount(1, $packets);
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        $container = $packets[0]->packet->responses[0]->containers[0];
        self::assertSame(FullContainerName::DYNAMIC, $container->containerName->containerNameId);
        self::assertSame(73, $container->containerName->dynamicId);
        self::assertSame(19, $container->slots[0]->stackNetworkId);
    }

    public function testAcceptedStoragePlacementUsesTheCurrentResponseSlotWireLayout(): void
    {
        $opened = array_fill(0, 27, null);
        $opened[12] = new InventoryStack('minecraft:stone', 16, 42);
        $event = new InventoryStackRequestProcessed(
            'owner',
            -1055,
            true,
            [new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                12,
                0,
                FullContainerName::LEVEL_ENTITY,
                responseSlot: 12,
            )],
            array_fill(0, 36, null),
            null,
            0,
            null,
            false,
            41,
            [],
            openedContainerInventory: $opened,
            openedContainerWindowId: 7,
        );

        $packets = $this->encoder()->encode($event, []);
        self::assertCount(1, $packets);
        self::assertInstanceOf(ItemStackResponsePacket::class, $packets[0]->packet);
        self::assertSame(
            '0100bd1001010700010c0c10015400010000',
            bin2hex($packets[0]->packet->encode()),
        );
    }

    public function testClientInitiatedCloseOnlyProjectsBlockStateTeardown(): void
    {
        $packets = $this->encoder()->encode(new ContainerClosed(
            'owner',
            7,
            ApiContainerType::CHEST,
            new BlockPosition(4, 65, -3),
            ['owner'],
            serverInitiated: false,
        ), []);

        self::assertCount(1, $packets);
        self::assertInstanceOf(BlockEventPacket::class, $packets[0]->packet);
        self::assertSame(0, $packets[0]->packet->eventData);
        self::assertNotInstanceOf(ContainerClosePacket::class, $packets[0]->packet);
    }

    public function testLiveBlockEntityChangeProjectsBoundedActorData(): void
    {
        $position = new BlockPosition(4, 65, -3);
        $entity = ContainerBlockEntity::empty(BlockEntityType::Chest, $position)
            ->withPair(new BlockPosition(5, 65, -3), true);

        $packets = $this->encoder()->encode(new BlockEntityChanged($entity, ['owner', 'nearby']), []);

        self::assertCount(2, $packets);
        self::assertSame(['owner', 'nearby'], array_map(
            static fn($directed): string => $directed->sessionId,
            $packets,
        ));
        foreach ($packets as $directed) {
            self::assertInstanceOf(BlockActorDataPacket::class, $directed->packet);
            self::assertSame([4, 65, -3], [
                $directed->packet->position->x,
                $directed->packet->position->y,
                $directed->packet->position->z,
            ]);
            self::assertNotSame('', $directed->packet->networkNbt);
        }
    }

    private function encoder(): BedrockWorldEventPacketEncoder
    {
        $data = BedrockDataSet::bundled();
        $internal = new BlockStateRegistry($data->blockStateRegistry()->states());
        $translator = new BlockNetworkTranslator($internal, $data->blockStateRegistry());

        return new BedrockWorldEventPacketEncoder(
            new BedrockChunkPacketSerializer($translator),
            BedrockInventoryPacketProjector::fromData($data, $translator),
        );
    }
}
