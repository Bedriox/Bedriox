<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Event\Block\BlockBreakEvent;
use Bedriox\Api\Event\Block\BlockBrokenEvent;
use Bedriox\Api\Event\Block\BlockPlacedEvent;
use Bedriox\Api\Event\Block\BlockPlaceEvent;
use Bedriox\Api\Event\Inventory\InventoryChangedEvent;
use Bedriox\Api\Event\Inventory\InventoryChangeEvent;
use Bedriox\Api\Event\Player\PlayerChatBroadcastEvent;
use Bedriox\Api\Event\Player\PlayerChatEvent;
use Bedriox\Api\Event\Player\PlayerJoinEvent;
use Bedriox\Api\Event\Player\PlayerLoginEvent;
use Bedriox\Api\Event\Player\PlayerMovedEvent;
use Bedriox\Api\Event\Player\PlayerMoveEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Api\Event\Player\PlayerQuitEvent;
use Bedriox\Api\Inventory\Inventory as ApiInventory;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\World\Block as ApiBlock;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\World\BlockPosition;

/** Projects authoritative simulation state into the capability-limited public event API. */
final readonly class PluginGameplayEventBridge
{
    public function __construct(private EventDispatcher $events) {}

    public function allowJoin(string $name, string $uuid): bool
    {
        $event = new PlayerPreJoinEvent($name, $uuid);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function joined(Player $player): void
    {
        $this->events->dispatch(new PlayerJoinEvent(self::playerView($player)));
    }

    public function login(ApiPlayer $player): PlayerLoginDecision
    {
        $event = new PlayerLoginEvent($player, $player->position, $player->yaw, $player->pitch);
        $this->events->dispatch($event);
        $destination = $event->destination();

        return new PlayerLoginDecision(
            !$event->isCancelled(),
            new Position($destination->x, $destination->y, $destination->z),
            $event->yaw(),
            $event->pitch(),
        );
    }

    public function quit(Player $player): void
    {
        $this->events->dispatch(new PlayerQuitEvent(self::playerView($player)));
    }

    public function allowMove(Player $player, Position $target): bool
    {
        $event = new PlayerMoveEvent(
            self::playerView($player),
            self::position($player->movement->position),
            self::position($target),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function moved(Player $player): void
    {
        $this->events->dispatch(new PlayerMovedEvent(self::playerView($player)));
    }

    public function chat(Player $player, string $message): ?string
    {
        $event = new PlayerChatEvent(self::playerView($player), $message);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->message();
    }

    public function chatBroadcast(Player $player, string $message): void
    {
        $this->events->dispatch(new PlayerChatBroadcastEvent(self::playerView($player), $message));
    }

    public function allowBlockBreak(Player $player, BlockPosition $position, string $identifier): bool
    {
        $event = new BlockBreakEvent(self::playerView($player), self::block($position, $identifier));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function blockBroken(Player $player, BlockPosition $position, string $identifier): void
    {
        $this->events->dispatch(new BlockBrokenEvent(self::playerView($player), self::block($position, $identifier)));
    }

    public function allowBlockPlace(Player $player, BlockPosition $position, string $identifier): bool
    {
        $event = new BlockPlaceEvent(self::playerView($player), self::block($position, $identifier));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function blockPlaced(Player $player, BlockPosition $position, string $identifier): void
    {
        $this->events->dispatch(new BlockPlacedEvent(self::playerView($player), self::block($position, $identifier)));
    }

    public function allowInventoryChange(Player $player, PlayerInventory $before, PlayerInventory $after): bool
    {
        $event = new InventoryChangeEvent(self::playerView($player, $before), self::inventory($before), self::inventory($after));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function inventoryChanged(Player $player, PlayerInventory $before): void
    {
        $this->events->dispatch(new InventoryChangedEvent(
            self::playerView($player),
            self::inventory($before),
            self::inventory($player->inventory),
        ));
    }

    public static function playerView(Player $player, ?PlayerInventory $inventory = null): ApiPlayer
    {
        $snapshot = $player->snapshot();

        return new ApiPlayer(
            $snapshot->displayName,
            $snapshot->identity,
            self::position($snapshot->position),
            $snapshot->yaw,
            $snapshot->pitch,
            $snapshot->sneaking,
            $snapshot->sprinting,
            self::inventory($inventory ?? $player->inventory),
        );
    }

    private static function inventory(PlayerInventory $inventory): ApiInventory
    {
        return new ApiInventory(
            array_map(self::item(...), $inventory->slots()),
            $inventory->selectedHotbarSlot(),
            self::item($inventory->cursorStack()),
        );
    }

    private static function item(?InventoryStack $stack): ?ApiItemStack
    {
        return $stack === null ? null : new ApiItemStack($stack->identifier, $stack->count);
    }

    private static function position(Position $position): ApiPosition
    {
        return new ApiPosition($position->x, $position->y, $position->z);
    }

    private static function block(BlockPosition $position, string $identifier): ApiBlock
    {
        return new ApiBlock(new ApiBlockPosition($position->x, $position->y, $position->z), $identifier);
    }
}
