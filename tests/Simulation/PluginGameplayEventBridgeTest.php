<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Simulation;

use Bedriox\Api\Event\Block\BlockBreakEvent;
use Bedriox\Api\Event\Block\BlockBrokenEvent;
use Bedriox\Api\Event\Block\BlockPlaceEvent;
use Bedriox\Api\Event\Inventory\InventoryChangedEvent;
use Bedriox\Api\Event\Inventory\InventoryChangeEvent;
use Bedriox\Api\Event\Player\PlayerChatBroadcastEvent;
use Bedriox\Api\Event\Player\PlayerChatEvent;
use Bedriox\Api\Event\Player\PlayerJoinEvent;
use Bedriox\Api\Event\Player\PlayerMoveEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Data\BedrockDataSet;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginExecutionContext;
use Bedriox\Server\Plugin\PluginExecutionFrame;
use Bedriox\Server\Plugin\PluginOwnershipRegistry;
use Bedriox\Server\Plugin\PluginRuntimeControl;
use Bedriox\Server\Simulation\BlockBreakAction;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\MovementMode;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkRepository;
use Bedriox\Server\World\FlatWorldGenerator;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldMetadata;
use PHPUnit\Framework\TestCase;
use Throwable;

final class PluginGameplayEventBridgeTest extends TestCase
{
    public function testCancelledJoinUsesTheExistingAuthoritativeRejection(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $joined = 0;
        $dispatcher->register('Example', PlayerPreJoinEvent::class, static function (PlayerPreJoinEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', PlayerJoinEvent::class, static function () use (&$joined): void {
            ++$joined;
        });
        [$simulation, $factory] = self::simulation($bridge);

        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(0, $joined);
        self::assertSame([], $simulation->snapshot()->players);
    }

    public function testChatCanBeChangedBeforeCommitAndPublishesAnImmutablePostEvent(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $broadcast = null;
        $dispatcher->register('Example', PlayerChatEvent::class, static function (PlayerChatEvent $event): void {
            $event->setMessage('changed');
        });
        $dispatcher->register('Example', PlayerChatBroadcastEvent::class, static function (PlayerChatBroadcastEvent $event) use (&$broadcast): void {
            $broadcast = $event->message;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->chat('one', 1, 'original'));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(ChatBroadcast::class, $event);
        self::assertSame('changed', $event->message);
        self::assertSame('changed', $broadcast);
    }

    public function testCancelledChatIsNotBroadcastAndStillConsumesItsAntiReplaySequence(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerChatEvent::class, static function (PlayerChatEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->chat('one', 1, 'blocked'));
        $cancelled = $simulation->tick()->events[0];
        $simulation->enqueue($factory->chat('one', 1, 'replay'));
        $replay = $simulation->tick()->events[0];

        self::assertInstanceOf(CommandRejected::class, $cancelled);
        self::assertSame('plugin_cancelled', $cancelled->reason);
        self::assertInstanceOf(CommandRejected::class, $replay);
        self::assertSame('stale_chat_sequence', $replay->reason);
    }

    public function testCancelledBlockBreakCorrectsThePredictionWithoutMutatingWorld(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', BlockBreakEvent::class, static function (BlockBreakEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', BlockBrokenEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory, $world, $palette] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();
        $position = new BlockPosition(1, 63, 0);

        $simulation->enqueue($factory->breakBlock('one', 1, BlockBreakAction::Start, $position, 1));
        $simulation->tick();
        $simulation->enqueue($factory->breakBlock('one', 2, BlockBreakAction::Complete, $position, 1));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(BlockChanged::class, $event);
        self::assertSame(['one'], $event->recipients());
        self::assertTrue($event->stopBreaking);
        self::assertSame($palette->grassBlock->value, $world->blockStateAt(1, 63, 0)->value);
        self::assertSame(0, $postEvents);
    }

    public function testCancelledMovementReturnsAnAuthoritativeCorrection(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', PlayerMoveEvent::class, static function (PlayerMoveEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->move('one', 1, 0.1, 64.0, 0.0, 0.0, 0.0, MovementMode::WALKING));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(MovementCorrected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame(0.0, $event->authoritativePlayer->position->x);
        self::assertSame(1, $event->authoritativePlayer->movementSequence);
    }

    public function testCancelledPlacementRepairsThePredictedBlockAndPreservesInventory(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $dispatcher->register('Example', BlockPlaceEvent::class, static function (BlockPlaceEvent $event): void {
            $event->cancel();
        });
        [$simulation, $factory, $world, $palette] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->placeBlock(
            'one',
            1,
            new BlockPosition(1, 63, 0),
            1,
            0,
            0,
            0.5,
            1.0,
            0.5,
        ));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(BlockPlacementCorrected::class, $event);
        self::assertSame('plugin_cancelled', $event->reason);
        self::assertSame($palette->air->value, $world->blockStateAt(1, 64, 0)->value);
        self::assertSame(64, $event->heldStack?->count);
    }

    public function testCancelledInventorySelectionReturnsTheCurrentAuthoritativeSlot(): void
    {
        [$dispatcher, $bridge] = self::bridge();
        $postEvents = 0;
        $dispatcher->register('Example', InventoryChangeEvent::class, static function (InventoryChangeEvent $event): void {
            $event->cancel();
        });
        $dispatcher->register('Example', InventoryChangedEvent::class, static function () use (&$postEvents): void {
            ++$postEvents;
        });
        [$simulation, $factory] = self::simulation($bridge);
        $simulation->enqueue($factory->join('one', 'identity-one', 'One'));
        $simulation->tick();

        $simulation->enqueue($factory->selectHotbarSlot('one', 1));
        $event = $simulation->tick()->events[0];

        self::assertInstanceOf(HeldItemChanged::class, $event);
        self::assertSame(0, $event->hotbarSlot);
        self::assertSame(0, $postEvents);
    }

    public function testPluginApiRequestsCommitThroughTheAuthoritativeQueue(): void
    {
        [, $bridge] = self::bridge();
        [$simulation, , $world, $palette] = self::simulation($bridge);
        $simulation->enqueue((new SimulationCommandFactory())->join('one', 'identity-one', 'One'));
        $simulation->tick();

        self::assertTrue($simulation->enqueuePluginMessage('identity-one', 'hello'));
        self::assertTrue($simulation->enqueuePluginTeleport('identity-one', new Position(2.0, 64.0, 0.0)));
        self::assertTrue($simulation->enqueuePluginBlock('Example', new BlockPosition(1, 63, 0), 'minecraft:air'));
        self::assertTrue($simulation->enqueuePluginInventorySlot(
            'identity-one',
            1,
            new InventoryStack('minecraft:grass_block', 3, 1, $palette->grassBlock),
        ));

        $events = $simulation->tick()->events;
        self::assertInstanceOf(ChatBroadcast::class, $events[0]);
        self::assertSame(['one'], $events[0]->recipients());
        self::assertInstanceOf(MovementCorrected::class, $events[1]);
        self::assertSame(2.0, $events[1]->authoritativePlayer->position->x);
        self::assertInstanceOf(BlockChanged::class, $events[2]);
        self::assertSame($palette->air->value, $world->blockStateAt(1, 63, 0)->value);
        self::assertInstanceOf(InventoryStackRequestProcessed::class, $events[3]);
        self::assertSame(3, $events[3]->mainInventory[1]?->count);
    }

    /** @return array{EventDispatcher, PluginGameplayEventBridge} */
    private static function bridge(): array
    {
        $control = new GameplayEventRuntimeControl();
        $dispatcher = new EventDispatcher(
            $control,
            new PluginExecutionContext(),
            new PluginActionBuffer(),
            new PluginOwnershipRegistry(),
        );

        return [$dispatcher, new PluginGameplayEventBridge($dispatcher)];
    }

    /** @return array{WorldSimulation, SimulationCommandFactory, World, FixedFlatBlockPalette} */
    private static function simulation(PluginGameplayEventBridge $bridge): array
    {
        $registry = new BlockStateRegistry(BedrockDataSet::bundled()->blockStateRegistry()->states());
        $palette = FixedFlatBlockPalette::fromRegistry($registry);
        $world = new World(
            new WorldMetadata('plugin-events', 0),
            new FlatWorldGenerator($palette),
            new ChunkRepository(8),
        );

        return [
            new WorldSimulation(blockWorld: $world, blockPalette: $palette, pluginEvents: $bridge),
            new SimulationCommandFactory(),
            $world,
            $palette,
        ];
    }
}

final class GameplayEventRuntimeControl implements PluginRuntimeControl
{
    public function isEnabled(string $plugin): bool
    {
        return $plugin === 'Example';
    }

    public function version(string $plugin): string
    {
        return '1.0.0';
    }

    public function disableAfterFailure(string $plugin, Throwable $failure, ?PluginExecutionFrame $frame): void {}
}
