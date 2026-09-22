<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Event\Block\BlockBreakEvent;
use Bedriox\Api\Event\Block\BlockBrokenEvent;
use Bedriox\Api\Event\Block\BlockPlacedEvent;
use Bedriox\Api\Event\Block\BlockPlaceEvent;
use Bedriox\Api\Event\Inventory\InventoryChangedEvent;
use Bedriox\Api\Event\Inventory\InventoryChangeEvent;
use Bedriox\Api\Event\Player\PlayerAttackedEvent;
use Bedriox\Api\Event\Player\PlayerAttackEvent;
use Bedriox\Api\Event\Player\PlayerChatBroadcastEvent;
use Bedriox\Api\Event\Player\PlayerChatEvent;
use Bedriox\Api\Event\Player\PlayerDamagedEvent;
use Bedriox\Api\Event\Player\PlayerDamageEvent;
use Bedriox\Api\Event\Player\PlayerDeathEvent;
use Bedriox\Api\Event\Player\PlayerDropItemEvent;
use Bedriox\Api\Event\Player\PlayerDroppedItemEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangedEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangeEvent;
use Bedriox\Api\Event\Player\PlayerJoinEvent;
use Bedriox\Api\Event\Player\PlayerKickEvent;
use Bedriox\Api\Event\Player\PlayerLoginEvent;
use Bedriox\Api\Event\Player\PlayerMovedEvent;
use Bedriox\Api\Event\Player\PlayerMoveEvent;
use Bedriox\Api\Event\Player\PlayerPickedUpItemEvent;
use Bedriox\Api\Event\Player\PlayerPickupItemEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Api\Event\Player\PlayerQuitEvent;
use Bedriox\Api\Event\Player\PlayerRespawnedEvent;
use Bedriox\Api\Event\Player\PlayerRespawnEvent;
use Bedriox\Api\Inventory\Inventory as ApiInventory;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Block as ApiBlock;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerVitals;
use Bedriox\Server\Plugin\Event\EventDispatcher;
use Bedriox\Server\World\BlockPosition;
use Closure;

/** Projects authoritative simulation state into the capability-limited public event API. */
final readonly class PluginGameplayEventBridge
{
    /** @param null|Closure(string): PlayerConnection $playerConnections */
    public function __construct(
        private EventDispatcher $events,
        private ?Closure $playerConnections = null,
    ) {}

    /** @param Closure(string): PlayerConnection $playerConnections */
    public function withPlayerConnections(Closure $playerConnections): self
    {
        return new self($this->events, $playerConnections);
    }

    public function allowJoin(string $name, string $uuid): bool
    {
        $event = new PlayerPreJoinEvent($name, $uuid);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function joined(Player $player): void
    {
        $this->events->dispatch(new PlayerJoinEvent($this->playerView($player)));
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
        $this->events->dispatch(new PlayerQuitEvent($this->playerView($player)));
    }

    /** @return null|array{string, ?string, ?string} */
    public function kick(ApiPlayer $player, string $reason, ?string $quitMessage, ?string $screenMessage): ?array
    {
        $event = new PlayerKickEvent($player, $reason, $quitMessage, $screenMessage);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : [$event->reason(), $event->quitMessage(), $event->disconnectScreenMessage()];
    }

    public function allowMove(Player $player, Position $target): bool
    {
        $event = new PlayerMoveEvent(
            $this->playerView($player),
            self::position($player->movement->position),
            self::position($target),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function moved(Player $player): void
    {
        $this->events->dispatch(new PlayerMovedEvent($this->playerView($player)));
    }

    public function damage(Player $player, DamageCause $cause, float $damage): ?float
    {
        $event = new PlayerDamageEvent($this->playerView($player), $cause->value, $damage);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->damage();
    }

    public function damaged(Player $player, DamageCause $cause, float $damage): void
    {
        $this->events->dispatch(new PlayerDamagedEvent($this->playerView($player), $cause->value, $damage));
    }

    public function attack(Player $attacker, Player $target, float $damage): ?float
    {
        $event = new PlayerAttackEvent($this->playerView($attacker), $this->playerView($target), $damage);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->damage();
    }

    public function attacked(Player $attacker, Player $target, float $damage): void
    {
        $this->events->dispatch(new PlayerAttackedEvent(
            $this->playerView($attacker),
            $this->playerView($target),
            $damage,
        ));
    }

    public function death(
        Player $player,
        DamageCause $cause,
        float $damage,
        ?Player $killer,
        string|TranslatableMessage|null $deathMessage,
        string|TranslatableMessage|null $deathScreenMessage,
    ): DeathPresentation {
        $event = new PlayerDeathEvent(
            $this->playerView($player),
            $cause->value,
            $damage,
            true,
            $killer === null ? null : $this->playerView($killer),
            $deathMessage,
            $deathScreenMessage,
        );
        $this->events->dispatch($event);

        return new DeathPresentation($event->deathMessage(), $event->deathScreenMessage());
    }

    public function respawn(Player $player, Position $position): Position
    {
        $event = new PlayerRespawnEvent($this->playerView($player), self::position($position));
        $this->events->dispatch($event);
        $destination = $event->position();

        return new Position($destination->x, $destination->y, $destination->z);
    }

    public function respawned(Player $player): void
    {
        $this->events->dispatch(new PlayerRespawnedEvent($this->playerView($player)));
    }

    public function gameModeChange(Player $player, GameMode $gameMode): ?GameMode
    {
        $event = new PlayerGameModeChangeEvent($this->playerView($player), $player->gameMode(), $gameMode);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->gameMode();
    }

    public function gameModeChanged(Player $player, GameMode $previous): void
    {
        $this->events->dispatch(new PlayerGameModeChangedEvent(
            $this->playerView($player),
            $previous,
            $player->gameMode(),
        ));
    }

    public function chat(Player $player, string $message): ?string
    {
        $event = new PlayerChatEvent($this->playerView($player), $message);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->message();
    }

    public function chatBroadcast(Player $player, string $message): void
    {
        $this->events->dispatch(new PlayerChatBroadcastEvent($this->playerView($player), $message));
    }

    public function allowBlockBreak(Player $player, BlockPosition $position, string $identifier): bool
    {
        $event = new BlockBreakEvent($this->playerView($player), self::block($position, $identifier));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function blockBroken(Player $player, BlockPosition $position, string $identifier): void
    {
        $this->events->dispatch(new BlockBrokenEvent($this->playerView($player), self::block($position, $identifier)));
    }

    public function allowBlockPlace(Player $player, BlockPosition $position, string $identifier): bool
    {
        $event = new BlockPlaceEvent($this->playerView($player), self::block($position, $identifier));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function blockPlaced(Player $player, BlockPosition $position, string $identifier): void
    {
        $this->events->dispatch(new BlockPlacedEvent($this->playerView($player), self::block($position, $identifier)));
    }

    public function allowInventoryChange(Player $player, PlayerInventory $before, PlayerInventory $after): bool
    {
        $event = new InventoryChangeEvent($this->playerView($player, $before), self::inventory($before), self::inventory($after));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function inventoryChanged(Player $player, PlayerInventory $before): void
    {
        $this->events->dispatch(new InventoryChangedEvent(
            $this->playerView($player),
            self::inventory($before),
            self::inventory($player->inventory),
        ));
    }

    public function pickupItem(Player $player, InventoryStack $stack): ?int
    {
        $event = new PlayerPickupItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt),
            $stack->count,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->count();
    }

    public function pickedUpItem(Player $player, InventoryStack $stack): void
    {
        $this->events->dispatch(new PlayerPickedUpItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt),
        ));
    }

    public function dropItem(Player $player, InventoryStack $stack): ?int
    {
        $event = new PlayerDropItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt),
            $stack->count,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->count();
    }

    public function droppedItem(Player $player, InventoryStack $stack): void
    {
        $this->events->dispatch(new PlayerDroppedItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt),
        ));
    }

    public function playerView(Player $player, ?PlayerInventory $inventory = null): ApiPlayer
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
            $player->vitals->health,
            PlayerVitals::MAX_HEALTH,
            $player->vitals->isAlive(),
            $player->gameMode(),
            $this->playerConnections === null
                ? PlayerConnection::disconnected()
                : ($this->playerConnections)($snapshot->identity),
        );
    }

    public static function detachedPlayerView(Player $player, ?PlayerInventory $inventory = null): ApiPlayer
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
            $player->vitals->health,
            PlayerVitals::MAX_HEALTH,
            $player->vitals->isAlive(),
            $player->gameMode(),
            PlayerConnection::disconnected(),
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
        return $stack === null ? null : new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt);
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
