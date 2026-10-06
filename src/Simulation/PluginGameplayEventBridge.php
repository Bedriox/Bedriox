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
use Bedriox\Api\Encounter\EnderDragonEncounter;
use Bedriox\Api\Encounter\EnderDragonPhase;
use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\Capability\Shearable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\EntityHealthRegainCause;
use Bedriox\Api\Entity\EntityInteractionType;
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\KnockbackCause;
use Bedriox\Api\Entity\KnockbackVector;
use Bedriox\Api\Entity\LivingEntity as ApiLivingEntity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\EntityBlockChangeReason;
use Bedriox\Api\Entity\Value\EntityTransformReason;
use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vector3;
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
use Bedriox\Api\Event\Block\EndPortalActivatedEvent;
use Bedriox\Api\Event\Block\EndPortalActivateEvent;
use Bedriox\Api\Event\Encounter\EnderDragonEncounterCompletedEvent;
use Bedriox\Api\Event\Encounter\EnderDragonParticipantChangedEvent;
use Bedriox\Api\Event\Encounter\EnderDragonParticipantChangeEvent;
use Bedriox\Api\Event\Encounter\EnderDragonPhaseChangedEvent;
use Bedriox\Api\Event\Encounter\EnderDragonPhaseChangeEvent;
use Bedriox\Api\Event\Encounter\EnderDragonRespawnedEvent;
use Bedriox\Api\Event\Encounter\EnderDragonRespawnEvent;
use Bedriox\Api\Event\Encounter\EnderDragonRewardedEvent;
use Bedriox\Api\Event\Encounter\EnderDragonRewardEvent;
use Bedriox\Api\Event\Encounter\EndGatewayCreatedEvent;
use Bedriox\Api\Event\Encounter\EndGatewayCreateEvent;
use Bedriox\Api\Event\Entity\ActorKnockbackEvent;
use Bedriox\Api\Event\Entity\ActorKnockedBackEvent;
use Bedriox\Api\Event\Entity\EntityBlockChangedEvent;
use Bedriox\Api\Event\Entity\EntityBlockChangeEvent;
use Bedriox\Api\Event\Entity\EntityBredEvent;
use Bedriox\Api\Event\Entity\EntityBreedEvent;
use Bedriox\Api\Event\Entity\EntityCombustEvent;
use Bedriox\Api\Event\Entity\EntityDamageByEntityEvent;
use Bedriox\Api\Event\Entity\EntityDamageEvent;
use Bedriox\Api\Event\Entity\EntityDeathEvent;
use Bedriox\Api\Event\Entity\EntityDespawnedEvent;
use Bedriox\Api\Event\Entity\EntityDespawnEvent;
use Bedriox\Api\Event\Entity\EntityDismountedEvent;
use Bedriox\Api\Event\Entity\EntityDismountEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddedEvent;
use Bedriox\Api\Event\Entity\EntityEffectAddEvent;
use Bedriox\Api\Event\Entity\EntityEffectRemovedEvent;
use Bedriox\Api\Event\Entity\EntityEffectRemoveEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangedEvent;
use Bedriox\Api\Event\Entity\EntityEquipmentChangeEvent;
use Bedriox\Api\Event\Entity\EntityExplodedEvent;
use Bedriox\Api\Event\Entity\EntityExplosionPrimeEvent;
use Bedriox\Api\Event\Entity\EntityInteractedEvent;
use Bedriox\Api\Event\Entity\EntityInteractEvent;
use Bedriox\Api\Event\Entity\EntityMountedEvent;
use Bedriox\Api\Event\Entity\EntityMountEvent;
use Bedriox\Api\Event\Entity\EntityPickedUpItemEvent;
use Bedriox\Api\Event\Entity\EntityPickupItemEvent;
use Bedriox\Api\Event\Entity\EntityRegainedHealthEvent;
use Bedriox\Api\Event\Entity\EntityRegainHealthEvent;
use Bedriox\Api\Event\Entity\EntityShearedEvent;
use Bedriox\Api\Event\Entity\EntityShearEvent;
use Bedriox\Api\Event\Entity\EntitySpawnedEvent;
use Bedriox\Api\Event\Entity\EntitySpawnEvent;
use Bedriox\Api\Event\Entity\EntitySplitEvent;
use Bedriox\Api\Event\Entity\EntityTamedEvent;
use Bedriox\Api\Event\Entity\EntityTameEvent;
use Bedriox\Api\Event\Entity\EntityTargetChangedEvent;
use Bedriox\Api\Event\Entity\EntityTargetEvent;
use Bedriox\Api\Event\Entity\EntityTransformedEvent;
use Bedriox\Api\Event\Entity\EntityTransformEvent;
use Bedriox\Api\Event\Entity\ExperienceOrbSpawnedEvent;
use Bedriox\Api\Event\Entity\ExperienceOrbSpawnEvent;
use Bedriox\Api\Event\Entity\PiglinBarteredEvent;
use Bedriox\Api\Event\Entity\PiglinBarterEvent;
use Bedriox\Api\Event\Entity\PotionProjectileImpactedEvent;
use Bedriox\Api\Event\Entity\PotionProjectileImpactEvent;
use Bedriox\Api\Event\Entity\ProjectileImpactedEvent;
use Bedriox\Api\Event\Entity\ProjectileImpactEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchedEvent;
use Bedriox\Api\Event\Entity\ProjectileLaunchEvent;
use Bedriox\Api\Event\Entity\ProjectileReflectedEvent;
use Bedriox\Api\Event\Entity\ProjectileReflectEvent;
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
use Bedriox\Api\Event\Player\PlayerEnchantedItemEvent;
use Bedriox\Api\Event\Player\PlayerEnchantItemEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangedEvent;
use Bedriox\Api\Event\Player\PlayerEquipmentChangeEvent;
use Bedriox\Api\Event\Player\PlayerExperienceChangedEvent as ApiPlayerExperienceChangedEvent;
use Bedriox\Api\Event\Player\PlayerExperienceChangeEvent;
use Bedriox\Api\Event\Player\PlayerFishedEvent;
use Bedriox\Api\Event\Player\PlayerFishEvent;
use Bedriox\Api\Event\Player\PlayerFishState;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangedEvent;
use Bedriox\Api\Event\Player\PlayerFoodLevelChangeEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangedEvent;
use Bedriox\Api\Event\Player\PlayerGameModeChangeEvent;
use Bedriox\Api\Event\Player\PlayerItemBreakEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumedEvent;
use Bedriox\Api\Event\Player\PlayerItemConsumeEvent;
use Bedriox\Api\Event\Player\PlayerItemDamageEvent;
use Bedriox\Api\Event\Player\PlayerItemMendedEvent;
use Bedriox\Api\Event\Player\PlayerItemMendEvent;
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
use Bedriox\Api\Event\Player\PlayerPortalTravelEvent;
use Bedriox\Api\Event\Player\PlayerPortalTravelledEvent;
use Bedriox\Api\Event\Player\PlayerPreJoinEvent;
use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\Api\Event\Player\PlayerQuitEvent;
use Bedriox\Api\Event\Player\PlayerRegainedHealthEvent;
use Bedriox\Api\Event\Player\PlayerRegainHealthEvent;
use Bedriox\Api\Event\Player\PlayerRespawnedEvent;
use Bedriox\Api\Event\Player\PlayerRespawnEvent;
use Bedriox\Api\Event\Player\PlayerTeleportedEvent;
use Bedriox\Api\Event\Player\PlayerTeleportEvent;
use Bedriox\Api\Event\Processing\AnvilProcessedEvent;
use Bedriox\Api\Event\Processing\AnvilProcessEvent;
use Bedriox\Api\Event\Processing\CampfireCookedEvent;
use Bedriox\Api\Event\Processing\CampfireCookEvent;
use Bedriox\Api\Event\Processing\CampfireCookingStartedEvent;
use Bedriox\Api\Event\Processing\CampfireCookStartEvent;
use Bedriox\Api\Event\Processing\CartographyProcessedEvent;
use Bedriox\Api\Event\Processing\CartographyProcessEvent;
use Bedriox\Api\Event\Processing\CauldronChangedEvent;
use Bedriox\Api\Event\Processing\CauldronChangeEvent;
use Bedriox\Api\Event\Processing\ComposterChangedEvent;
use Bedriox\Api\Event\Processing\ComposterChangeEvent;
use Bedriox\Api\Event\Processing\EnchantingOptionsEvent;
use Bedriox\Api\Event\Processing\EnchantingOptionsGeneratedEvent;
use Bedriox\Api\Event\Processing\FurnaceExtractedEvent;
use Bedriox\Api\Event\Processing\FurnaceExtractEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumedEvent;
use Bedriox\Api\Event\Processing\FurnaceFuelConsumeEvent;
use Bedriox\Api\Event\Processing\FurnaceSmeltedEvent;
use Bedriox\Api\Event\Processing\FurnaceSmeltEvent;
use Bedriox\Api\Event\Processing\FurnaceStartedSmeltingEvent;
use Bedriox\Api\Event\Processing\FurnaceStartSmeltEvent;
use Bedriox\Api\Event\Processing\GrindstoneProcessedEvent;
use Bedriox\Api\Event\Processing\GrindstoneProcessEvent;
use Bedriox\Api\Event\Processing\LoomProcessedEvent;
use Bedriox\Api\Event\Processing\LoomProcessEvent;
use Bedriox\Api\Event\Processing\SmithingProcessedEvent;
use Bedriox\Api\Event\Processing\SmithingProcessEvent;
use Bedriox\Api\Event\Processing\StonecutterProcessedEvent;
use Bedriox\Api\Event\Processing\StonecutterProcessEvent;
use Bedriox\Api\Event\Vehicle\VehicleControlEvent;
use Bedriox\Api\Event\Vehicle\VehicleControlledEvent;
use Bedriox\Api\Event\World\WeatherChangeCause;
use Bedriox\Api\Event\World\WeatherChangedEvent;
use Bedriox\Api\Event\World\WeatherChangeEvent;
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
use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\ExperienceSnapshot;
use Bedriox\Api\Player\FoodLevelChangeCause;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\HealthRegainCause as ApiHealthRegainCause;
use Bedriox\Api\Player\Nutrition;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\Player\PlayerActions;
use Bedriox\Api\Player\PlayerConnection;
use Bedriox\Api\Processing\CartographyOperation;
use Bedriox\Api\Processing\CauldronChangeCause;
use Bedriox\Api\Processing\CauldronContentType;
use Bedriox\Api\Processing\ComposterChangeCause;
use Bedriox\Api\Processing\EnchantingOption;
use Bedriox\Api\Processing\FurnaceFuelCause;
use Bedriox\Api\Processing\FurnaceType;
use Bedriox\Api\Processing\SmithingRecipeType;
use Bedriox\Api\Processing\StationProcessCause;
use Bedriox\Api\TextFormat;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\Block as ApiBlock;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\PortalType;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
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
     * @param null|Closure(string): (Closure(int, ExperienceChangeCause): void) $playerExperienceActions
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
        private ?Closure $playerExperienceActions = null,
    ) {}

    /** @internal Dispatches lifecycle events that are owned outside the simulation. */
    public function dispatch(Event $event): Event
    {
        return $this->events->dispatch($event);
    }

    public function enderDragonPhaseChange(
        EnderDragonEncounter $encounter,
        EnderDragonPhase $from,
        EnderDragonPhase $to,
    ): ?EnderDragonPhase {
        $event = new EnderDragonPhaseChangeEvent($encounter, $from, $to);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->to();
    }

    public function enderDragonPhaseChanged(
        EnderDragonEncounter $encounter,
        EnderDragonPhase $from,
        EnderDragonPhase $to,
    ): void {
        $this->events->dispatch(new EnderDragonPhaseChangedEvent($encounter, $from, $to));
    }

    public function enderDragonParticipantChange(
        EnderDragonEncounter $encounter,
        Player $player,
        bool $joining,
    ): bool {
        $event = new EnderDragonParticipantChangeEvent($encounter, $this->playerView($player), $joining);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function enderDragonParticipantChanged(
        EnderDragonEncounter $encounter,
        Player $player,
        bool $joined,
    ): void {
        $this->events->dispatch(new EnderDragonParticipantChangedEvent(
            $encounter,
            $this->playerView($player),
            $joined,
        ));
    }

    public function enderDragonReward(
        EnderDragonEncounter $encounter,
        bool $firstVictory,
        int $experience,
    ): ?int {
        $event = new EnderDragonRewardEvent($encounter, $firstVictory, $experience);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->experience();
    }

    public function enderDragonRewarded(
        EnderDragonEncounter $encounter,
        bool $firstVictory,
        int $experience,
    ): void {
        $this->events->dispatch(new EnderDragonRewardedEvent($encounter, $firstVictory, $experience));
    }

    public function enderDragonRespawn(EnderDragonEncounter $encounter): bool
    {
        $event = new EnderDragonRespawnEvent($encounter);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function enderDragonRespawned(EnderDragonEncounter $encounter): void
    {
        $this->events->dispatch(new EnderDragonRespawnedEvent($encounter));
    }

    public function endGatewayCreate(
        EnderDragonEncounter $encounter,
        ApiBlockPosition $position,
        int $index,
    ): ?ApiBlockPosition {
        $event = new EndGatewayCreateEvent($encounter, $position, $index);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->position();
    }

    public function endGatewayCreated(
        EnderDragonEncounter $encounter,
        ApiBlockPosition $position,
        int $index,
    ): void {
        $this->events->dispatch(new EndGatewayCreatedEvent($encounter, $position, $index));
    }

    public function enderDragonEncounterCompleted(EnderDragonEncounter $encounter, bool $firstVictory): void
    {
        $this->events->dispatch(new EnderDragonEncounterCompletedEvent($encounter, $firstVictory));
    }

    public function breedEntities(Breedable $first, Breedable $second, int $experience): ?int
    {
        $event = new EntityBreedEvent($first, $second, $first->getType(), $experience);
        $this->events->dispatch($event);
        return $event->isCancelled() ? null : $event->getExperience();
    }

    public function entitiesBred(Breedable $first, Breedable $second, Breedable $child, int $experience): void
    {
        $this->events->dispatch(new EntityBredEvent($first, $second, $child, $experience));
    }

    public function allowTame(Tameable $entity, Player $player): bool
    {
        $event = new EntityTameEvent($entity, $this->playerView($player));
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function entityTamed(Tameable $entity, Player $player): void
    {
        $this->events->dispatch(new EntityTamedEvent($entity, $this->playerView($player)));
    }

    /** @param Closure(string): PlayerConnection $playerConnections */
    public function withPlayerConnections(Closure $playerConnections): self
    {
        return new self($this->events, $playerConnections, $this->worldResolver, $this->playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions, $this->playerExperienceActions);
    }

    /** @param Closure(string): ?\Bedriox\Api\World\World $worldResolver */
    public function withWorldResolver(Closure $worldResolver): self
    {
        return new self($this->events, $this->playerConnections, $worldResolver, $this->playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions, $this->playerExperienceActions);
    }

    /** @param Closure(string): PlayerActions $playerActions */
    public function withPlayerActions(Closure $playerActions): self
    {
        return new self($this->events, $this->playerConnections, $this->worldResolver, $playerActions, $this->playerInventoryActions, $this->maximumStackSize, $this->playerEffectActions, $this->playerExperienceActions);
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
            $this->playerExperienceActions,
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
            $this->playerExperienceActions,
        );
    }

    /** @param Closure(string): (Closure(int, ExperienceChangeCause): void) $playerExperienceActions */
    public function withPlayerExperienceActions(Closure $playerExperienceActions): self
    {
        return new self(
            $this->events,
            $this->playerConnections,
            $this->worldResolver,
            $this->playerActions,
            $this->playerInventoryActions,
            $this->maximumStackSize,
            $this->playerEffectActions,
            $playerExperienceActions,
        );
    }

    public function allowJoin(string $name, string $uuid): bool
    {
        $event = new PlayerPreJoinEvent($name, $uuid);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function joined(Player $player): string|TranslatableMessage|null
    {
        $view = $this->playerView($player);
        $event = new PlayerJoinEvent(
            $view,
            TextFormat::YELLOW . $view->name . ' joined the game' . TextFormat::RESET,
        );
        $this->events->dispatch($event);

        return $event->getJoinMessage();
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

    public function quit(
        Player $player,
        PlayerQuitCause $cause,
        string $reason,
        ?string $actor,
        string|TranslatableMessage|null $quitMessage,
    ): string|TranslatableMessage|null {
        $event = new PlayerQuitEvent($this->playerView($player), $cause, $reason, $actor, $quitMessage);
        $this->events->dispatch($event);

        return $event->getQuitMessage();
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

    public function allowMount(
        ApiEntity|ApiPlayer $passenger,
        ApiEntity $vehicle,
        MountSeat $seat,
        MountReason $reason,
    ): bool {
        $event = new EntityMountEvent($passenger, $vehicle, $seat, $reason);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function mounted(
        ApiEntity|ApiPlayer $passenger,
        ApiEntity $vehicle,
        MountSeat $seat,
        MountReason $reason,
    ): void {
        $this->events->dispatch(new EntityMountedEvent($passenger, $vehicle, $seat, $reason));
    }

    public function vehicleControl(
        Player $driver,
        \Bedriox\Api\Entity\Vanilla\Boat $vehicle,
        float $forward,
        float $strafe,
        float $yaw,
        bool $paddlingLeft,
        bool $paddlingRight,
    ): ?VehicleControlEvent {
        $event = new VehicleControlEvent(
            $this->playerView($driver),
            $vehicle,
            $forward,
            $strafe,
            $yaw,
            $paddlingLeft,
            $paddlingRight,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function vehicleControlled(
        Player $driver,
        \Bedriox\Api\Entity\Vanilla\Boat $vehicle,
        VehicleControlEvent $control,
    ): void {
        $this->events->dispatch(new VehicleControlledEvent(
            $this->playerView($driver),
            $vehicle,
            $control->forward(),
            $control->strafe(),
            $control->yaw(),
            $control->isPaddlingLeft(),
            $control->isPaddlingRight(),
        ));
    }

    public function allowDismount(
        ApiEntity|ApiPlayer $passenger,
        ApiEntity $vehicle,
        MountSeat $seat,
        MountReason $reason,
    ): bool {
        $event = new EntityDismountEvent($passenger, $vehicle, $seat, $reason);
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function dismounted(
        ApiEntity|ApiPlayer $passenger,
        ApiEntity $vehicle,
        MountSeat $seat,
        MountReason $reason,
    ): void {
        $this->events->dispatch(new EntityDismountedEvent($passenger, $vehicle, $seat, $reason));
    }

    public function primeExplosion(
        ApiEntity $entity,
        ApiPosition $position,
        float $radius,
        bool $breaksBlocks,
        float $fireChance,
    ): ?EntityExplosionPrimeEvent {
        $event = new EntityExplosionPrimeEvent($entity, $position, $radius, $breaksBlocks, $fireChance);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    /**
     * @param list<ApiBlockPosition> $affectedBlocks
     * @param list<ApiEntity|ApiPlayer> $affectedEntities
     */
    public function entityExploded(
        ApiEntity $entity,
        ApiPosition $position,
        float $radius,
        bool $brokeBlocks,
        float $fireChance,
        array $affectedBlocks,
        array $affectedEntities,
    ): void {
        $this->events->dispatch(new EntityExplodedEvent(
            $entity,
            $position,
            $radius,
            $brokeBlocks,
            $fireChance,
            $affectedBlocks,
            $affectedEntities,
        ));
    }

    public function transformEntity(
        ApiLivingEntity $entity,
        EntityType $targetType,
        EntityTransformReason $reason,
    ): ?EntityType {
        $event = new EntityTransformEvent($entity, $targetType, $reason);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->targetType();
    }

    public function entityTransformed(
        ApiLivingEntity $original,
        ApiLivingEntity $transformed,
        EntityTransformReason $reason,
    ): void {
        $this->events->dispatch(new EntityTransformedEvent($original, $transformed, $reason));
    }

    public function entityBlockChange(
        ApiEntity $entity,
        ApiBlock $from,
        ApiBlock $to,
        EntityBlockChangeReason $reason,
    ): ?ApiBlock {
        $event = new EntityBlockChangeEvent($entity, $from, $to, $reason);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->to();
    }

    public function entityBlockChanged(
        ApiEntity $entity,
        ApiBlock $from,
        ApiBlock $to,
        EntityBlockChangeReason $reason,
    ): void {
        $this->events->dispatch(new EntityBlockChangedEvent($entity, $from, $to, $reason));
    }

    public function splitEntity(ApiLivingEntity $entity, EntityType $childType, int $childCount): ?int
    {
        $event = new EntitySplitEvent($entity, $childType, $childCount);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->childCount();
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

    public function entityRegainHealth(
        ApiLivingEntity $entity,
        EntityHealthRegainCause $cause,
        float $amount,
    ): ?float {
        $event = new EntityRegainHealthEvent($entity, $cause, $amount);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->amount();
    }

    public function entityRegainedHealth(
        ApiLivingEntity $entity,
        EntityHealthRegainCause $cause,
        float $amount,
    ): void {
        $this->events->dispatch(new EntityRegainedHealthEvent($entity, $cause, $amount));
    }

    public function knockback(
        ApiPlayer|ApiLivingEntity $actor,
        ApiPlayer|ApiEntity|null $source,
        KnockbackCause $cause,
        KnockbackVector $motion,
    ): ?KnockbackVector {
        $event = new ActorKnockbackEvent($actor, $source, $cause, $motion);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->motion();
    }

    public function knockedBack(
        ApiPlayer|ApiLivingEntity $actor,
        ApiPlayer|ApiEntity|null $source,
        KnockbackCause $cause,
        KnockbackVector $motion,
    ): void {
        $this->events->dispatch(new ActorKnockedBackEvent($actor, $source, $cause, $motion));
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
        $held = $player->inventory->selectedStack();
        $event = new EntityInteractEvent(
            $this->playerView($player),
            $entity,
            $interaction,
            $held === null ? null : new ApiItemStack(
                $held->identifier,
                $held->count,
                $held->damage,
                $held->nbt,
                $held->auxValue,
            ),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function entityInteracted(
        Player $player,
        ApiEntity $entity,
        EntityInteractionType $interaction,
        ?InventoryStack $heldItem,
    ): void {
        $this->events->dispatch(new EntityInteractedEvent(
            $this->playerView($player),
            $entity,
            $interaction,
            $heldItem === null ? null : new ApiItemStack(
                $heldItem->identifier,
                $heldItem->count,
                $heldItem->damage,
                $heldItem->nbt,
                $heldItem->auxValue,
            ),
        ));
    }

    public function entityTarget(
        AbstractMobEntity $entity,
        ApiEntity|ApiPlayer|null $previousTarget,
        ApiEntity|ApiPlayer|null $target,
        EntityTargetReason $reason,
    ): EntityTargetEvent {
        $event = new EntityTargetEvent($entity, $previousTarget, $target, $reason);
        $this->events->dispatch($event);

        return $event;
    }

    public function entityTargetChanged(
        AbstractMobEntity $entity,
        ApiEntity|ApiPlayer|null $previousTarget,
        ApiEntity|ApiPlayer|null $target,
        EntityTargetReason $reason,
    ): void {
        $this->events->dispatch(new EntityTargetChangedEvent(
            $entity,
            $previousTarget,
            $target,
            $reason,
        ));
    }

    /**
     * @param list<ApiItemStack> $drops
     * @return null|list<ApiItemStack>
     */
    public function shearEntity(
        Player $player,
        Shearable $entity,
        InventoryStack $tool,
        array $drops,
    ): ?array {
        $event = new EntityShearEvent(
            $this->playerView($player),
            $entity,
            new ApiItemStack($tool->identifier, 1, $tool->damage, $tool->nbt, $tool->auxValue),
            $drops,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->getDrops();
    }

    /** @param list<ApiItemStack> $drops */
    public function entitySheared(
        Player $player,
        Shearable $entity,
        InventoryStack $tool,
        array $drops,
    ): void {
        $this->events->dispatch(new EntityShearedEvent(
            $this->playerView($player),
            $entity,
            new ApiItemStack($tool->identifier, 1, $tool->damage, $tool->nbt, $tool->auxValue),
            $drops,
        ));
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
    public function kick(ApiPlayer $player, \Bedriox\Api\Event\Player\PlayerKickCause $cause, string $reason, ?string $quitMessage, ?string $screenMessage, ?string $actor = null): ?array
    {
        $event = new PlayerKickEvent($player, $cause, $reason, $quitMessage, $screenMessage, $actor);
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

    public function portalTravel(
        Player $player,
        PortalType $portalType,
        WorldDimension $sourceDimension,
        WorldDimension $targetDimension,
        Position $destination,
    ): ?ApiPosition {
        $event = new PlayerPortalTravelEvent(
            $this->playerView($player),
            $portalType,
            $this->positionFor($player, $player->movement->position, dimension: $sourceDimension),
            $this->positionFor(
                $player,
                $destination,
                $player->movement->yaw,
                $player->movement->pitch,
                $targetDimension,
            ),
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->destination();
    }

    public function portalTravelled(
        Player $player,
        PortalType $portalType,
        ApiPosition $from,
        ApiPosition $destination,
    ): void {
        $this->events->dispatch(new PlayerPortalTravelledEvent(
            $this->playerView($player),
            $portalType,
            $from,
            $destination,
        ));
    }

    public function allowEndPortalActivation(Player $player, BlockPosition $center): bool
    {
        $event = new EndPortalActivateEvent(
            $this->playerView($player),
            new ApiBlockPosition($center->x, $center->y, $center->z, $player->dimension()),
        );
        $this->events->dispatch($event);

        return !$event->isCancelled();
    }

    public function endPortalActivated(Player $player, BlockPosition $center): void
    {
        $this->events->dispatch(new EndPortalActivatedEvent(
            $this->playerView($player),
            new ApiBlockPosition($center->x, $center->y, $center->z, $player->dimension()),
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

    public function itemMend(
        Player $player,
        InventoryStack $item,
        EquipmentSlot $slot,
        int $repairAmount,
        int $experienceCost,
    ): ?PlayerItemMendEvent {
        $event = new PlayerItemMendEvent(
            $this->playerView($player),
            self::itemRequired($item),
            $slot,
            $repairAmount,
            $experienceCost,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function itemMended(
        Player $player,
        InventoryStack $previous,
        InventoryStack $item,
        EquipmentSlot $slot,
        int $repairAmount,
        int $experienceCost,
    ): void {
        $this->events->dispatch(new PlayerItemMendedEvent(
            $this->playerView($player),
            self::itemRequired($previous),
            self::itemRequired($item),
            $slot,
            $repairAmount,
            $experienceCost,
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
            false,
            $killer === null ? null : $this->playerView($killer),
            $deathMessage,
            $deathScreenMessage,
        );
        $this->events->dispatch($event);

        return new DeathPresentation(
            $event->deathMessage(),
            $event->deathScreenMessage(),
            $event->keepsInventory(),
        );
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

    public function experienceChange(Player $player, int $totalPoints, ExperienceChangeCause $cause): ?ExperienceSnapshot
    {
        $event = new PlayerExperienceChangeEvent(
            $this->playerView($player),
            $player->experience->snapshot(),
            new ExperienceSnapshot($totalPoints),
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->experience();
    }

    public function experienceChanged(Player $player, ExperienceSnapshot $previous, ExperienceChangeCause $cause): void
    {
        $this->events->dispatch(new ApiPlayerExperienceChangedEvent(
            $this->playerView($player),
            $previous,
            $player->experience->snapshot(),
            $cause,
        ));
    }

    public function experienceOrbSpawn(Position $position, int $value): ?int
    {
        $event = new ExperienceOrbSpawnEvent(self::position($position), $value);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->value();
    }

    public function experienceOrbSpawned(int $runtimeEntityId, Position $position, int $value): void
    {
        $this->events->dispatch(new ExperienceOrbSpawnedEvent(
            $runtimeEntityId,
            self::position($position),
            $value,
        ));
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

    /**
     * @param list<ApiItemStack> $drops
     * @return null|list<ApiItemStack>
     */
    public function blockBreak(Player $player, BlockPosition $position, string $identifier, array $drops): ?array
    {
        $event = new BlockBreakEvent($this->playerView($player), self::block($position, $identifier), $drops);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->getDrops();
    }

    /** @param list<ApiItemStack> $drops */
    public function blockBroken(
        Player $player,
        BlockPosition $position,
        string $identifier,
        array $drops = [],
    ): void {
        $this->events->dispatch(new BlockBrokenEvent(
            $this->playerView($player),
            self::block($position, $identifier),
            $drops,
        ));
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

    public function allowWeatherChange(
        string $worldName,
        WeatherState $previous,
        WeatherState $weather,
        WeatherChangeCause $cause,
    ): ?WeatherState {
        $world = $this->worldResolver === null ? null : ($this->worldResolver)($worldName);
        if ($world === null) {
            return $weather;
        }
        $event = new WeatherChangeEvent($world, $previous, $weather, $cause);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->weather();
    }

    public function weatherChanged(
        string $worldName,
        WeatherState $previous,
        WeatherState $weather,
        WeatherChangeCause $cause,
    ): void {
        $world = $this->worldResolver === null ? null : ($this->worldResolver)($worldName);
        if ($world !== null) {
            $this->events->dispatch(new WeatherChangedEvent($world, $previous, $weather, $cause));
        }
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

    public function projectileReflect(
        Player $player,
        int $runtimeId,
        string $identifier,
        \Bedriox\Server\Entity\EntityMotion $motion,
    ): ?\Bedriox\Server\Entity\EntityMotion {
        $event = new ProjectileReflectEvent(
            $this->playerView($player),
            $runtimeId,
            $identifier,
            new Vector3($motion->x, $motion->y, $motion->z),
        );
        $this->events->dispatch($event);
        if ($event->isCancelled()) {
            return null;
        }
        $reflected = $event->motion();

        return new \Bedriox\Server\Entity\EntityMotion($reflected->x, $reflected->y, $reflected->z);
    }

    public function projectileReflected(
        Player $player,
        int $runtimeId,
        string $identifier,
        \Bedriox\Server\Entity\EntityMotion $motion,
    ): void {
        $this->events->dispatch(new ProjectileReflectedEvent(
            $this->playerView($player),
            $runtimeId,
            $identifier,
            new Vector3($motion->x, $motion->y, $motion->z),
        ));
    }

    public function entityPickupItem(ApiLivingEntity $entity, InventoryStack $stack): ?int
    {
        $event = new EntityPickupItemEvent(
            $entity,
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
            $stack->count,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event->count();
    }

    public function entityPickedUpItem(ApiLivingEntity $entity, InventoryStack $stack): void
    {
        $this->events->dispatch(new EntityPickedUpItemEvent(
            $entity,
            new ApiItemStack($stack->identifier, $stack->count, $stack->damage, $stack->nbt, $stack->auxValue),
        ));
    }

    /**
     * @param list<InventoryStack> $outputs
     * @return null|list<InventoryStack>
     */
    public function piglinBarter(\Bedriox\Api\Entity\Vanilla\Piglin $piglin, InventoryStack $payment, array $outputs): ?array
    {
        $apiPayment = new ApiItemStack(
            $payment->identifier,
            $payment->count,
            $payment->damage,
            $payment->nbt,
            $payment->auxValue,
        );
        $event = new PiglinBarterEvent($piglin, $apiPayment, array_map(
            static fn(InventoryStack $stack): ApiItemStack => new ApiItemStack(
                $stack->identifier,
                $stack->count,
                $stack->damage,
                $stack->nbt,
                $stack->auxValue,
            ),
            $outputs,
        ));
        $this->events->dispatch($event);
        if ($event->isCancelled()) {
            return null;
        }

        return array_map(
            static fn(ApiItemStack $stack): InventoryStack => new InventoryStack(
                $stack->identifier,
                $stack->count,
                stackNetworkId: 1,
                damage: $stack->damage,
                nbt: $stack->nbt,
                auxValue: $stack->auxValue,
            ),
            $event->outputs(),
        );
    }

    /** @param list<InventoryStack> $outputs */
    public function piglinBartered(\Bedriox\Api\Entity\Vanilla\Piglin $piglin, InventoryStack $payment, array $outputs): void
    {
        $this->events->dispatch(new PiglinBarteredEvent(
            $piglin,
            new ApiItemStack($payment->identifier, $payment->count, $payment->damage, $payment->nbt, $payment->auxValue),
            array_map(
                static fn(InventoryStack $stack): ApiItemStack => new ApiItemStack(
                    $stack->identifier,
                    $stack->count,
                    $stack->damage,
                    $stack->nbt,
                    $stack->auxValue,
                ),
                $outputs,
            ),
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

    public function furnaceFuel(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $fuel,
        FurnaceFuelCause $cause,
        int $burnTicks,
    ): ?FurnaceFuelConsumeEvent {
        $event = new FurnaceFuelConsumeEvent($position, $furnaceType, $fuel, $cause, $burnTicks);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function furnaceFuelConsumed(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $fuel,
        FurnaceFuelCause $cause,
        int $burnTicks,
    ): void {
        $this->events->dispatch(new FurnaceFuelConsumedEvent(
            $position,
            $furnaceType,
            $fuel,
            $cause,
            $burnTicks,
        ));
    }

    public function furnaceStartSmelt(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $input,
        ApiItemStack $result,
        int $cookTicks,
    ): ?FurnaceStartSmeltEvent {
        $event = new FurnaceStartSmeltEvent($position, $furnaceType, $input, $result, $cookTicks);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function furnaceStartedSmelting(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $input,
        ApiItemStack $result,
        int $cookTicks,
    ): void {
        $this->events->dispatch(new FurnaceStartedSmeltingEvent(
            $position,
            $furnaceType,
            $input,
            $result,
            $cookTicks,
        ));
    }

    public function furnaceSmelt(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $input,
        ApiItemStack $result,
    ): ?FurnaceSmeltEvent {
        $event = new FurnaceSmeltEvent($position, $furnaceType, $input, $result);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function furnaceSmelted(
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $input,
        ApiItemStack $result,
    ): void {
        $this->events->dispatch(new FurnaceSmeltedEvent($position, $furnaceType, $input, $result));
    }

    public function furnaceExtract(
        ApiPlayer $player,
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $result,
        int $experience,
    ): ?FurnaceExtractEvent {
        $event = new FurnaceExtractEvent($player, $position, $furnaceType, $result, $experience);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function furnaceExtracted(
        ApiPlayer $player,
        ApiBlockPosition $position,
        FurnaceType $furnaceType,
        ApiItemStack $result,
        int $experience,
    ): void {
        $this->events->dispatch(new FurnaceExtractedEvent(
            $player,
            $position,
            $furnaceType,
            $result,
            $experience,
        ));
    }

    public function campfireCookStart(
        ApiBlockPosition $position,
        int $slot,
        ApiItemStack $input,
        ApiItemStack $result,
        int $cookTicks,
        bool $soulCampfire = false,
    ): ?CampfireCookStartEvent {
        $event = new CampfireCookStartEvent($position, $slot, $input, $result, $cookTicks, $soulCampfire);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function campfireCookingStarted(
        ApiBlockPosition $position,
        int $slot,
        ApiItemStack $input,
        ApiItemStack $result,
        int $cookTicks,
        bool $soulCampfire = false,
    ): void {
        $this->events->dispatch(new CampfireCookingStartedEvent(
            $position,
            $slot,
            $input,
            $result,
            $cookTicks,
            $soulCampfire,
        ));
    }

    public function campfireCook(
        ApiBlockPosition $position,
        int $slot,
        ApiItemStack $input,
        ApiItemStack $result,
        bool $soulCampfire = false,
    ): ?CampfireCookEvent {
        $event = new CampfireCookEvent($position, $slot, $input, $result, $soulCampfire);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function campfireCooked(
        ApiBlockPosition $position,
        int $slot,
        ApiItemStack $input,
        ApiItemStack $result,
        bool $soulCampfire = false,
    ): void {
        $this->events->dispatch(new CampfireCookedEvent($position, $slot, $input, $result, $soulCampfire));
    }

    public function stonecutterProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $input,
        ApiItemStack $result,
        string $recipeId,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?StonecutterProcessEvent {
        $event = new StonecutterProcessEvent($player, $position, $input, $result, $recipeId, $cause);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function stonecutterProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $input,
        ApiItemStack $result,
        string $recipeId,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new StonecutterProcessedEvent(
            $player,
            $position,
            $input,
            $result,
            $recipeId,
            $cause,
        ));
    }

    public function smithingProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        SmithingRecipeType $recipeType,
        ApiItemStack $template,
        ApiItemStack $base,
        ApiItemStack $addition,
        ApiItemStack $result,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?SmithingProcessEvent {
        $event = new SmithingProcessEvent(
            $player,
            $position,
            $recipeType,
            $template,
            $base,
            $addition,
            $result,
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function smithingProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        SmithingRecipeType $recipeType,
        ApiItemStack $template,
        ApiItemStack $base,
        ApiItemStack $addition,
        ApiItemStack $result,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new SmithingProcessedEvent(
            $player,
            $position,
            $recipeType,
            $template,
            $base,
            $addition,
            $result,
            $cause,
        ));
    }

    public function anvilProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $left,
        ?ApiItemStack $right,
        ApiItemStack $result,
        int $levelCost,
        string $resultName = '',
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?AnvilProcessEvent {
        $event = new AnvilProcessEvent(
            $player,
            $position,
            $left,
            $right,
            $result,
            $levelCost,
            $resultName,
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function anvilProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $left,
        ?ApiItemStack $right,
        ApiItemStack $result,
        int $levelCost,
        string $resultName = '',
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new AnvilProcessedEvent(
            $player,
            $position,
            $left,
            $right,
            $result,
            $levelCost,
            $resultName,
            $cause,
        ));
    }

    public function grindstoneProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $top,
        ?ApiItemStack $bottom,
        ApiItemStack $result,
        int $experience,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?GrindstoneProcessEvent {
        $event = new GrindstoneProcessEvent(
            $player,
            $position,
            $top,
            $bottom,
            $result,
            $experience,
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function grindstoneProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $top,
        ?ApiItemStack $bottom,
        ApiItemStack $result,
        int $experience,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new GrindstoneProcessedEvent(
            $player,
            $position,
            $top,
            $bottom,
            $result,
            $experience,
            $cause,
        ));
    }

    /**
     * @param list<EnchantingOption> $options
     */
    public function enchantingOptions(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $item,
        array $options,
    ): ?EnchantingOptionsEvent {
        $event = new EnchantingOptionsEvent($player, $position, $item, $options);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    /**
     * @param list<EnchantingOption> $options
     */
    public function enchantingOptionsGenerated(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $item,
        array $options,
    ): void {
        $this->events->dispatch(new EnchantingOptionsGeneratedEvent($player, $position, $item, $options));
    }

    public function playerEnchantItem(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $item,
        ApiItemStack $result,
        EnchantingOption $option,
    ): ?PlayerEnchantItemEvent {
        $event = new PlayerEnchantItemEvent($player, $position, $item, $result, $option);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function playerEnchantedItem(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $item,
        ApiItemStack $result,
        EnchantingOption $option,
    ): void {
        $this->events->dispatch(new PlayerEnchantedItemEvent($player, $position, $item, $result, $option));
    }

    public function loomProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $banner,
        ApiItemStack $dye,
        ?ApiItemStack $patternItem,
        ApiItemStack $result,
        string $pattern,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?LoomProcessEvent {
        $event = new LoomProcessEvent(
            $player,
            $position,
            $banner,
            $dye,
            $patternItem,
            $result,
            $pattern,
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function loomProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        ApiItemStack $banner,
        ApiItemStack $dye,
        ?ApiItemStack $patternItem,
        ApiItemStack $result,
        string $pattern,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new LoomProcessedEvent(
            $player,
            $position,
            $banner,
            $dye,
            $patternItem,
            $result,
            $pattern,
            $cause,
        ));
    }

    public function cartographyProcess(
        ApiPlayer $player,
        ApiBlockPosition $position,
        CartographyOperation $operation,
        ApiItemStack $map,
        ?ApiItemStack $addition,
        ApiItemStack $result,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): ?CartographyProcessEvent {
        $event = new CartographyProcessEvent(
            $player,
            $position,
            $operation,
            $map,
            $addition,
            $result,
            $cause,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function cartographyProcessed(
        ApiPlayer $player,
        ApiBlockPosition $position,
        CartographyOperation $operation,
        ApiItemStack $map,
        ?ApiItemStack $addition,
        ApiItemStack $result,
        StationProcessCause $cause = StationProcessCause::PLAYER,
    ): void {
        $this->events->dispatch(new CartographyProcessedEvent(
            $player,
            $position,
            $operation,
            $map,
            $addition,
            $result,
            $cause,
        ));
    }

    public function composterChange(
        ?ApiPlayer $player,
        ApiBlockPosition $position,
        int $oldLevel,
        int $newLevel,
        ComposterChangeCause $cause,
        ?ApiItemStack $item = null,
    ): ?ComposterChangeEvent {
        $event = new ComposterChangeEvent($player, $position, $oldLevel, $newLevel, $cause, $item);
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function composterChanged(
        ?ApiPlayer $player,
        ApiBlockPosition $position,
        int $oldLevel,
        int $newLevel,
        ComposterChangeCause $cause,
        ?ApiItemStack $item = null,
    ): void {
        $this->events->dispatch(new ComposterChangedEvent(
            $player,
            $position,
            $oldLevel,
            $newLevel,
            $cause,
            $item,
        ));
    }

    public function cauldronChange(
        ?ApiPlayer $player,
        ApiBlockPosition $position,
        CauldronContentType $oldContent,
        int $oldLevel,
        CauldronContentType $newContent,
        int $newLevel,
        CauldronChangeCause $cause,
        ?ApiItemStack $item = null,
    ): ?CauldronChangeEvent {
        $event = new CauldronChangeEvent(
            $player,
            $position,
            $oldContent,
            $oldLevel,
            $newContent,
            $newLevel,
            $cause,
            $item,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function cauldronChanged(
        ?ApiPlayer $player,
        ApiBlockPosition $position,
        CauldronContentType $oldContent,
        int $oldLevel,
        CauldronContentType $newContent,
        int $newLevel,
        CauldronChangeCause $cause,
        ?ApiItemStack $item = null,
    ): void {
        $this->events->dispatch(new CauldronChangedEvent(
            $player,
            $position,
            $oldContent,
            $oldLevel,
            $newContent,
            $newLevel,
            $cause,
            $item,
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

    public function projectileLaunch(
        Player|AbstractLivingEntity $shooter,
        Projectile $projectile,
        string $identifier,
    ): ?Projectile {
        $event = new ProjectileLaunchEvent(
            $shooter instanceof Player ? $this->playerView($shooter) : $shooter,
            $projectile->runtimeEntityId,
            $identifier,
            self::position($projectile->position),
            new Vector3($projectile->motion->x, $projectile->motion->y, $projectile->motion->z),
        );
        $this->events->dispatch($event);
        if ($event->isCancelled()) {
            return null;
        }
        $motion = $event->motion();

        return $projectile->withMotion(new EntityMotion(
            $motion->x,
            $motion->y,
            $motion->z,
        ));
    }

    public function projectileLaunched(
        Player|AbstractLivingEntity $shooter,
        Projectile $projectile,
        string $identifier,
    ): void {
        $this->events->dispatch(new ProjectileLaunchedEvent(
            $shooter instanceof Player ? $this->playerView($shooter) : $shooter,
            $projectile->runtimeEntityId,
            $identifier,
            self::position($projectile->position),
            new Vector3($projectile->motion->x, $projectile->motion->y, $projectile->motion->z),
        ));
    }

    public function projectileImpact(
        Projectile $projectile,
        Player|AbstractLivingEntity|null $shooter = null,
        ?Player $playerTarget = null,
        ?AbstractLivingEntity $entityTarget = null,
        ?BlockPosition $blockTarget = null,
    ): bool {
        $target = $playerTarget === null ? $entityTarget : $this->playerView($playerTarget);
        $generic = new ProjectileImpactEvent(
            $projectile->runtimeEntityId,
            $shooter instanceof Player ? $this->playerView($shooter) : $shooter,
            $projectile->type->value,
            self::position($projectile->position),
            $target,
            $blockTarget === null ? null : new ApiBlockPosition($blockTarget->x, $blockTarget->y, $blockTarget->z),
        );
        $this->events->dispatch($generic);
        if ($generic->isCancelled()) {
            return false;
        }
        if (!$projectile->type->isPotion()
            && $projectile->type !== ProjectileType::ARROW) {
            return true;
        }
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

    public function projectileImpacted(
        Projectile $projectile,
        Player|AbstractLivingEntity|null $shooter = null,
        ?Player $playerTarget = null,
        ?AbstractLivingEntity $entityTarget = null,
        ?BlockPosition $blockTarget = null,
    ): void {
        $target = $playerTarget === null ? $entityTarget : $this->playerView($playerTarget);
        $this->events->dispatch(new ProjectileImpactedEvent(
            $projectile->runtimeEntityId,
            $shooter instanceof Player ? $this->playerView($shooter) : $shooter,
            $projectile->type->value,
            self::position($projectile->position),
            $target,
            $blockTarget === null ? null : new ApiBlockPosition($blockTarget->x, $blockTarget->y, $blockTarget->z),
        ));
        if (!$projectile->type->isPotion()
            && $projectile->type !== ProjectileType::ARROW) {
            return;
        }
        $this->events->dispatch(new PotionProjectileImpactedEvent(
            $projectile->runtimeEntityId,
            $projectile->ownerUuid,
            $projectile->potionType,
            self::position($projectile->position),
            $projectile->lingering,
            $projectile->tippedArrow,
        ));
    }

    public function fish(
        Player $player,
        int $hookRuntimeEntityId,
        PlayerFishState $state,
        ?InventoryStack $caughtItem = null,
        int $experience = 0,
    ): ?PlayerFishEvent {
        $event = new PlayerFishEvent(
            $this->playerView($player),
            $hookRuntimeEntityId,
            $state,
            self::item($caughtItem),
            $experience,
        );
        $this->events->dispatch($event);

        return $event->isCancelled() ? null : $event;
    }

    public function fished(
        Player $player,
        int $hookRuntimeEntityId,
        PlayerFishState $state,
        ?InventoryStack $caughtItem = null,
        int $experience = 0,
    ): void {
        $this->events->dispatch(new PlayerFishedEvent(
            $this->playerView($player),
            $hookRuntimeEntityId,
            $state,
            self::item($caughtItem),
            $experience,
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
            experience: new ExperienceSnapshot($player->experience->totalPoints()),
        );

        return $this->playerActions === null || $this->playerInventoryActions === null || $this->maximumStackSize === null
            ? $view
            : $view->withRuntime(
                $connection,
                ($this->playerActions)($snapshot->identity),
                ($this->playerInventoryActions)($snapshot->identity),
                ($this->maximumStackSize)($snapshot->identity),
                $this->playerEffectActions === null ? null : ($this->playerEffectActions)($snapshot->identity),
                $this->playerExperienceActions === null ? null : ($this->playerExperienceActions)($snapshot->identity),
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
            experience: new ExperienceSnapshot($player->experience->totalPoints()),
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
        ?WorldDimension $dimension = null,
    ): ApiPosition {
        $world = $this->worldResolver === null ? null : ($this->worldResolver)($player->worldName());

        return new ApiPosition(
            $position->x,
            $position->y,
            $position->z,
            $yaw ?? $player->movement->yaw,
            $pitch ?? $player->movement->pitch,
            $world,
            $dimension ?? $player->dimension(),
        );
    }

    private static function block(BlockPosition $position, string $identifier): ApiBlock
    {
        return new ApiBlock(new ApiBlockPosition($position->x, $position->y, $position->z), $identifier);
    }
}
