<?php

declare(strict_types=1);

namespace Bedriox\Server\Tests\Api;

use Bedriox\Api\Event\Block\ChestPairedEvent;
use Bedriox\Api\Event\Block\ChestPairEvent;
use Bedriox\Api\Event\CancellableEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseReason;
use Bedriox\Api\Event\Inventory\InventoryOpenedEvent;
use Bedriox\Api\Event\Inventory\InventoryOpenEvent;
use Bedriox\Api\Event\Inventory\InventoryTransactionCommittedEvent;
use Bedriox\Api\Event\Inventory\InventoryTransactionEvent;
use Bedriox\Api\Event\PostEvent;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerType;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Inventory\Inventory;
use Bedriox\Api\Inventory\InventoryActionType;
use Bedriox\Api\Inventory\InventoryTransaction;
use Bedriox\Api\Inventory\InventoryTransactionAction;
use Bedriox\Api\Inventory\InventoryTransactionCause;
use Bedriox\Api\Inventory\InventoryView;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Api\Player\Player;
use Bedriox\Api\World\Block;
use Bedriox\Api\World\BlockPosition;
use Bedriox\Api\World\Position;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class ContainerApiTest extends TestCase
{
    public function testContainerViewsAreProtocolNeutralAndPositioned(): void
    {
        $position = new BlockPosition(4, 65, -2);
        $inventory = new InventoryView('world:4:65:-2', [new ItemStack('minecraft:apple', 3), null], '12');
        $container = new ContainerView(
            ContainerType::CHEST,
            $inventory,
            $position,
            'Supplies',
            ['00000000-0000-0000-0000-000000000001'],
        );

        self::assertSame($position, $container->position);
        self::assertSame('minecraft:apple', $container->inventory->stackAt(0)?->identifier);
        self::assertSame(2, $container->inventory->size());
    }

    public function testLiveContainerUsesSnapshotRevisionForSafeOperations(): void
    {
        $view = new ContainerView(
            ContainerType::BARREL,
            new InventoryView('world:barrel', [null], '7'),
            new BlockPosition(0, 64, 0),
        );
        $calls = [];
        $container = new Container(
            static fn(): ContainerView => $view,
            static function (int $slot, ?ItemStack $stack, string $revision) use (&$calls): bool {
                $calls[] = ['set', $slot, $stack?->identifier, $revision];

                return true;
            },
            static fn(ItemStack $stack, string $revision): ?ItemStack => null,
            static fn(ItemStack $stack, string $revision): int => $stack->count,
            static fn(string $revision): bool => $revision === '7',
            static fn(Player $player): bool => true,
            static fn(Player $player): bool => true,
        );

        self::assertTrue($container->setItem(0, new ItemStack('minecraft:stone', 1)));
        self::assertFalse($container->setItem(1, null));
        self::assertTrue($container->clear());
        self::assertSame(ContainerType::BARREL, $container->type());
        self::assertEquals(new BlockPosition(0, 64, 0), $container->position());
        self::assertSame([null], $container->contents());
        self::assertSame([], $container->viewerUuids());
        self::assertSame([['set', 0, 'minecraft:stone', '7']], $calls);
    }

    public function testInventoryLifecycleEventsExposeSnapshotAndLiveHandleWithoutCancellableClose(): void
    {
        $player = self::player();
        $view = new ContainerView(
            ContainerType::ENDER_CHEST,
            new InventoryView('player:ender', [null], '0'),
            new BlockPosition(1, 64, 1),
        );
        $handle = self::unavailableContainer();
        $open = new InventoryOpenEvent($player, $view, $handle);
        $opened = new InventoryOpenedEvent($player, $view, $handle);
        $close = new InventoryCloseEvent($player, $view, InventoryCloseReason::TELEPORT, $handle);

        self::assertInstanceOf(CancellableEvent::class, $open);
        self::assertInstanceOf(PostEvent::class, $opened);
        self::assertInstanceOf(PostEvent::class, $close);
        self::assertSame(InventoryCloseReason::TELEPORT, $close->reason);
        self::assertSame($handle, $open->handle);
    }

    public function testTransactionEventsDescribeCompleteAtomicBeforeAndAfterState(): void
    {
        $before = new InventoryView('world:chest', [new ItemStack('minecraft:stone', 4), null], '1');
        $after = new InventoryView('world:chest', [null, new ItemStack('minecraft:stone', 4)], '2');
        $transaction = new InventoryTransaction(
            'request-42',
            InventoryTransactionCause::PLAYER,
            [$before],
            [$after],
            [new InventoryTransactionAction(
                InventoryActionType::MOVE,
                'world:chest',
                0,
                $before->stackAt(0),
                null,
            )],
        );
        $pre = new InventoryTransactionEvent(self::player(), $transaction);
        $post = new InventoryTransactionCommittedEvent(self::player(), $transaction);

        self::assertInstanceOf(CancellableEvent::class, $pre);
        self::assertInstanceOf(PostEvent::class, $post);
        self::assertSame($transaction, $post->transaction);
    }

    public function testChestPairEventsExposeBothExactBlocks(): void
    {
        $left = new Block(new BlockPosition(0, 64, 0), 'minecraft:chest');
        $right = new Block(new BlockPosition(1, 64, 0), 'minecraft:chest');
        $pre = new ChestPairEvent($left, $right, self::player());
        $post = new ChestPairedEvent($left, $right, self::player());

        self::assertInstanceOf(CancellableEvent::class, $pre);
        self::assertInstanceOf(PostEvent::class, $post);
        self::assertSame($right->position, $post->right->position);
    }

    public function testTransactionRejectsActionsOutsideItsInventorySet(): void
    {
        $view = new InventoryView('world:chest', [null], '0');

        $this->expectException(InvalidArgumentException::class);
        new InventoryTransaction(
            'request-1',
            InventoryTransactionCause::PLAYER,
            [$view],
            [$view],
            [new InventoryTransactionAction(InventoryActionType::MOVE, 'world:other', 0, null, null)],
        );
    }

    private static function unavailableContainer(): Container
    {
        return new Container(
            static fn(): ?ContainerView => null,
            static fn(int $slot, ?ItemStack $stack, string $revision): bool => false,
            static fn(ItemStack $stack, string $revision): ItemStack => $stack,
            static fn(ItemStack $stack, string $revision): int => 0,
            static fn(string $revision): bool => false,
            static fn(Player $player): bool => false,
            static fn(Player $player): bool => false,
        );
    }

    private static function player(): Player
    {
        return new Player(
            'Player',
            '00000000-0000-0000-0000-000000000001',
            new Position(0.0, 64.0, 0.0),
            0.0,
            0.0,
            false,
            false,
            new Inventory(array_fill(0, 36, null), 0),
        );
    }
}
