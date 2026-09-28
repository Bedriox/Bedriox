<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Inventory\ContainerLayout;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginException;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerContentsChanged;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SimulationPluginContainerService;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class SimulationPluginContainerServiceTest extends TestCase
{
    public function testCachedWorldContainerHandleBecomesUnavailableAfterItsBlockIsRemoved(): void
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('plugin-container-test', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(4),
        );
        $position = new BlockPosition(1, 64, 0);
        $world->setBlockState($position->x, $position->y, $position->z, $registry->internalId(
            CanonicalBlockState::from('minecraft:chest', ['minecraft:cardinal_direction' => 'north']),
        ));
        $world->setBlockEntity(ContainerBlockEntity::empty(BlockEntityType::Chest, $position));
        $simulation = new WorldSimulation(blockWorld: $world, blockPalette: $palette, blockStateRegistry: $registry);
        $manager = (new SimulationPluginContainerService(
            'Example',
            new ContainerRuntimeControl(true),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
            $simulation,
        ))->manager();
        $container = $manager->at(new ApiWorld('plugin-container-test', 1), new ApiBlockPosition(1, 64, 0));
        self::assertNotNull($container);
        self::assertTrue($container->isAvailable());

        $world->setBlockState($position->x, $position->y, $position->z, $palette->air);
        $world->removeBlockEntity($position);

        self::assertFalse($container->isAvailable());
        self::assertSame([], $container->contents());
    }

    public function testTypedVirtualContainerMutatesOpensAndClosesThroughItsPublicHandle(): void
    {
        $simulation = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        $uuid = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($simulation->enqueue($factory->join('one', $uuid, 'One')));
        $simulation->tick();
        $control = new ContainerRuntimeControl(true);
        $ownership = new PluginOwnershipRegistry();
        $manager = (new SimulationPluginContainerService(
            'Example',
            $control,
            new PluginActionBuffer(),
            $ownership,
            $simulation,
        ))->manager();

        $container = $manager->create(ContainerLayout::HOPPER, 'Rewards');
        self::assertSame(ContainerType::VIRTUAL, $container->type());
        self::assertSame(5, $container->view()?->inventory->size());
        self::assertTrue($container->setItem(2, new ItemStack('minecraft:stone', 3)));
        self::assertSame(3, $container->contents()[2]?->count);

        $player = $simulation->pluginPlayer($uuid);
        self::assertNotNull($player);
        self::assertTrue($player->openInventory($container));
        self::assertInstanceOf(ContainerOpened::class, $simulation->tick()->events[0]);
        self::assertSame([$uuid], $container->viewerUuids());
        self::assertTrue($player->closeInventory($container));
        self::assertInstanceOf(ContainerClosed::class, $simulation->tick()->events[0]);
        self::assertSame([], $container->viewerUuids());
        self::assertSame(1, $ownership->count('Example'));
    }

    public function testCachedManagerAndContainerRejectUseAfterPluginDisableAndCleanupClosesViewers(): void
    {
        $simulation = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        $uuid = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($simulation->enqueue($factory->join('one', $uuid, 'One')));
        $simulation->tick();
        $control = new ContainerRuntimeControl(true);
        $ownership = new PluginOwnershipRegistry();
        $manager = (new SimulationPluginContainerService(
            'Example',
            $control,
            new PluginActionBuffer(),
            $ownership,
            $simulation,
        ))->manager();
        $container = $manager->create(ContainerLayout::SINGLE_CHEST);
        $player = $simulation->pluginPlayer($uuid);
        self::assertNotNull($player);
        self::assertTrue($container->open($player));
        $simulation->tick();

        $control->enabled = false;
        self::assertSame([], $ownership->releaseAll('Example'));
        self::assertInstanceOf(ContainerClosed::class, $simulation->tick()->events[0]);

        try {
            $container->contents();
            self::fail('A disabled plugin retained a usable container handle.');
        } catch (PluginException) {
            self::assertSame(0, $ownership->count('Example'));
        }
        $this->expectException(PluginException::class);
        $manager->create(ContainerLayout::SINGLE_CHEST);
    }

    public function testOpenedContainerTransferCommitsBothInventoriesAndSynchronizesOtherViewer(): void
    {
        $simulation = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        $firstUuid = '00000000-0000-0000-0000-000000000001';
        $secondUuid = '00000000-0000-0000-0000-000000000002';
        self::assertTrue($simulation->enqueue($factory->join('one', $firstUuid, 'One')));
        self::assertTrue($simulation->enqueue($factory->join('two', $secondUuid, 'Two')));
        $simulation->tick();
        $manager = (new SimulationPluginContainerService(
            'Example',
            new ContainerRuntimeControl(true),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
            $simulation,
        ))->manager();
        $container = $manager->create(ContainerLayout::SINGLE_CHEST);
        self::assertTrue($container->setItem(0, new ItemStack('minecraft:stone', 3)));
        $first = $simulation->pluginPlayer($firstUuid);
        $second = $simulation->pluginPlayer($secondUuid);
        self::assertNotNull($first);
        self::assertNotNull($second);
        self::assertTrue($container->open($first));
        $firstOpen = $simulation->tick()->events[0];
        self::assertInstanceOf(ContainerOpened::class, $firstOpen);
        self::assertTrue($container->open($second));
        $secondOpen = $simulation->tick()->events[0];
        self::assertInstanceOf(ContainerOpened::class, $secondOpen);
        $source = $firstOpen->slots[0];
        self::assertNotNull($source);

        self::assertTrue($simulation->enqueue($factory->inventoryStackRequest('one', 1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    0,
                    $source->stackNetworkId,
                    expectedCount: 3,
                ),
                new InventorySlotReference(InventoryContainer::Main, 5, 0, expectedCount: 0),
                2,
            ),
        ])));
        $events = $simulation->tick()->events;
        $processed = $events[0];
        if (!$processed instanceof InventoryStackRequestProcessed) {
            self::fail('Expected the container request to produce an inventory response.');
        }
        self::assertTrue($processed->success);
        self::assertSame(1, $container->contents()[0]?->count);
        self::assertSame(2, $simulation->pluginPlayer($firstUuid)?->getInventory()->getItem(5)?->count);
        $synchronized = $events[1];
        if (!$synchronized instanceof ContainerContentsChanged) {
            self::fail('Expected the container request to synchronize its viewers.');
        }
        self::assertSame(['one', 'two'], $synchronized->recipients());
        self::assertSame([], $synchronized->changedSlots, 'A rebuilt peer projection requires a full content sync.');

        $remaining = $processed->openedContainerInventory[0];
        self::assertNotNull($remaining);
        self::assertTrue($simulation->enqueue($factory->dropItem(
            'one',
            2,
            new InventorySlotReference(
                InventoryContainer::OpenedContainer,
                0,
                $remaining->stackNetworkId,
                expectedCount: 1,
            ),
            1,
            InventoryResponseMode::ItemStackResponse,
        )));
        $drop = $simulation->tick()->events[0];
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $drop);
        self::assertTrue($drop->success);
        self::assertNull($container->contents()[0]);
    }

    public function testOpenedContainerTransferSynchronizesTheOwnersActiveWindow(): void
    {
        $simulation = new WorldSimulation();
        $factory = new SimulationCommandFactory();
        $uuid = '00000000-0000-0000-0000-000000000001';
        self::assertTrue($simulation->enqueue($factory->join('one', $uuid, 'One')));
        $simulation->tick();
        $manager = (new SimulationPluginContainerService(
            'Example',
            new ContainerRuntimeControl(true),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
            $simulation,
        ))->manager();
        $container = $manager->create(ContainerLayout::SINGLE_CHEST);
        self::assertTrue($container->setItem(0, new ItemStack('minecraft:stone', 3)));
        $player = $simulation->pluginPlayer($uuid);
        self::assertNotNull($player);
        self::assertTrue($container->open($player));
        $opened = $simulation->tick()->events[0];
        self::assertInstanceOf(ContainerOpened::class, $opened);
        $source = $opened->slots[0];
        self::assertNotNull($source);

        self::assertTrue($simulation->enqueue($factory->inventoryStackRequest('one', 1, [
            new InventoryStackRequestAction(
                InventoryStackRequestActionType::Take,
                new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    0,
                    $source->stackNetworkId,
                    expectedCount: 3,
                ),
                new InventorySlotReference(InventoryContainer::Main, 5, 0, expectedCount: 0),
                2,
            ),
        ])));
        $events = $simulation->tick()->events;

        self::assertInstanceOf(InventoryStackRequestProcessed::class, $events[0]);
        self::assertTrue($events[0]->success);
        self::assertInstanceOf(ContainerContentsChanged::class, $events[1]);
        self::assertSame(['one'], $events[1]->recipients());
        self::assertSame([0], $events[1]->changedSlots);
        self::assertSame(1, $events[1]->slots[0]?->count);
    }
}

final class ContainerRuntimeControl implements PluginRuntimeControl
{
    public function __construct(public bool $enabled) {}

    public function isEnabled(string $plugin): bool
    {
        return $this->enabled;
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void
    {
        $this->enabled = false;
    }
}
