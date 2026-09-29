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

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Crafting\CraftingGrid as ApiCraftingGrid;
use Bedriox\Api\Crafting\CraftingRecipe as ApiCraftingRecipe;
use Bedriox\Api\Effect\EffectActions;
use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\LivingEntity as ApiLivingEntity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Block\BlockBreakEvent;
use Bedriox\Api\Event\Block\BlockBrokenEvent;
use Bedriox\Api\Event\Block\BlockPlacedEvent;
use Bedriox\Api\Event\Block\BlockPlaceEvent;
use Bedriox\Api\Event\Block\BrewedEvent;
use Bedriox\Api\Event\Block\BrewingEvent;
use Bedriox\Api\Event\Block\BrewingFuelConsumedEvent;
use Bedriox\Api\Event\Block\BrewingFuelConsumeEvent;
use Bedriox\Api\Event\Block\ChestPairedEvent;
use Bedriox\Api\Event\Block\ChestPairEvent;
use Bedriox\Api\Event\Entity\EntityCombustEvent;
use Bedriox\Api\Event\Entity\EntityDamageByEntityEvent;
use Bedriox\Api\Event\Entity\EntityDamageEvent;
use Bedriox\Api\Event\Entity\EntityDeathEvent;
use Bedriox\Api\Event\Entity\EntityDespawnedEvent;
use Bedriox\Api\Event\Entity\EntityDespawnEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddedEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddEvent;
use Bedriox\Api\Event\Entity\EntityEffectRemovedEvent;
use Bedriox\Api\Event\Entity\EntityEffectRemoveEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangedEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangeEvent;
use Bedriox\Api\Event\Entity\EntityInteractEvent;
use Bedriox\Api\Event\Entity\EntitySpawnedEvent;
use Bedriox\Api\Event\Entity\EntitySpawnEvent;
use Bedriox\Api\Event\Entity\PotionProjectileImpactedEvent;
use Bedriox\Api\Event\Entity\PotionProjectileImpactEvent;
use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\Inventory\InventoryChangedEvent;
use Bedriox\Api\Event\Inventory\InventoryChangeEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseReason;
use Bedriox\Api\Event\Inventory\InventoryOpenedEvent;
use Bedriox\Api\Event\Inventory\InventoryOpenEvent;
use Bedriox\Api\Event\Inventory\InventoryTransactionCommittedEvent;
use Bedriox\Api\Event\Inventory\InventoryTransactionEvent;
use Bedriox\Api\Event\Player\PlayerAttackedEvent;
use Bedriox\Api\Event\Player\PlayerAttackEvent;
use Bedriox\Api\Event\Player\PlayerChatBroadcastEvent;
use Bedriox\Api\Event\Player\PlayerChatEvent;
use Bedriox\Api\Event\Player\PlayerCraftedItemEvent;
use Bedriox\Api\Event\Player\PlayerCraftItemEvent;
use Bedriox\Api\Event\Player\PlayerDamagedEvent;
use Bedriox\Api\Event\Player\PlayerDamageEvent;
use Bedriox\Api\Event\Player\PlayerDeathEvent;
use Bedriox\Api\Event\Player\PlayerDropItemEvent;
use Bedriox\Api\Event\Player\PlayerDroppedItemEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangedEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangeEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangedEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangeEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangedEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangeEvent;
use Bedriox\Api\Event\Player\PlayerItemBreakEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumedEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumeEvent;
use Bedriox\Api\Event\Player\PlayerItemDamageEvent;
use Bedriox\Api\Event\Player\PlayerItemUseCancelledEvent;
use Bedriox\Api\Event\Player\PlayerItemUsedEvent;
use Bedriox\Api\Event\Player\PlayerItemUseEvent;
use Bedriox\Api\Event\Player\PlayerJoinEvent;
use Bedriox\Api\Event\Player\PlayerKickEvent;
use Bedriox\Api\Event\Player\PlayerLoginEvent;
use Bedriox\Api\Event\Player\PlayerMissSwingEvent;
use Bedriox\Api\Event\Player\PlayerMovedEvent;
use Bedriox\Api\Event\Player\PlayerMoveEvent;
use Bedriox\Api\Event\Player\PlayerPickedUpItemEvent;
use Bedriox\Api\Event\Player\PlayerPickupItemEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Api\Event\Player\PlayerQuitEvent;
use Bedriox\Api\Event\Player\PlayerRegainedHealthEvent;
use Bedriox\Api\Event\Player\PlayerRegainHealthEvent;
use Bedriox\Api\Event\Player\PlayerRespawnedEvent;
use Bedriox\Api\Event\Player\PlayerRespawnEvent;
use Bedriox\Api\Event\Player\PlayerTeleportedEvent;
use Bedriox\Api\Event\Player\PlayerTeleportEvent;
use Bedriox\Api\Inventory\ConsumptionResult;
use Bedriox\Api\Inventory\Container;
use Bedriox\Api\Inventory\ContainerView;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\Inventory as ApiInventory;
use Bedriox\Api\Inventory\InventoryTransaction;
use Bedriox\Api\Inventory\ItemDamageCause;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Inventory\ItemUseCancellationReason as ApiItemUseCancellationReason;
use Bedriox\Api\Inventory\ItemUseKind;
use Bedriox\Api\Inventory\PlayerInventoryActions;
use Bedriox\Api\Player\FoodLevelChangeCause;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\HealthRegainCause as ApiHealthRegainCause;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\Player\PlayerActions;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Block as ApiBlock;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
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
    /**
     * @param null|Closure(string): PlayerConnection $playerConnections
     * @param null|Closure(string): PlayerActions    $playerActions
     * @param null|Closure(string): PlayerInventoryActions $playerInventoryActions
     * @param null|Closure(string): (Closure(ApiItemStack): int) $maximumStackSize
     * @param null|Closure(string): EffectActions $playerEffectActions
     * @param null|Closure(string): ?\Bedriox\Api\World\World $worldResolver
     */
    public function __construct(
        private EventDispatcher $events,
        private ?Closure $playerConnections = null,
        private ?Closure $worldResolver = null,
        private ?Closure $playerActions = null,
        private ?Closure $playerInventoryActions = null,
        private ?Closure $maximumStackSize = null,
        private ?Closure $playerEffectActions = null,
    ) {}

    /** @internal Dispatches lifecycle events that are owned outside the simulation. */
    public function dispatch(Event $event): Event
    {
        return $this->events->dispatch($event);
    }

    /** @param Closure(string): PlayerConnection $playerConnections */
    public function withPlayerConnections(Closure $playerConnections): self
    {
        return new self($this->events, $playerConnections, $this->worldResolver, $this->playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions);
    }

    /** @param Closure(string): ?\Bedriox\Api\World\World $worldResolver */
    public function withWorldResolver(Closure $worldResolver): self
    {
        return new self($this->events, $this->playerConnections, $worldResolver, $this->playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions);
    }

    /** @param Closure(string): PlayerActions $playerActions */
    public function withPlayerActions(Closure $playerActions): self
    {
        return new self($this->events, $this->playerConnections, $this->worldResolver, $playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions);
    }

    /**
     * @param Closure(string): PlayerInventoryActions $playerInventoryActions
     * @param Closure(string): (Closure(ApiItemStack): int) $maximumStackSize
     */
    public function withPlayerInventoryActions(
        Closure $playerInventoryActions,
        Closure $maximumStackSize,
    ): self {
        return new self(
            $this->events,
            $this->playerConnections,
            $this->worldResolver,
            $this->playerActions,
            $playerInventoryActions,
            $maximumStackSize,
            $this->playerEffectActions,
        );
    }

    /** @param Closure(string): EffectActions $playerEffectActions */
    public function withPlayerEffectActions(Closure $playerEffectActions): self
    {
        return new self(
            $this->events,
            $this->playerConnections,
            $this->worldResolver,
            $this->playerActions,
            $this->playerInventoryActions,
            $this->maximumStackSize,
            $playerEffectActions,
        );
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

    public function allowEntitySpawn(ApiEntity $entity, SpawnCause $cause): bool
    {
        $event = new EntitySpawnEvent($entity, $cause);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function entitySpawned(ApiEntity $entity, SpawnCause $cause): void
    {
        $this->events->dispatch(new EntitySpawnedEvent($entity, $cause));
    }

    public function entityDamage(
        ApiLivingEntity $entity,
        EntityDamageCause $cause,
        float $damage,
        ApiEntity|ApiPlayer|null $damager = null,
    ): ?EntityDamageEvent {
        $event = $damager === null
            ? new EntityDamageEvent($entity, $cause, $damage)
            : new EntityDamageByEntityEvent($damager, $entity, $cause, $damage);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function combust(
        ApiLivingEntity $entity,
        EntityCombustionCause $cause,
        int $durationTicks,
    ): ?EntityCombustEvent {
        $event = new EntityCombustEvent($entity, $cause, $durationTicks);
        $this->events->dispatch($event);

        return $event->isCancelled() || $event->durationTicks() === 0 ? null : $event;
    }

    /**
     * @param list<\Bedriox\Api\Inventory\ItemStack> $drops
     * @return list<\Bedriox\Api\Inventory\ItemStack>
     */
    public function entityDied(ApiLivingEntity $entity, ?EntityDamageEvent $lastDamage, array $drops = []): array
    {
        $event = new EntityDeathEvent($entity, $lastDamage, $drops);
        $this->events->dispatch($event);

        return $event->getDrops();
    }

    public function entityEquipmentChange(
        ApiLivingEntity $entity,
        EquipmentSlot $slot,
        ?ApiItemStack $previous,
        float $previousDropChance,
        ?ApiItemStack $item,
        float $dropChance,
    ): ?EntityEquipmentChangeEvent {
        $event = new EntityEquipmentChangeEvent(
            $entity,
            $slot,
            $previous,
            $item,
            $previousDropChance,
            $dropChance,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function entityEquipmentChanged(
        ApiLivingEntity $entity,
        EquipmentSlot $slot,
        ?ApiItemStack $previous,
        float $previousDropChance,
        ?ApiItemStack $item,
        float $dropChance,
    ): void {
        $this->events->dispatch(new EntityEquipmentChangedEvent(
            $entity,
            $slot,
            $previous,
            $item,
            $previousDropChance,
            $dropChance,
        ));
    }

    public function allowEntityInteract(
        Player $player,
        ApiEntity $entity,
        EntityInteractionType $interaction,
    ): bool {
        $event = new EntityInteractEvent($this->playerView($player), $entity, $interaction);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function allowEntityDespawn(ApiEntity $entity, string $reason): bool
    {
        $event = new EntityDespawnEvent($entity, $reason);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function entityDespawned(ApiEntity $entity, string $reason): void
    {
        $this->events->dispatch(new EntityDespawnedEvent($entity, $reason));
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
        if (!$this->events->hasListenersFor(PlayerMoveEvent::class)) {
            return true;
        }
        $event = new PlayerMoveEvent(
            $this->playerView($player),
            $this->positionFor($player, $player->movement->position),
            $this->positionFor($player, $target),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function moved(Player $player): void
    {
        if (!$this->events->hasListenersFor(PlayerMovedEvent::class)) {
            return;
        }
        $this->events->dispatch(new PlayerMovedEvent($this->playerView($player)));
    }

    public function teleport(Player $player, Position $destination, float $yaw, float $pitch): ?PlayerTeleportDecision
    {
        $from = $this->positionFor($player, $player->movement->position);
        $event = new PlayerTeleportEvent(
            $this->playerView($player),
            $from,
            $this->positionFor($player, $destination, $yaw, $pitch),
            $yaw,
            $pitch,
        );
        $this->events->dispatch($event);
        if ($event->isCancelled()) {
            return null;
        }
        $final = $event->destination();

        return new PlayerTeleportDecision(
            new Position($final->x, $final->y, $final->z),
            $event->yaw(),
            $event->pitch(),
        );
    }

    public function teleported(Player $player, Position $from): void
    {
        $snapshot = $this->playerView($player);
        $this->events->dispatch(new PlayerTeleportedEvent(
            $snapshot,
            $this->positionFor($player, $from),
            $snapshot->position,
            $snapshot->yaw,
            $snapshot->pitch,
        ));
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

    public function allowItemUse(Player $player, InventoryStack $item, ItemUseKind $kind, int $requiredTicks): bool
    {
        $event = new PlayerItemUseEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $kind,
            EquipmentSlot::MAIN_HAND,
            $requiredTicks,
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function itemUseCancelled(
        Player $player,
        InventoryStack $item,
        ItemUseKind $kind,
        ItemUseCancellationReason $reason,
        int $elapsedTicks,
    ): void {
        $this->events->dispatch(new PlayerItemUseCancelledEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $kind,
            EquipmentSlot::MAIN_HAND,
            self::itemUseCancellationReason($reason),
            max(0, min(1_200, $elapsedTicks)),
        ));
    }

    public function consume(
        Player $player,
        InventoryStack $item,
        Nutrition $nutrition,
        ConsumptionResult $result,
    ): ?ConsumptionResult {
        $event = new PlayerItemConsumeEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $nutrition,
            $result,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->result();
    }

    public function consumed(
        Player $player,
        InventoryStack $item,
        Nutrition $previous,
        Nutrition $nutrition,
        ConsumptionResult $result,
    ): void {
        $this->events->dispatch(new PlayerItemConsumedEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $previous,
            $nutrition,
            $result,
        ));
    }

    public function itemUsed(Player $player, InventoryStack $item, ItemUseKind $kind, int $elapsedTicks): void
    {
        $this->events->dispatch(new PlayerItemUsedEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $kind,
            EquipmentSlot::MAIN_HAND,
            max(0, min(1_200, $elapsedTicks)),
        ));
    }

    public function nutritionChange(
        Player $player,
        Nutrition $previous,
        Nutrition $nutrition,
        FoodLevelChangeCause $cause,
    ): ?Nutrition {
        if (!$this->events->hasListenersFor(PlayerFoodLevelChangeEvent::class)) {
            return $nutrition;
        }
        $event = new PlayerFoodLevelChangeEvent($this->playerView($player), $previous, $nutrition, $cause);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->nutrition();
    }

    public function nutritionChanged(
        Player $player,
        Nutrition $previous,
        Nutrition $nutrition,
        FoodLevelChangeCause $cause,
    ): void {
        if (!$this->events->hasListenersFor(PlayerFoodLevelChangedEvent::class)) {
            return;
        }
        $this->events->dispatch(new PlayerFoodLevelChangedEvent(
            $this->playerView($player),
            $previous,
            $nutrition,
            $cause,
        ));
    }

    public function hasNutritionListeners(): bool
    {
        return $this->events->hasListenersFor(PlayerFoodLevelChangeEvent::class)
            || $this->events->hasListenersFor(PlayerFoodLevelChangedEvent::class);
    }

    public function regainHealth(Player $player, ApiHealthRegainCause $cause, float $amount): ?float
    {
        $event = new PlayerRegainHealthEvent($this->playerView($player), $cause, $amount);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->amount();
    }

    public function addEffect(Player|AbstractLivingEntity $entity, EffectInstance $effect, EffectCause $cause): ?EffectInstance
    {
        $event = new EntityEffectAddEvent(
            $this->effectEntity($entity),
            $effect,
            $cause,
            $entity instanceof Player
                ? $entity->effects->get($effect->type)
                : $entity->effectState()->get($effect->type),
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->effect();
    }

    public function effectAdded(
        Player|AbstractLivingEntity $entity,
        EffectInstance $effect,
        EffectCause $cause,
        ?EffectInstance $previous,
    ): void {
        $this->events->dispatch(new EntityEffectAddedEvent(
            $this->effectEntity($entity),
            $effect,
            $cause,
            $previous,
        ));
    }

    public function allowEffectRemoval(Player|AbstractLivingEntity $entity, EffectInstance $effect, EffectCause $cause): bool
    {
        $event = new EntityEffectRemoveEvent($this->effectEntity($entity), $effect, $cause);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function effectRemoved(
        Player|AbstractLivingEntity $entity,
        EffectInstance $effect,
        EffectCause $cause,
        ?EffectInstance $promoted = null,
    ): void {
        $this->events->dispatch(new EntityEffectRemovedEvent(
            $this->effectEntity($entity),
            $effect,
            $cause,
            $promoted,
        ));
    }

    private function effectEntity(Player|AbstractLivingEntity $entity): ApiPlayer|ApiLivingEntity
    {
        return $entity instanceof Player ? $this->playerView($entity) : $entity;
    }

    public function regainedHealth(Player $player, ApiHealthRegainCause $cause, float $amount): void
    {
        $this->events->dispatch(new PlayerRegainedHealthEvent($this->playerView($player), $cause, $amount));
    }

    public function equipmentChange(
        Player $player,
        EquipmentSlot $slot,
        ?InventoryStack $previous,
        ?InventoryStack $item,
    ): ?PlayerEquipmentChangeEvent {
        $event = new PlayerEquipmentChangeEvent(
            $this->playerView($player),
            $slot,
            self::item($previous),
            self::item($item),
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function equipmentChanged(
        Player $player,
        EquipmentSlot $slot,
        ?InventoryStack $previous,
        ?InventoryStack $item,
    ): void {
        $this->events->dispatch(new PlayerEquipmentChangedEvent(
            $this->playerView($player),
            $slot,
            self::item($previous),
            self::item($item),
        ));
    }

    public function itemDamage(
        Player $player,
        InventoryStack $item,
        ItemDamageCause $cause,
        EquipmentSlot $slot,
        int $damage,
    ): ?int {
        $event = new PlayerItemDamageEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $cause,
            $slot,
            $damage,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->damage();
    }

    public function itemBroken(
        Player $player,
        InventoryStack $item,
        ItemDamageCause $cause,
        EquipmentSlot $slot,
    ): void {
        $this->events->dispatch(new PlayerItemBreakEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $cause,
            $slot,
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
        $event = new PlayerRespawnEvent($this->playerView($player), $this->positionFor($player, $position));
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

    public function allowMissSwing(Player $player): bool
    {
        $event = new PlayerMissSwingEvent($this->playerView($player));
        $this->events->dispatch($event);

        return !$event->isCancelled();
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

    public function allowChestPair(Player $player, BlockPosition $left, BlockPosition $right, string $identifier): bool
    {
        $event = new ChestPairEvent(
            self::block($left, $identifier),
            self::block($right, $identifier),
            $this->playerView($player),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function chestPaired(Player $player, BlockPosition $left, BlockPosition $right, string $identifier): void
    {
        $this->events->dispatch(new ChestPairedEvent(
            self::block($left, $identifier),
            self::block($right, $identifier),
            $this->playerView($player),
        ));
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

    public function allowContainerOpen(Player $player, ContainerView $container, ?Container $handle = null): bool
    {
        $event = new InventoryOpenEvent($this->playerView($player), $container, $handle);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function containerOpened(Player $player, ContainerView $container, ?Container $handle = null): void
    {
        $this->events->dispatch(new InventoryOpenedEvent($this->playerView($player), $container, $handle));
    }

    public function containerClosed(
        Player $player,
        ContainerView $container,
        InventoryCloseReason $reason,
        ?Container $handle = null,
    ): void {
        $this->events->dispatch(new InventoryCloseEvent($this->playerView($player), $container, $reason, $handle));
    }

    public function allowContainerTransaction(Player $player, InventoryTransaction $transaction): bool
    {
        $event = new InventoryTransactionEvent($this->playerView($player), $transaction);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function containerTransactionCommitted(Player $player, InventoryTransaction $transaction): void
    {
        $this->events->dispatch(new InventoryTransactionCommittedEvent($this->playerView($player), $transaction));
    }

    public function pickupItem(Player $player, InventoryStack $stack): ?int
    {
        $event = new PlayerPickupItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
            $stack->count,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->count();
    }

    public function pickedUpItem(Player $player, InventoryStack $stack): void
    {
        $this->events->dispatch(new PlayerPickedUpItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
        ));
    }

    public function dropItem(Player $player, InventoryStack $stack): ?int
    {
        $event = new PlayerDropItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
            $stack->count,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->count();
    }

    public function droppedItem(Player $player, InventoryStack $stack): void
    {
        $this->events->dispatch(new PlayerDroppedItemEvent(
            $this->playerView($player),
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
        ));
    }

    /**
     * @param list<ApiItemStack> $consumedInputs
     * @param list<ApiItemStack> $outputs
     * @param list<ApiItemStack> $remainders
     * @return null|list<ApiItemStack>
     */
    public function craft(
        Player $player,
        ApiCraftingRecipe $recipe,
        ApiCraftingGrid $grid,
        int $craftCount,
        array $consumedInputs,
        array $outputs,
        array $remainders = [],
    ): ?array {
        $event = new PlayerCraftItemEvent(
            $this->playerView($player),
            $recipe,
            $grid,
            $craftCount,
            $consumedInputs,
            $outputs,
            $remainders,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->outputs();
    }

    /**
     * @param list<ApiItemStack> $consumedInputs
     * @param list<ApiItemStack> $outputs
     * @param list<ApiItemStack> $remainders
     * @param list<ApiItemStack> $overflow
     */
    public function crafted(
        Player $player,
        ApiCraftingRecipe $recipe,
        ApiCraftingGrid $grid,
        int $craftCount,
        array $consumedInputs,
        array $outputs,
        array $remainders = [],
        array $overflow = [],
    ): void {
        $this->events->dispatch(new PlayerCraftedItemEvent(
            $this->playerView($player),
            $recipe,
            $grid,
            $craftCount,
            $consumedInputs,
            $outputs,
            $remainders,
            $overflow,
        ));
    }

    /** @return null|list<ApiItemStack|null> */
    public function brew(BrewingStandBlockEntity $state): ?array
    {
        $event = new BrewingEvent(
            new ApiBlockPosition($state->position->x, $state->position->y, $state->position->z),
            self::brewingResults($state),
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->results();
    }

    public function brewed(BrewingStandBlockEntity $state): void
    {
        $this->events->dispatch(new BrewedEvent(
            new ApiBlockPosition($state->position->x, $state->position->y, $state->position->z),
            self::brewingResults($state),
        ));
    }

    public function brewingFuel(BrewingStandBlockEntity $state, int $uses): ?int
    {
        $fuel = $state->inventory->stackAt(BrewingStandBlockEntity::SLOT_FUEL);
        if ($fuel === null) {
            return null;
        }
        $event = new BrewingFuelConsumeEvent(
            new ApiBlockPosition($state->position->x, $state->position->y, $state->position->z),
            new ApiItemStack($fuel->identifier, 1, $fuel->damage, $fuel->nbt, $fuel->auxValue),
            $uses,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->fuelUses();
    }

    public function brewingFuelConsumed(BrewingStandBlockEntity $before, int $uses): void
    {
        $fuel = $before->inventory->stackAt(BrewingStandBlockEntity::SLOT_FUEL);
        if ($fuel === null) {
            return;
        }
        $this->events->dispatch(new BrewingFuelConsumedEvent(
            new ApiBlockPosition($before->position->x, $before->position->y, $before->position->z),
            new ApiItemStack($fuel->identifier, 1, $fuel->damage, $fuel->nbt, $fuel->auxValue),
            $uses,
        ));
    }

    public function potionProjectileImpact(\Bedriox\Server\Gameplay\Potion\PotionProjectile $projectile): bool
    {
        $event = new PotionProjectileImpactEvent(
            $projectile->runtimeEntityId,
            $projectile->ownerUuid,
            $projectile->potionType,
            self::position($projectile->position),
            $projectile->lingering,
            $projectile->tippedArrow,
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function potionProjectileImpacted(\Bedriox\Server\Gameplay\Potion\PotionProjectile $projectile): void
    {
        $this->events->dispatch(new PotionProjectileImpactedEvent(
            $projectile->runtimeEntityId,
            $projectile->ownerUuid,
            $projectile->potionType,
            self::position($projectile->position),
            $projectile->lingering,
            $projectile->tippedArrow,
        ));
    }

    /** @return list<ApiItemStack|null> */
    private static function brewingResults(BrewingStandBlockEntity $state): array
    {
        $results = [];
        foreach ([
            BrewingStandBlockEntity::SLOT_BOTTLE_LEFT,
            BrewingStandBlockEntity::SLOT_BOTTLE_MIDDLE,
            BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT,
        ] as $slot) {
            $stack = $state->inventory->stackAt($slot);
            $results[] = $stack === null
                ? null
                : new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue);
        }

        return $results;
    }

    public function playerView(Player $player, ?PlayerInventory $inventory = null): ApiPlayer
    {
        $snapshot = $player->snapshot();

        $connection = $this->playerConnections === null
            ? PlayerConnection::disconnected()
            : ($this->playerConnections)($snapshot->identity);
        $view = new ApiPlayer(
            $snapshot->displayName,
            $snapshot->identity,
            $this->positionFor($player, $snapshot->position, $snapshot->yaw, $snapshot->pitch),
            $snapshot->yaw,
            $snapshot->pitch,
            $snapshot->sneaking,
            $snapshot->sprinting,
            self::inventory($inventory ?? $player->inventory),
            $player->vitals->health,
            PlayerVitals::MAX_HEALTH,
            $player->vitals->isAlive(),
            $player->gameMode(),
            $connection,
            self::nutrition($player),
            armorInventory: self::armorInventory($inventory ?? $player->inventory),
            offHandItem: self::item(($inventory ?? $player->inventory)->offhandStack()),
            effects: $player->effects->snapshot(),
        );

        return $this->playerActions === null || $this->playerInventoryActions === null || $this->maximumStackSize === null
            ? $view
            : $view->withRuntime(
                $connection,
                ($this->playerActions)($snapshot->identity),
                ($this->playerInventoryActions)($snapshot->identity),
                ($this->maximumStackSize)($snapshot->identity),
                $this->playerEffectActions === null ? null : ($this->playerEffectActions)($snapshot->identity),
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
            self::nutrition($player),
            armorInventory: self::armorInventory($inventory ?? $player->inventory),
            offHandItem: self::item(($inventory ?? $player->inventory)->offhandStack()),
            effects: $player->effects->snapshot(),
        );
    }

    /** @return array<string, ApiItemStack|null> */
    private static function armorInventory(PlayerInventory $inventory): array
    {
        return [
            EquipmentSlot::HEAD->value => self::item($inventory->armorStack(0)),
            EquipmentSlot::CHEST->value => self::item($inventory->armorStack(1)),
            EquipmentSlot::LEGS->value => self::item($inventory->armorStack(2)),
            EquipmentSlot::FEET->value => self::item($inventory->armorStack(3)),
        ];
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
        return $stack === null
            ? null
            : new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue);
    }

    private static function itemRequired(InventoryStack $stack): ApiItemStack
    {
        return self::item($stack)
            ?? throw new \LogicException('A required inventory stack could not be projected.');
    }

    public static function nutrition(Player $player): Nutrition
    {
        return new Nutrition(
            (int) $player->vitals->food,
            $player->vitals->saturation,
            $player->vitals->exhaustion,
        );
    }

    private static function itemUseCancellationReason(ItemUseCancellationReason $reason): ApiItemUseCancellationReason
    {
        return match ($reason) {
            ItemUseCancellationReason::RELEASED, ItemUseCancellationReason::TOO_EARLY =>
                ApiItemUseCancellationReason::RELEASED_EARLY,
            ItemUseCancellationReason::HELD_ITEM_CHANGED => ApiItemUseCancellationReason::ITEM_CHANGED,
            ItemUseCancellationReason::DEATH => ApiItemUseCancellationReason::PLAYER_DIED,
            ItemUseCancellationReason::TELEPORT => ApiItemUseCancellationReason::TELEPORTED,
            ItemUseCancellationReason::GAME_MODE_CHANGED => ApiItemUseCancellationReason::GAME_MODE_CHANGED,
            ItemUseCancellationReason::DISCONNECTED => ApiItemUseCancellationReason::DISCONNECTED,
            ItemUseCancellationReason::TIMED_OUT => ApiItemUseCancellationReason::INVALIDATED,
            ItemUseCancellationReason::PLUGIN => ApiItemUseCancellationReason::PLUGIN,
        };
    }

    private static function position(Position $position): ApiPosition
    {
        return new ApiPosition($position->x, $position->y, $position->z);
    }

    private function positionFor(
        Player $player,
        Position $position,
        ?float $yaw = null,
        ?float $pitch = null,
    ): ApiPosition {
        $world = $this->worldResolver === null ? null : ($this->worldResolver)($player->worldName());

        return new ApiPosition(
            $position->x,
            $position->y,
            $position->z,
            $yaw ?? $player->movement->yaw,
            $pitch ?? $player->movement->pitch,
            $world,
        );
    }

    private static function block(BlockPosition $position, string $identifier): ApiBlock
    {
        return new ApiBlock(new ApiBlockPosition($position->x, $position->y, $position->z), $identifier);
    }
}
