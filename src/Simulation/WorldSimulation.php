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
use Bedriox\Api\Crafting\RecipeIngredient as ApiRecipeIngredient;
use Bedriox\Api\Crafting\ShapedRecipe as ApiShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe as ApiShapelessRecipe;
use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\Capability\Arthropod;
use Bedriox\Api\Entity\Capability\Breedable;
use Bedriox\Api\Entity\Capability\Rideable;
use Bedriox\Api\Entity\Capability\Tameable;
use Bedriox\Api\Entity\Capability\Undead;
use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause as ApiEntityDamageCause;
use Bedriox\Api\Entity\EntityTargetReason;
use Bedriox\Api\Entity\KnockbackCause as ApiKnockbackCause;
use Bedriox\Api\Entity\KnockbackVector as ApiKnockbackVector;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\MountReason;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Value\RabbitVariant;
use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\Value\WoolColor;
use Bedriox\Api\Entity\Vanilla\Skeleton as ApiSkeleton;
use Bedriox\Api\Entity\Vanilla\Zombie as ApiZombie;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Entity\EntityDamageByEntityEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseReason as ApiInventoryCloseReason;
use Bedriox\Api\Event\World\WeatherChangeCause;
use Bedriox\Api\Inventory\ConsumptionResult as ApiConsumptionResult;
use Bedriox\Api\Inventory\Container as ApiContainer;
use Bedriox\Api\Inventory\ContainerLayout as ApiContainerLayout;
use Bedriox\Api\Inventory\ContainerType as ApiContainerType;
use Bedriox\Api\Inventory\ContainerView as ApiContainerView;
use Bedriox\Api\Inventory\EquipmentSlot as ApiEquipmentSlot;
use Bedriox\Api\Inventory\InventoryActionType as ApiInventoryActionType;
use Bedriox\Api\Inventory\InventoryTransaction as ApiInventoryTransaction;
use Bedriox\Api\Inventory\InventoryTransactionAction as ApiInventoryTransactionAction;
use Bedriox\Api\Inventory\InventoryTransactionCause as ApiInventoryTransactionCause;
use Bedriox\Api\Inventory\InventoryView as ApiInventoryView;
use Bedriox\Api\Inventory\ItemDamageCause as ApiItemDamageCause;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Inventory\ItemUseKind as ApiItemUseKind;
use Bedriox\Api\Player\FoodLevelChangeCause as ApiFoodLevelChangeCause;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\HealthRegainCause as ApiHealthRegainCause;
use Bedriox\Api\Player\Nutrition as ApiNutrition;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\Potion\PotionType;
use Bedriox\Api\Processing\CartographyOperation;
use Bedriox\Api\Processing\SmithingRecipeType;
use Bedriox\Api\TranslatableMessage;
use Bedriox\Api\World\BlockFace as ApiBlockFace;
use Bedriox\Api\World\BlockPosition as ApiBlockPosition;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\WeatherState;
use Bedriox\Data\BlockPropertyRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Effect\VanillaEffectBehavior;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMeleeIntent;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiRangedIntent;
use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\Ai\VanillaAiMemories;
use Bedriox\Server\Entity\AquaticBucketRegistry;
use Bedriox\Server\Entity\AquaticRuntimeState;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityPhysicsResolver;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Equipment\EntityEquipmentTransition;
use Bedriox\Server\Entity\Experience\ExperienceOrbCollisionResolver;
use Bedriox\Server\Entity\Experience\ExperienceOrbMotion;
use Bedriox\Server\Entity\Experience\ExperienceOrbRegistry;
use Bedriox\Server\Entity\Experience\ExperienceOrbTarget;
use Bedriox\Server\Entity\Item\DroppedItemCollisionResolver;
use Bedriox\Server\Entity\Item\DroppedItemEntity;
use Bedriox\Server\Entity\Item\ItemEntityMotion;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Loot\EntityLootResolver;
use Bedriox\Server\Entity\Loot\EquippedLootItem;
use Bedriox\Server\Entity\Loot\GameplayLootItemRegistry;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Mount\HorseFamilyEntity;
use Bedriox\Server\Entity\Mount\MountLink;
use Bedriox\Server\Entity\Mount\MountRegistry;
use Bedriox\Server\Entity\Mount\UndeadHorseEntity;
use Bedriox\Server\Entity\Persistence\EntityPersistenceFlushResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnPlayer;
use Bedriox\Server\Entity\Spawn\Natural\WorldNaturalSpawnRuntime;
use Bedriox\Server\Entity\TameableAnimalEntity;
use Bedriox\Server\Entity\Vanilla\ArmadilloEntity;
use Bedriox\Server\Entity\Vanilla\AxolotlEntity;
use Bedriox\Server\Entity\Vanilla\BoggedEntity;
use Bedriox\Server\Entity\Vanilla\CamelEntity;
use Bedriox\Server\Entity\Vanilla\CatEntity;
use Bedriox\Server\Entity\Vanilla\CaveSpiderEntity;
use Bedriox\Server\Entity\Vanilla\ChickenEntity;
use Bedriox\Server\Entity\Vanilla\CowEntity;
use Bedriox\Server\Entity\Vanilla\CreeperEntity;
use Bedriox\Server\Entity\Vanilla\EndermanEntity;
use Bedriox\Server\Entity\Vanilla\FoxEntity;
use Bedriox\Server\Entity\Vanilla\GoatEntity;
use Bedriox\Server\Entity\Vanilla\LlamaEntity;
use Bedriox\Server\Entity\Vanilla\MagmaCubeEntity;
use Bedriox\Server\Entity\Vanilla\MooshroomEntity;
use Bedriox\Server\Entity\Vanilla\OcelotEntity;
use Bedriox\Server\Entity\Vanilla\PandaEntity;
use Bedriox\Server\Entity\Vanilla\PigEntity;
use Bedriox\Server\Entity\Vanilla\RabbitEntity;
use Bedriox\Server\Entity\Vanilla\SheepEntity;
use Bedriox\Server\Entity\Vanilla\SkeletonHorseEntity;
use Bedriox\Server\Entity\Vanilla\SlimeEntity;
use Bedriox\Server\Entity\Vanilla\SnifferEntity;
use Bedriox\Server\Entity\Vanilla\StrayEntity;
use Bedriox\Server\Entity\Vanilla\TraderLlamaEntity;
use Bedriox\Server\Entity\Vanilla\TurtleEntity;
use Bedriox\Server\Entity\Vanilla\WitchEntity;
use Bedriox\Server\Entity\Vanilla\WitherSkeletonEntity;
use Bedriox\Server\Entity\Vanilla\WolfEntity;
use Bedriox\Server\Entity\Vanilla\ZombieFamilyEntity;
use Bedriox\Server\Entity\WorldEntityEnvironment;
use Bedriox\Server\Gameplay\Block\BlockBreakContext;
use Bedriox\Server\Gameplay\Block\BlockBreakRules;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropRules;
use Bedriox\Server\Gameplay\Block\BlockPlacementStateResolver;
use Bedriox\Server\Gameplay\Block\BlockType;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Block\SystemDropRandom;
use Bedriox\Server\Gameplay\Combat\KnockbackMotion;
use Bedriox\Server\Gameplay\Combat\KnockbackResolver;
use Bedriox\Server\Gameplay\Crafting\ComplexCraftingRecipeEvaluator;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingGrid;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipe;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipeMatch;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapedRecipe;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Gameplay\Enchanting\EnchantmentEffects;
use Bedriox\Server\Gameplay\Enchanting\VanillaEnchantments;
use Bedriox\Server\Gameplay\Explosion\Planning\ExplosionPlanningService;
use Bedriox\Server\Gameplay\Explosion\Planning\LoadedWorldExplosionView;
use Bedriox\Server\Gameplay\Explosion\Value\ExplosionRequest;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ConsumableEffectDefinition;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemUseSession;
use Bedriox\Server\Gameplay\Item\VanillaItemDurability;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloudRegistry;
use Bedriox\Server\Gameplay\Potion\BrewingRecipeCatalog;
use Bedriox\Server\Gameplay\Potion\BrewingStandBlockEntity;
use Bedriox\Server\Gameplay\Potion\BrewingStandProcessor;
use Bedriox\Server\Gameplay\Potion\PotionCatalog;
use Bedriox\Server\Gameplay\Potion\PotionEffectDose;
use Bedriox\Server\Gameplay\Potion\PotionEffectProjector;
use Bedriox\Server\Gameplay\Processing\CampfireBlockEntity;
use Bedriox\Server\Gameplay\Processing\CampfireProcessor;
use Bedriox\Server\Gameplay\Processing\CampfireRecipeResolver;
use Bedriox\Server\Gameplay\Processing\CampfireType;
use Bedriox\Server\Gameplay\Processing\CauldronBlockEntity;
use Bedriox\Server\Gameplay\Processing\CauldronProcessor;
use Bedriox\Server\Gameplay\Processing\CauldronState;
use Bedriox\Server\Gameplay\Processing\ComposterProcessor;
use Bedriox\Server\Gameplay\Processing\ComposterState;
use Bedriox\Server\Gameplay\Processing\FurnaceBlockEntity;
use Bedriox\Server\Gameplay\Processing\FurnaceProcessor;
use Bedriox\Server\Gameplay\Processing\FurnaceRecipeCatalog;
use Bedriox\Server\Gameplay\Processing\FurnaceType;
use Bedriox\Server\Gameplay\Processing\StationTickScheduler;
use Bedriox\Server\Gameplay\Processing\TransientWorkstationProcessor;
use Bedriox\Server\Gameplay\Processing\TransientWorkstationType;
use Bedriox\Server\Gameplay\Processing\WorkstationItemData;
use Bedriox\Server\Gameplay\Processing\WorkstationResult;
use Bedriox\Server\Gameplay\Projectile\ArrowPickupMode;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Gameplay\Projectile\ProjectileCollisionMath;
use Bedriox\Server\Gameplay\Projectile\ProjectileOwnerType;
use Bedriox\Server\Gameplay\Projectile\ProjectilePersistenceCodec;
use Bedriox\Server\Gameplay\Projectile\ProjectileRegistry;
use Bedriox\Server\Gameplay\Projectile\ProjectileState;
use Bedriox\Server\Gameplay\Projectile\ProjectileType;
use Bedriox\Server\Inventory\ContainerInventory as LiveContainerInventory;
use Bedriox\Server\Inventory\ContainerRevisionMismatchException;
use Bedriox\Server\Inventory\ResolvedWorldContainer;
use Bedriox\Server\Inventory\ShulkerBoxItemNbtCodec;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use Bedriox\Server\Inventory\VirtualContainer;
use Bedriox\Server\Inventory\WorldContainerStore;
use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestAction;
use Bedriox\Server\Player\InventoryStackRequestActionType;
use Bedriox\Server\Player\InventoryStackRequestResult;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Player\SupportedInventoryItem;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Plugin\PluginEntityLifecycleBridge;
use Bedriox\Server\Simulation\Command\AcknowledgeRespawn;
use Bedriox\Server\Simulation\Command\AddPlayerEffect;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\ChangeGameMode;
use Bedriox\Server\Simulation\Command\ClearPlayerEffects;
use Bedriox\Server\Simulation\Command\CloseContainer;
use Bedriox\Server\Simulation\Command\CloseCraftingGrid;
use Bedriox\Server\Simulation\Command\DamageEntity;
use Bedriox\Server\Simulation\Command\DamagePlayer;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\DismountPlayer;
use Bedriox\Server\Simulation\Command\DropItem;
use Bedriox\Server\Simulation\Command\GiveItem;
use Bedriox\Server\Simulation\Command\InteractEntity;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MountPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\ReleaseItem;
use Bedriox\Server\Simulation\Command\RemovePlayerEffect;
use Bedriox\Server\Simulation\Command\RemovePluginInventoryStack;
use Bedriox\Server\Simulation\Command\RespawnPlayer;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SendPluginMessage;
use Bedriox\Server\Simulation\Command\SetPlayerExperience;
use Bedriox\Server\Simulation\Command\SetPluginArmorContents;
use Bedriox\Server\Simulation\Command\SetPluginBlock;
use Bedriox\Server\Simulation\Command\SetPluginEquipmentSlot;
use Bedriox\Server\Simulation\Command\SetPluginInventoryContents;
use Bedriox\Server\Simulation\Command\SetPluginInventorySlot;
use Bedriox\Server\Simulation\Command\SpawnPluginParticle;
use Bedriox\Server\Simulation\Command\SwingArm;
use Bedriox\Server\Simulation\Command\SyncInventory;
use Bedriox\Server\Simulation\Command\SyncInventorySlots;
use Bedriox\Server\Simulation\Command\TeleportPlayer;
use Bedriox\Server\Simulation\Command\UseItem;
use Bedriox\Server\Simulation\Command\WorkstationRequest;
use Bedriox\Server\Simulation\Command\WorldCommand;
use Bedriox\Server\Simulation\Event\ActorDismounted;
use Bedriox\Server\Simulation\Event\ActorMounted;
use Bedriox\Server\Simulation\Event\AreaEffectCloudRemoved;
use Bedriox\Server\Simulation\Event\AreaEffectCloudSpawned;
use Bedriox\Server\Simulation\Event\AreaEffectCloudUpdated;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\BrewingCompleted;
use Bedriox\Server\Simulation\Event\BrewingStandUpdated;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerContentsChanged;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\ContainerViewerProjection;
use Bedriox\Server\Simulation\Event\CraftingTableOpened;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\EnchantingOptionsUpdated;
use Bedriox\Server\Simulation\Event\EntityActorAttackStarted;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorEffectChanged;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorHealthChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\EntityExplosionPresented;
use Bedriox\Server\Simulation\Event\EntityInteracted;
use Bedriox\Server\Simulation\Event\ExperienceOrbMoved;
use Bedriox\Server\Simulation\Event\ExperienceOrbPickedUp;
use Bedriox\Server\Simulation\Event\ExperienceOrbRemoved;
use Bedriox\Server\Simulation\Event\ExperienceOrbSpawned;
use Bedriox\Server\Simulation\Event\FurnaceUpdated;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InstantItemUsed;
use Bedriox\Server\Simulation\Event\InventorySlotChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemConsumed;
use Bedriox\Server\Simulation\Event\ItemEntityDespawned;
use Bedriox\Server\Simulation\Event\ItemEntityMoved;
use Bedriox\Server\Simulation\Event\ItemEntityPickedUp;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\ItemUseCancelled;
use Bedriox\Server\Simulation\Event\ItemUseStarted;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\ParticleSpawned;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerEffectChanged;
use Bedriox\Server\Simulation\Event\PlayerEnvironmentChanged;
use Bedriox\Server\Simulation\Event\PlayerExperienceChanged;
use Bedriox\Server\Simulation\Event\PlayerGameModeChanged;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\PotionSplashImpacted;
use Bedriox\Server\Simulation\Event\ProjectileMoved;
use Bedriox\Server\Simulation\Event\ProjectileRemoved;
use Bedriox\Server\Simulation\Event\ProjectileSpawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\TameAttemptPresented;
use Bedriox\Server\Simulation\Event\WeatherChanged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockEntity\BlockEntityType;
use Bedriox\Server\World\BlockEntity\ContainerBlockEntity;
use Bedriox\Server\World\BlockEntity\ContainerItemStack;
use Bedriox\Server\World\BlockEntity\SimpleBlockEntity;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\ChunkPosition;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\BlockCollisionRegistry;
use Bedriox\Server\World\Collision\PlayerCollisionResolver;
use Bedriox\Server\World\Collision\PlayerCollisionShape;
use Bedriox\Server\World\Environment\EnvironmentTickScheduler;
use Bedriox\Server\World\Environment\EnvironmentTickType;
use Bedriox\Server\World\Environment\Fluid\FluidFlowPlanner;
use Bedriox\Server\World\Environment\Fluid\FluidState;
use Bedriox\Server\World\Environment\Fluid\FluidType;
use Bedriox\Server\World\Environment\Fluid\LoadedWorldFluidView;
use Bedriox\Server\World\World;
use InvalidArgumentException;
use OverflowException;
use SplQueue;

final class WorldSimulation
{
    /** @var array<string, int> */
    private array $lastTickStageNanoseconds = [];

    private int $movementCollisionNanoseconds = 0;
    private int $movementSnapshotNanoseconds = 0;
    private int $movementRecipientNanoseconds = 0;
    private int $movementPluginNanoseconds = 0;
    private int $movementFastCollisions = 0;
    private int $movementCollisionObstacles = 0;
    private int $movementGroundedInputs = 0;

    private int $lastEntityRuntimeNanoseconds = 0;

    private int $lastEntityPersistenceNanoseconds = 0;
    private const int DAYLIGHT_FIRE_DURATION_TICKS = 160;
    private const float FIRE_TICK_DAMAGE = 1.0;
    private const float DROWNING_DAMAGE = 2.0;
    private const float LAVA_CONTACT_DAMAGE = 4.0;

    private const int MAXIMUM_ITEM_ENTITIES_SPAWNED_PER_COMMAND = 256;
    private const int MAXIMUM_ITEM_MOVEMENT_EVENTS_PER_TICK = 256;
    private const int MAXIMUM_ITEM_DESPAWN_EVENTS_PER_TICK = 256;
    private const int EMOTE_COOLDOWN_TICKS = 5;
    private const int ARM_SWING_INTERVAL_TICKS = 6;
    private const int EMPTY_HAND_GRASS_BREAK_RATE = 3640;
    private const float MAXIMUM_BLOCK_REACH = 6.0;
    private const int MAXIMUM_ITEM_USE_HOLD_TICKS = 1_200;
    private const int NATURAL_REGENERATION_INTERVAL_TICKS = 80;
    private const float NATURAL_REGENERATION_FOOD_THRESHOLD = 18.0;
    private const float NATURAL_REGENERATION_HEALTH = 1.0;
    private const float NATURAL_REGENERATION_EXHAUSTION = 6.0;
    private const float SPRINTING_EXHAUSTION_PER_BLOCK = 0.1;
    private const int MAXIMUM_VIRTUAL_CONTAINERS = 1_024;
    private const int MAXIMUM_PARTICLES_PER_PLUGIN_PER_TICK = 256;
    private const int MAXIMUM_PARTICLES_PER_WORLD_PER_TICK = 1_024;

    /** @var SplQueue<WorldCommand> */
    private SplQueue $commands;

    /** @var SplQueue<DisconnectPlayer> */
    private SplQueue $lifecycleCommands;

    /** @var SplQueue<string> Session map keys in first-enqueued order. */
    private SplQueue $movementOrder;

    /** @var array<string, MovePlayer> Latest pending movement input per session. */
    private array $movements = [];

    private readonly PlayerRegistry $players;

    /** @var array<string, true> */
    private array $pendingDisconnects = [];

    private int $queuedBytes = 0;
    private int $queuedCommandBytes = 0;
    private int $queuedLifecycleBytes = 0;
    private bool $acceptingCommands = true;
    private int $tick = 0;
    private int $nextRuntimeActorId = 1;
    private readonly SimulationCommandFactory $validator;
    private readonly ?BlockCollisionQuery $blockCollisions;
    private readonly ?PlayerCollisionResolver $collisionResolver;
    private readonly ?DroppedItemCollisionResolver $itemCollisionResolver;
    private readonly ?BlockPlacementStateResolver $blockPlacementStates;
    private readonly DropRandom $dropRandom;
    private readonly ItemEntityRegistry $itemEntities;

    private readonly ExperienceOrbRegistry $experienceOrbs;

    private readonly ?ExperienceOrbCollisionResolver $experienceOrbCollisions;

    private readonly ?EntityLootResolver $entityLoot;

    private readonly EntityWorldRuntime $entityRuntime;

    private readonly ?WorldEntityEnvironment $entityEnvironment;

    private ?EntityPersistenceManager $entityPersistence = null;

    /** @var array<int, AbstractLivingEntity> */
    private array $announcedEntities = [];

    /** @var array<int, int> Runtime actor ID to last projected presentation revision. */
    private array $publishedEntityPresentationRevisions = [];

    /** @var array<int, float> Runtime actor ID to last projected health. */
    private array $publishedEntityHealth = [];

    private readonly IndexedAiWorldView $entityAiWorld;

    /** @var list<AiPlayerSnapshot> One immutable player projection rebuilt once per simulation tick. */
    private array $entityAiPlayers = [];

    /** @var array<int, ApiEntity|ApiPlayer> Runtime actor ID to committed plugin-visible target. */
    private array $entityTargets = [];

    private readonly ?WorldNaturalSpawnRuntime $naturalSpawns;

    private readonly ?EnvironmentTickScheduler $environmentTicks;
    private ?InternalBlockStateId $frostedIceState = null;

    private readonly ?FluidFlowPlanner $fluidFlow;

    private readonly ?LoadedWorldFluidView $fluidWorld;

    /** @var array<int, int> Runtime actor ID to removal tick after its death animation. */
    private array $entityDeathRemovalTicks = [];

    /** @var array<int, int> Runtime actor ID to last immune tick. */
    private array $entityInvulnerableUntilTicks = [];

    /** @var array<int, \Bedriox\Api\Event\Entity\EntityDamageEvent> */
    private array $entityLastDamageEvents = [];
    private int $itemMovementCursor = 0;

    /** @var array<int, ItemEntityMotion> Last motion published for each live item actor. */
    private array $itemPublishedMotions = [];
    /** @var array<int, EntityMotion> */
    private array $projectilePublishedMotions = [];

    /** @var list<int> */
    private array $pendingItemDespawns = [];

    /** @var list<WorldEvent> */
    private array $deferredEvents = [];

    /** @var array<string, true> session ID => pending autosave-cycle membership */
    private array $playerAutosaveQueue = [];

    /** @var array<string, ItemUseSession> One transient action per connected session. */
    private array $activeItemUses = [];

    /** @var array<string, array<string, int>> Session, canonical identifier, expiry tick. */
    private array $itemCooldowns = [];

    /** @var array<string, int> Last successful completion tick per connected session. */
    private array $lastItemUseCompletionTicks = [];

    private readonly ItemBehaviorRegistry $itemBehaviors;

    private readonly ?WorldContainerStore $worldContainers;

    private readonly ?BrewingStandProcessor $brewingStands;

    private readonly ?FurnaceProcessor $furnaces;

    private readonly ?CampfireProcessor $campfires;

    private readonly ?FurnaceRecipeCatalog $processingRecipes;

    private readonly StationTickScheduler $processingStations;

    private readonly StationTickScheduler $campfireStations;

    private readonly ComposterProcessor $composters;

    private readonly CauldronProcessor $cauldrons;

    private readonly StationTickScheduler $composterStations;

    /** @var array<string, BlockPosition> */
    private array $composterPositions = [];

    /** @var array<string, true> Loaded chunks already inspected for pending composter maturation. */
    private array $knownComposterChunks = [];

    /** @var array<string, BlockPosition> */
    private array $furnacePositions = [];

    /** @var array<string, BlockPosition> */
    private array $campfirePositions = [];

    /** @var array<string, BlockPosition> Dirty or actively brewing stands only. */
    private array $activeBrewingStands = [];

    private readonly ProjectileRegistry $projectiles;

    private readonly AreaEffectCloudRegistry $areaEffectClouds;

    private readonly ?\Bedriox\Server\Entity\Persistence\TransientEntityPersistenceStore $projectileEntityStore;

    private readonly ProjectilePersistenceCodec $projectileEntityCodec;

    private bool $projectileEntitiesDirty = false;

    private readonly ShulkerBoxItemNbtCodec $shulkerItems;

    /** @var array<string, PlayerContainerSession> Session map key to its one authorized dynamic window. */
    private array $openContainers = [];

    /** @var array<string, int> Session map key to the next candidate dynamic window ID. */
    private array $nextContainerWindowIds = [];

    /** @var array<string, VirtualContainer> Plugin-owned virtual inventories. */
    private array $virtualContainers = [];

    private int $nextVirtualContainerId = 1;

    private int $particlesThisTick = 0;

    /** @var array<string, int> */
    private array $particlesByPluginThisTick = [];

    private readonly ?ComplexCraftingRecipeEvaluator $complexCraftingRecipes;

    private readonly KnockbackResolver $knockbackResolver;

    private readonly string $worldId;

    private readonly MountRegistry $mounts;

    /** @var array<string, array{position: BlockPosition, state: int, sequence: int, face: int, lastParticleTick: int, lastSwingTick: int}> */
    private array $breakingBlocks = [];

    /** @var array<string, array{session: string, position: Position, acknowledge: bool}> */
    private array $pendingRespawns = [];

    public function __construct(
        private readonly SimulationLimits $limits = new SimulationLimits(),
        private readonly Position $spawn = new Position(0.0, 64.0, 0.0),
        private readonly ?World $blockWorld = null,
        private readonly ?FixedFlatBlockPalette $blockPalette = null,
        private readonly ?PluginGameplayEventBridge $pluginEvents = null,
        private readonly ?InternalBlockStateId $waterState = null,
        private readonly ?InternalBlockStateId $lavaState = null,
        private readonly ?PlayerPersistenceManager $playerPersistence = null,
        private readonly bool $pvp = true,
        private readonly ?ItemCatalog $itemCatalog = null,
        private readonly ?BlockCatalog $blockCatalog = null,
        private readonly ?BlockStateRegistry $blockStateRegistry = null,
        private readonly ?BlockCollisionRegistry $blockCollisionRegistry = null,
        ?DropRandom $dropRandom = null,
        ?ItemEntityRegistry $itemEntities = null,
        ?ItemBehaviorRegistry $itemBehaviors = null,
        private readonly ?CraftingCatalog $craftingCatalog = null,
        private readonly ?BlockPropertyRegistry $blockProperties = null,
        ?EntityWorldRuntime $entityRuntime = null,
        private readonly bool $entityAiEnabled = true,
        private readonly bool $spawnAnimals = true,
        private readonly bool $spawnMonsters = true,
        private readonly ?EntityTypeRegistry $entityTypes = null,
        ?EntityDefinitionRegistry $entityDefinitions = null,
        private readonly ?PluginEntityLifecycleBridge $pluginEntityLifecycle = null,
        private readonly ?PluginActionBuffer $pluginActions = null,
        ?string $worldId = null,
        ?BrewingRecipeCatalog $brewingRecipes = null,
        ?FurnaceRecipeCatalog $furnaceRecipes = null,
        private readonly WorldDimension $dimension = WorldDimension::OVERWORLD,
        private readonly ?TransientWorkstationProcessor $transientWorkstations = null,
        ?MountRegistry $mounts = null,
    ) {
        $this->worldId = $worldId ?? $blockWorld?->metadata->name ?? 'world';
        if ($this->worldId === '' || strlen($this->worldId) > 64) {
            throw new InvalidArgumentException('Simulation world identity must be non-empty and bounded.');
        }
        $this->commands = new SplQueue();
        $this->lifecycleCommands = new SplQueue();
        $this->movementOrder = new SplQueue();
        $this->validator = new SimulationCommandFactory($this->limits);
        $this->players = new PlayerRegistry($this->limits->maximumPlayers);
        $this->mounts = $mounts ?? new MountRegistry();
        $this->knockbackResolver = new KnockbackResolver();
        $this->dropRandom = $dropRandom ?? new SystemDropRandom();
        $this->itemEntities = $itemEntities ?? new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $this->experienceOrbs = new ExperienceOrbRegistry(firstEntityId: 3_750_000_000);
        $this->entityLoot = $itemCatalog === null
            ? null
            : EntityLootResolver::vanilla(new GameplayLootItemRegistry($itemCatalog));
        $this->itemBehaviors = $itemBehaviors ?? ItemBehaviorRegistry::vanilla();
        $this->worldContainers = $blockWorld === null ? null : new WorldContainerStore($blockWorld);
        $this->brewingStands = $blockWorld === null || $brewingRecipes === null
            ? null
            : new BrewingStandProcessor($brewingRecipes);
        $this->furnaces = $blockWorld === null || $furnaceRecipes === null
            ? null
            : new FurnaceProcessor($furnaceRecipes);
        $this->campfires = $blockWorld === null || $furnaceRecipes === null
            ? null
            : new CampfireProcessor($furnaceRecipes);
        $this->processingRecipes = $furnaceRecipes;
        $this->processingStations = new StationTickScheduler();
        $this->campfireStations = new StationTickScheduler();
        $this->composters = new ComposterProcessor();
        $this->cauldrons = new CauldronProcessor();
        $this->composterStations = new StationTickScheduler();
        $this->projectiles = new ProjectileRegistry(firstEntityId: 3_000_000_000);
        $this->areaEffectClouds = new AreaEffectCloudRegistry(firstEntityId: 3_500_000_000);
        $this->projectileEntityCodec = new ProjectilePersistenceCodec();
        $this->projectileEntityStore = $blockWorld?->transientEntityPersistenceStore();
        $persistedProjectileEntities = $this->projectileEntityStore?->loadTransientEntities('projectiles');
        if ($persistedProjectileEntities !== null) {
            [$projectiles, $clouds] = $this->projectileEntityCodec->decode(
                $this->worldId,
                $persistedProjectileEntities,
            );
            foreach ($projectiles as $projectile) {
                $this->projectiles->restore($projectile);
            }
            foreach ($clouds as $cloud) {
                $this->areaEffectClouds->restore($cloud);
            }
        }
        $this->shulkerItems = new ShulkerBoxItemNbtCodec();
        $this->complexCraftingRecipes = $itemCatalog !== null && $blockStateRegistry !== null
            ? new ComplexCraftingRecipeEvaluator($itemCatalog, $blockStateRegistry)
            : null;
        $collisionQuery = $blockWorld !== null && $blockPalette !== null
            ? new BlockCollisionQuery(
                $blockWorld,
                $blockPalette->air,
                array_values(array_filter([$waterState, $lavaState])),
                $blockCollisionRegistry,
            )
            : null;
        $this->blockCollisions = $collisionQuery;
        $this->collisionResolver = $collisionQuery === null ? null : new PlayerCollisionResolver($collisionQuery);
        $this->itemCollisionResolver = $collisionQuery === null ? null : new DroppedItemCollisionResolver($collisionQuery);
        $this->experienceOrbCollisions = $collisionQuery === null ? null : new ExperienceOrbCollisionResolver($collisionQuery);
        $definitions = $entityDefinitions ?? EntityDefinitionRegistry::baseline();
        $this->entityEnvironment = $blockWorld !== null && $blockPalette !== null
            && $blockStateRegistry !== null && $blockCollisionRegistry !== null
            ? new WorldEntityEnvironment(
                $blockWorld,
                $blockStateRegistry,
                $blockCollisionRegistry,
                $blockPalette->air,
                $waterState,
            )
            : null;
        if ($entityRuntime === null) {
            $entityRegistry = new EntityRegistry(firstRuntimeId: 2_000_000_000);
            $spawnService = new EntitySpawnService(
                $entityRegistry,
                $definitions,
                static fn(string $_world, int $chunkX, int $chunkZ): bool => $blockWorld === null
                    || $blockWorld->hasLoadedChunk(new ChunkPosition($chunkX, $chunkZ)),
                static function (EntityDefinition $definition, Position $position) use ($collisionQuery): bool {
                    if ($collisionQuery === null) {
                        return true;
                    }
                    $halfWidth = $definition->width / 2.0;

                    return !$collisionQuery->hasCollision(new AxisAlignedBox(
                        $position->x - $halfWidth,
                        $position->y,
                        $position->z - $halfWidth,
                        $position->x + $halfWidth,
                        $position->y + $definition->height,
                        $position->z + $halfWidth,
                    ));
                },
                $this->pluginEvents === null
                    ? null
                    : fn(\Bedriox\Api\Event\Entity\EntitySpawnEvent $event): bool =>
                        $this->pluginEvents->allowEntitySpawn($event->entity, $event->cause),
                $this->pluginEvents === null
                    ? null
                    : function (\Bedriox\Api\Event\Entity\EntitySpawnedEvent $event): void {
                        $this->pluginEvents->entitySpawned($event->entity, $event->cause);
                    },
                static function (\Bedriox\Server\Entity\AbstractEntity $entity, SpawnCause $cause) use (
                    $pluginEntityLifecycle,
                ): bool {
                    return !$entity instanceof PluginMobEntity
                        || ($pluginEntityLifecycle !== null && $pluginEntityLifecycle->spawned($entity, $cause));
                },
                function (\Bedriox\Server\Entity\AbstractEntity $entity): void {
                    $this->prepareLivingEntity($entity, true);
                },
            );
            $entityRuntime = new EntityWorldRuntime(
                $entityRegistry,
                $spawnService,
                $collisionQuery === null ? null : new EntityPhysicsResolver($collisionQuery, $this->entityEnvironment),
                scheduledAiTick: static function (AbstractMobEntity $entity, int $currentTick) use (
                    $pluginEntityLifecycle,
                ): void {
                    if ($entity instanceof PluginMobEntity) {
                        $pluginEntityLifecycle?->aiTick($entity, $currentTick);
                    }
                },
            );
        }
        $this->entityRuntime = $entityRuntime;
        $this->entityAiWorld = new IndexedAiWorldView(
            $this->entityRuntime->registry(),
            fn(): array => $this->entityAiPlayers,
            $collisionQuery === null
                ? null
                : fn(AbstractMobEntity $entity, AiPlayerSnapshot $player): bool =>
                    $this->hostileEntityHasLineOfSight($entity, $player->position),
            fn(string $worldName, Position $position): bool =>
                $this->entityEnvironment?->isWaterAt($worldName, $position) === true,
            fn(AbstractMobEntity $entity): bool =>
                $this->entityEnvironment?->isTouchingWater($entity) === true,
        );
        $this->naturalSpawns = $blockWorld !== null
            && $blockPalette !== null
            && $blockStateRegistry !== null
            && $blockCollisionRegistry !== null
            && $collisionQuery !== null
            ? WorldNaturalSpawnRuntime::baseline(
                $blockWorld,
                $this->entityRuntime,
                $definitions,
                $blockStateRegistry,
                $blockCollisionRegistry,
                $collisionQuery,
                $blockPalette->air,
                $waterState,
                $lavaState,
                spawnAnimals: $this->spawnAnimals,
                spawnMonsters: $this->spawnMonsters,
                beforeDespawn: fn(AbstractLivingEntity $entity): bool =>
                    $this->pluginEvents?->allowEntityDespawn($entity, 'natural_distance') ?? true,
                worldName: $this->worldId,
            )
            : null;
        if ($blockWorld !== null && $blockStateRegistry !== null && $blockCollisionRegistry !== null) {
            $this->environmentTicks = new EnvironmentTickScheduler();
            $this->fluidFlow = new FluidFlowPlanner();
            $this->fluidWorld = new LoadedWorldFluidView($blockWorld, $blockStateRegistry, $blockCollisionRegistry);
        } else {
            $this->environmentTicks = null;
            $this->fluidFlow = null;
            $this->fluidWorld = null;
        }
        if ($blockStateRegistry !== null) {
            foreach ($blockStateRegistry->states() as $state) {
                if ($state->identifier() === 'minecraft:frosted_ice') {
                    $this->frostedIceState = $blockStateRegistry->internalId($state);
                    break;
                }
            }
        }
        $this->blockPlacementStates = $blockStateRegistry === null
            ? null
            : new BlockPlacementStateResolver($blockStateRegistry);
        $this->validator->move('spawn', 0, $spawn->x, $spawn->y, $spawn->z, 0.0, 0.0, MovementMode::STOPPED);
        if ($blockWorld === null && $spawn->y < $this->limits->flatGroundY) {
            throw new InvalidArgumentException('Spawn cannot be below the flat-world surface.');
        }
    }

    /** Runs authenticated plugin admission before StartGame and chunk scheduling. */
    public function prepareLogin(string $sessionId, int $runtimeActorId, PlayerBootstrap $bootstrap): ?PlayerBootstrap
    {
        if ($this->players->hasSession($sessionId)
            || $this->players->hasIdentity($bootstrap->identity->uuid)
            || $this->players->hasActorId($runtimeActorId)
            || $this->players->isFull()
            || ($this->pluginEvents !== null
                && !$this->pluginEvents->allowJoin($bootstrap->identity->displayName, $bootstrap->identity->uuid))) {
            return null;
        }
        if ($this->blockPalette === null) {
            return null;
        }
        $candidate = new Player(
            $sessionId,
            $runtimeActorId,
            $bootstrap->identity,
            $bootstrap->position,
            $this->limits->chatBucketCapacity,
            $this->tick,
            $this->limits->flatGroundY,
            PlayerInventory::restore(
                $bootstrap->inventory,
                $this->blockPalette,
                $this->itemCatalog,
                $this->blockStateRegistry,
            ),
            $bootstrap->worldName,
            $bootstrap->firstPlayedAt,
            $bootstrap->gamemode,
            $bootstrap->health,
            $bootstrap->food,
            $bootstrap->saturation,
            $bootstrap->exhaustion,
            $bootstrap->effects,
            absorption: $bootstrap->absorption,
            airTicks: $bootstrap->airTicks,
            fireTicks: $bootstrap->fireTicks,
            effectPersistenceState: $bootstrap->effectPersistenceState,
            totalExperience: $bootstrap->totalExperience,
        );
        $candidate->movement->yaw = $bootstrap->yaw;
        $candidate->movement->headYaw = $bootstrap->yaw;
        $candidate->movement->pitch = $bootstrap->pitch;
        $decision = $this->pluginEvents?->login($this->pluginEvents->playerView($candidate));
        if ($decision !== null && !$decision->allowed) {
            return null;
        }

        $destination = $bootstrap->position;
        $yaw = $bootstrap->yaw;
        $pitch = $bootstrap->pitch;
        if ($decision !== null) {
            $destination = $decision->destination;
            $yaw = $decision->yaw;
            $pitch = $decision->pitch;
        }

        return new PlayerBootstrap(
            $bootstrap->identity,
            $bootstrap->worldName,
            $destination,
            $yaw,
            $pitch,
            $bootstrap->inventory,
            $bootstrap->firstPlayedAt,
            $bootstrap->lastPlayedAt,
            $bootstrap->gamemode,
            $bootstrap->health,
            $bootstrap->food,
            $bootstrap->saturation,
            $bootstrap->exhaustion,
            $bootstrap->effects,
            $bootstrap->absorption,
            $bootstrap->airTicks,
            $bootstrap->fireTicks,
            $bootstrap->effectPersistenceState,
            $bootstrap->totalExperience,
        );
    }

    /** Captures one deterministic autosave cycle without chasing continuously changing players. */
    public function beginPlayerAutosave(): int
    {
        if ($this->playerPersistence === null
            || $this->playerAutosaveQueue !== []
            || $this->playerPersistence->pendingCount() > 0) {
            return count($this->playerAutosaveQueue);
        }
        foreach ($this->players->players() as $player) {
            if ($player->isDirty()) {
                $this->playerAutosaveQueue[$player->sessionId] = true;
            }
        }

        return count($this->playerAutosaveQueue);
    }

    /**
     * Advances a bounded autosave snapshot and drains its asynchronous acknowledgements.
     *
     * @return array{saved: int, submitted: int, remaining: int}
     */
    public function autosavePlayers(int $budget): array
    {
        if ($this->playerPersistence === null || $budget < 1) {
            return ['saved' => 0, 'submitted' => 0, 'remaining' => 0];
        }
        $saved = $this->playerPersistence->retryPending($budget);
        $submitted = 0;
        foreach (array_keys($this->playerAutosaveQueue) as $sessionId) {
            if ($saved + $submitted >= $budget) {
                break;
            }
            unset($this->playerAutosaveQueue[$sessionId]);
            $player = $this->players->player($sessionId);
            if ($player !== null && $player->isDirty()) {
                $this->playerPersistence->save($player);
                ++$submitted;
            }
        }

        return [
            'saved' => $saved,
            'submitted' => $submitted,
            'remaining' => count($this->playerAutosaveQueue) + $this->playerPersistence->pendingCount(),
        ];
    }

    public function enqueue(WorldCommand $command): bool
    {
        if (!$this->isValid($command)) {
            return false;
        }
        if ($command instanceof DisconnectPlayer) {
            return $this->enqueueDisconnect($command);
        }
        if (!$this->acceptingCommands) {
            return false;
        }
        if (isset($this->pendingDisconnects[self::sessionKey($command->sessionId())])) {
            return false;
        }
        if ($command instanceof MovePlayer) {
            return $this->enqueueMovement($command);
        }
        $bytes = $command->estimatedBytes();
        if (
            $this->commands->count() + count($this->movements) >= $this->limits->maximumQueuedCommands
            || $bytes > $this->limits->maximumQueuedBytes - $this->queuedCommandBytes
        ) {
            return false;
        }
        $this->commands->enqueue($command);
        $this->queuedCommandBytes += $bytes;
        $this->queuedBytes += $bytes;

        return true;
    }

    public function tick(): SimulationTick
    {
        $stageStartedNanoseconds = hrtime(true);
        ++$this->tick;
        $this->particlesThisTick = 0;
        $this->particlesByPluginThisTick = [];
        $this->blockWorld?->advanceTime();
        [$processed, $events] = $this->processLifecycleCommands($this->limits->maximumCommandsPerTick);
        $stageCompletedNanoseconds = hrtime(true);
        $stages = ['lifecycle' => $stageCompletedNanoseconds - $stageStartedNanoseconds];

        $remaining = $this->limits->maximumCommandsPerTick - $processed;
        $reservedMovements = min(count($this->movements), $remaining);
        while (!$this->commands->isEmpty() && $processed < $this->limits->maximumCommandsPerTick) {
            $next = $this->commands->bottom();
            if ($processed >= $this->limits->maximumCommandsPerTick - $reservedMovements && !$next instanceof JoinPlayer) {
                break;
            }
            $command = $this->commands->dequeue();
            $bytes = $command->estimatedBytes();
            $this->queuedCommandBytes -= $bytes;
            $this->queuedBytes -= $bytes;
            ++$processed;
            $event = $this->apply($command);
            if ($event !== null) {
                $events[] = $event;
            }
            array_push($events, ...$this->drainDeferredEvents());
        }
        $nextStageNanoseconds = hrtime(true);
        $stages['commands'] = $nextStageNanoseconds - $stageCompletedNanoseconds;
        $stageCompletedNanoseconds = $nextStageNanoseconds;

        $this->movementCollisionNanoseconds = 0;
        $this->movementSnapshotNanoseconds = 0;
        $this->movementRecipientNanoseconds = 0;
        $this->movementPluginNanoseconds = 0;
        $this->movementFastCollisions = 0;
        $this->movementCollisionObstacles = 0;
        $this->movementGroundedInputs = 0;
        while (!$this->movementOrder->isEmpty() && $processed < $this->limits->maximumCommandsPerTick) {
            $key = $this->movementOrder->dequeue();
            $command = $this->movements[$key] ?? null;
            if ($command === null) {
                continue;
            }
            unset($this->movements[$key]);
            $bytes = $command->estimatedBytes();
            $this->queuedCommandBytes -= $bytes;
            $this->queuedBytes -= $bytes;
            ++$processed;
            $events[] = $this->acceptMovementInput($command);
            $this->reconcileActiveItemUse($this->players->player($command->session));
            array_push($events, ...$this->drainDeferredEvents());
        }
        $nextStageNanoseconds = hrtime(true);
        $stages['movement'] = $nextStageNanoseconds - $stageCompletedNanoseconds;
        $stages['movement_collision'] = $this->movementCollisionNanoseconds;
        $stages['movement_snapshot'] = $this->movementSnapshotNanoseconds;
        $stages['movement_recipients'] = $this->movementRecipientNanoseconds;
        $stages['movement_plugins'] = $this->movementPluginNanoseconds;
        $stages['movement_fast'] = $this->movementFastCollisions;
        $stages['movement_obstacles'] = $this->movementCollisionObstacles;
        $stages['movement_grounded'] = $this->movementGroundedInputs;
        $stageCompletedNanoseconds = $nextStageNanoseconds;

        array_push($events, ...$this->advancePendingRespawns());
        array_push($events, ...$this->advanceItemUseSessions());
        array_push($events, ...$this->advancePlayerEffects());
        $environmentStartedNanoseconds = hrtime(true);
        array_push($events, ...$this->advanceEnvironmentalBlocks());
        array_push($events, ...$this->advanceWeather());
        $stages['environment'] = hrtime(true) - $environmentStartedNanoseconds;
        array_push($events, ...$this->advancePlayerEnvironment());
        array_push($events, ...$this->advanceNutrition());
        array_push($events, ...$this->advanceBrewingStands());
        array_push($events, ...$this->advanceFurnaces());
        array_push($events, ...$this->advanceCampfires());
        array_push($events, ...$this->advanceComposters());
        array_push($events, ...$this->advanceBlockBreakParticles());
        $nextStageNanoseconds = hrtime(true);
        $stages['players'] = $nextStageNanoseconds - $stageCompletedNanoseconds;
        $stageCompletedNanoseconds = $nextStageNanoseconds;
        array_push($events, ...$this->advanceItemEntities());
        array_push($events, ...$this->advanceExperienceOrbs());
        array_push($events, ...$this->advanceProjectiles());
        $nextStageNanoseconds = hrtime(true);
        $stages['items'] = $nextStageNanoseconds - $stageCompletedNanoseconds;
        $stageCompletedNanoseconds = $nextStageNanoseconds;
        array_push($events, ...$this->advanceNaturalEntities());
        $nextStageNanoseconds = hrtime(true);
        $stages['natural_entities'] = $nextStageNanoseconds - $stageCompletedNanoseconds;
        $stageCompletedNanoseconds = $nextStageNanoseconds;
        array_push($events, ...$this->advanceGeneralEntities());
        array_push($events, ...$this->drainDeferredEvents());
        $stages['general_entities'] = hrtime(true) - $stageCompletedNanoseconds;
        $stages['entity_runtime'] = $this->lastEntityRuntimeNanoseconds;
        $stages['entity_persistence'] = $this->lastEntityPersistenceNanoseconds;
        $aiMetrics = $this->entityRuntime->lastAiMetrics();
        $runtimeMetrics = $this->entityRuntime->lastRuntimeMetrics();
        $stages['entity_ai'] = $aiMetrics->elapsedNanoseconds ?? 0;
        $stages['entity_physics'] = $runtimeMetrics->elapsedNanoseconds ?? 0;
        $stages['entity_ai_ticked'] = $aiMetrics->ticked ?? 0;
        $stages['entity_physics_ticked'] = $runtimeMetrics->physicsTicked ?? 0;
        $stages['entity_physics_continuous_beyond_budget'] = $runtimeMetrics->continuousBeyondBudget ?? 0;
        $this->lastTickStageNanoseconds = $stages;

        return new SimulationTick($this->tick, $processed, $events);
    }

    /** @return array<string, int> */
    public function lastTickStageMicroseconds(): array
    {
        $microseconds = [];
        foreach ($this->lastTickStageNanoseconds as $stage => $nanoseconds) {
            if (in_array($stage, [
                'movement_fast',
                'movement_obstacles',
                'movement_grounded',
                'entity_ai_ticked',
                'entity_physics_ticked',
                'entity_physics_continuous_beyond_budget',
            ], true)) {
                $microseconds[$stage . '_count'] = $nanoseconds;
                continue;
            }
            $microseconds[$stage . '_us'] = intdiv($nanoseconds, 1_000);
        }

        return $microseconds;
    }

    /**
     * Drains only bounded disconnect lifecycle work during shutdown.
     * Gameplay and movement input intentionally remain unapplied once admission stops.
     */
    public function drainLifecycle(): SimulationTick
    {
        ++$this->tick;
        [$processed, $events] = $this->processLifecycleCommands($this->limits->maximumCommandsPerTick);

        return new SimulationTick($this->tick, $processed, $events);
    }

    /** Stops gameplay admission and discards queued non-lifecycle input before shutdown draining. */
    public function beginShutdown(): void
    {
        if (!$this->acceptingCommands) {
            return;
        }
        $this->acceptingCommands = false;
        $this->commands = new SplQueue();
        $this->movements = [];
        $this->movementOrder = new SplQueue();
        $this->queuedCommandBytes = 0;
        $this->queuedBytes = $this->queuedLifecycleBytes;
    }

    public function queuedLifecycleCommands(): int
    {
        return $this->lifecycleCommands->count();
    }

    public function snapshot(): WorldSnapshot
    {
        return new WorldSnapshot($this->tick, $this->players->snapshots());
    }

    /** Returns whether this world can synchronously accept the exact player aggregate. */
    public function canAcceptTransferredPlayer(Player $player): bool
    {
        return !$this->players->hasSession($player->sessionId)
            && !$this->players->hasIdentity($player->identity->uuid)
            && !$this->players->hasActorId($player->runtimeActorId)
            && !$this->players->isFull();
    }

    /**
     * Removes a player from this world without invoking login, join, quit, or persistence lifecycle hooks.
     *
     * The returned aggregate remains authoritative and must be attached to the destination immediately on
     * the same main-thread turn. Events are ordered cleanup first and source-world visibility removal last.
     */
    public function detachPlayerForTransfer(string $sessionId): ?PlayerTransferDeparture
    {
        $key = self::sessionKey($sessionId);
        $player = $this->players->player($sessionId);
        if ($player === null || isset($this->pendingDisconnects[$key])) {
            return null;
        }
        $previousPeers = array_values(array_filter(
            $this->players->snapshots(),
            static fn(PlayerSnapshot $snapshot): bool => $snapshot->sessionId !== $sessionId,
        ));

        $this->removeQueuedCommandsForSession($sessionId);
        $this->removePendingMovement($key);
        unset(
            $this->breakingBlocks[$key],
            $this->pendingRespawns[$key],
            $this->itemCooldowns[$key],
            $this->lastItemUseCompletionTicks[$key],
            $this->playerAutosaveQueue[$sessionId],
        );
        $deferredOffset = count($this->deferredEvents);
        $this->forceDismountPlayer($player, MountReason::WORLD_CHANGE);
        $this->deferItemUseCancellation($player, ItemUseCancellationReason::TELEPORT);
        $closed = $this->closeContainer($player, ApiInventoryCloseReason::TELEPORT, true);
        if ($closed !== null) {
            $this->deferredEvents[] = $closed;
        }
        $this->evacuateCraftingGrid($player, $this->players->recipients($player->sessionId));

        $events = array_splice($this->deferredEvents, $deferredOffset);
        $removed = $this->players->remove($sessionId);
        if ($removed !== $player) {
            throw new \LogicException('Player transfer lost authoritative registry ownership.');
        }
        foreach ($previousPeers as $peer) {
            $events[] = new PlayerBecameHidden($peer->sessionId, $peer->runtimeActorId, $player->sessionId);
        }
        foreach ($this->itemEntities->all() as $entity) {
            $events[] = new ItemEntityDespawned($entity->runtimeEntityId, [$player->sessionId]);
        }
        foreach ($this->experienceOrbs->all() as $entity) {
            $events[] = new ExperienceOrbRemoved($entity->runtimeEntityId, [$player->sessionId]);
        }
        foreach ($this->announcedEntities as $entity) {
            if ($entity->isAlive()) {
                $events[] = new EntityActorRemoved($entity, [$player->sessionId]);
            }
        }
        $events[] = new PlayerDisconnected(
            $player->sessionId,
            $player->identity->uuid,
            $player->runtimeActorId,
            $this->players->recipients(),
        );

        return new PlayerTransferDeparture($player, $previousPeers, $events);
    }

    /**
     * Attaches an already detached aggregate without invoking login or join plugin lifecycle hooks.
     *
     * @throws \LogicException if destination capacity or identity ownership changed after preflight
     */
    public function attachTransferredPlayer(
        Player $player,
        string $worldId,
        Position $position,
        float $yaw,
        float $pitch,
    ): PlayerTransferArrival {
        if (!$this->canAcceptTransferredPlayer($player)) {
            throw new \LogicException('Destination world cannot accept the transferred player.');
        }
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || !is_finite($yaw) || !is_finite($pitch)) {
            throw new InvalidArgumentException('Transferred player pose must contain finite values.');
        }

        $peers = $this->players->snapshots();
        $verticalState = $this->collisionResolver?->isGrounded($position) === true
            ? VerticalState::GROUNDED
            : VerticalState::AIRBORNE;
        $player->changeWorld($worldId);
        $movement = $player->movement;
        $movement->position = $position;
        $movement->yaw = $yaw;
        $movement->headYaw = $yaw;
        $movement->pitch = $pitch;
        $movement->mode = MovementMode::STOPPED;
        $movement->sneaking = false;
        $movement->sprinting = false;
        $movement->velocityX = 0.0;
        $movement->verticalVelocity = 0.0;
        $movement->velocityZ = 0.0;
        $movement->distanceThisTick = 0.0;
        $movement->jumpAuthorizedUntilTick = -1;
        $movement->fallDistance = 0.0;
        $movement->lastTick = $this->tick;
        $movement->budgetTick = $this->tick;
        $movement->verticalState = $verticalState;
        $player->markDirty();
        $this->players->add($player);

        $events = [new PlayerJoined(
            $player->snapshot(),
            $peers,
            $this->players->recipients(),
            $this->blockWorld?->weather()->weather,
        )];
        foreach ($this->itemEntities->all() as $entity) {
            $events[] = new ItemEntitySpawned($entity, [$player->sessionId]);
        }
        foreach ($this->experienceOrbs->all() as $entity) {
            $events[] = new ExperienceOrbSpawned($entity, [$player->sessionId]);
        }
        foreach ($this->announcedEntities as $entity) {
            if ($entity->isAlive()) {
                $events[] = new EntityActorSpawned(
                    $entity,
                    [$player->sessionId],
                    !$this->entityAiEnabled || ($entity instanceof AbstractMobEntity && !$entity->isAiEnabled()),
                );
            }
        }

        return new PlayerTransferArrival($player, $events);
    }

    public function spawnEntity(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        $outcome = $this->entityRuntime->spawn($request);
        if ($outcome->entity instanceof AbstractLivingEntity) {
            $this->naturalSpawns?->restoreDespawnOwnership($outcome->entity);
            if ($this->entityPersistence !== null && $outcome->entity->isPersistent()) {
                $this->entityPersistence->registerSpawned($outcome->entity);
            }
            $this->announcedEntities[$outcome->entity->getRuntimeId()] = $outcome->entity;
            $outcome->entity->drainEquipmentChanges();
            $outcome->entity->drainEffectChanges();
            $this->publishedEntityHealth[$outcome->entity->getRuntimeId()] = $outcome->entity->getHealth();
            $this->publishedEntityPresentationRevisions[$outcome->entity->getRuntimeId()] =
                $outcome->entity->presentationRevision();
            $this->deferredEvents[] = new EntityActorSpawned(
                $outcome->entity,
                $this->players->recipients(),
                !$this->entityAiEnabled,
            );
        }

        return $outcome;
    }

    public function enableEntityPersistence(
        EntityPersistenceStore $store,
        EntityDefinitionRegistry $definitions,
    ): void {
        if ($this->entityPersistence !== null || $this->blockWorld === null || $this->tick !== 0) {
            throw new \LogicException('Entity persistence must be enabled once before simulation begins.');
        }
        $this->entityPersistence = new EntityPersistenceManager(
            $this->worldId,
            $this->entityRuntime->registry(),
            $definitions,
            $store,
            afterActivation: function (
                \Bedriox\Server\Entity\Persistence\EntityPersistenceRecord $record,
                \Bedriox\Server\Entity\AbstractEntity $entity,
            ): bool {
                $this->prepareLivingEntity($entity, false);
                if (!$entity instanceof PluginMobEntity) {
                    if ($entity instanceof AbstractLivingEntity) {
                        $this->naturalSpawns?->restoreDespawnOwnership($entity);
                    }

                    $this->pluginEvents?->entitySpawned($entity, SpawnCause::CHUNK_LOAD);

                    return true;
                }
                if ($this->pluginEntityLifecycle === null) {
                    return false;
                }
                if ($record->customSchemaVersion > 0 && !$this->pluginEntityLifecycle->restoreState(
                    $entity,
                    new \Bedriox\Api\Entity\CustomEntityState($record->customSchemaVersion, $record->customData),
                )) {
                    return false;
                }

                $activated = $this->pluginEntityLifecycle->spawned($entity, SpawnCause::CHUNK_LOAD);
                if ($activated) {
                    $this->naturalSpawns?->restoreDespawnOwnership($entity);
                    $this->pluginEvents?->entitySpawned($entity, SpawnCause::CHUNK_LOAD);
                }

                return $activated;
            },
            customStateEncoder: function (\Bedriox\Server\Entity\AbstractEntity $entity): ?\Bedriox\Api\Entity\CustomEntityState {
                if (!$entity instanceof PluginMobEntity) {
                    return null;
                }
                $state = $this->pluginEntityLifecycle?->encodeState($entity);
                if ($state === null) {
                    throw new \RuntimeException('Plugin entity state is unavailable.');
                }

                return $state;
            },
            afterDeactivation: function (\Bedriox\Server\Entity\AbstractEntity $entity): void {
                if ($entity instanceof PluginMobEntity) {
                    $this->pluginEntityLifecycle?->despawned($entity);
                }
                $this->pluginEvents?->entityDespawned($entity, 'chunk_unload');
            },
        );
        $this->blockWorld->attachEntityPersistence($this->entityPersistence);
    }

    public function autosaveEntities(int $maximumChunks): EntityPersistenceFlushResult
    {
        return $this->entityPersistence?->persistAutosaveGeneration($maximumChunks)
            ?? new EntityPersistenceFlushResult(0, 0, []);
    }

    public function beginEntityAutosave(): int
    {
        return $this->entityPersistence?->beginAutosaveGeneration() ?? 0;
    }

    public function pendingEntityAutosaveChunkCount(): int
    {
        return $this->entityPersistence?->pendingAutosaveChunkCount() ?? 0;
    }

    /** @return list<array{uuid: string, source: ChunkPosition, destination: ChunkPosition, code: string, detail: string}> */
    public function drainEntityOwnershipTransferFailures(): array
    {
        return $this->entityPersistence?->drainOwnershipTransferFailures() ?? [];
    }

    public function dirtyEntityChunkCount(): int
    {
        return $this->entityPersistence?->dirtyChunkCount() ?? 0;
    }

    public function flushEntityPersistence(): EntityPersistenceFlushResult
    {
        $this->persistProjectileEntities();
        return $this->entityPersistence?->flushShutdown()
            ?? new EntityPersistenceFlushResult(0, 0, []);
    }

    public function entityRuntime(): EntityWorldRuntime
    {
        return $this->entityRuntime;
    }

    private function prepareLivingEntity(AbstractEntity $entity, bool $initialSpawn): void
    {
        $entity->bindMountView(
            fn(): ?ApiEntity => $this->mounts->entityLink($entity->getRuntimeId())?->vehicle,
            fn(): array => $this->mounts->passengerViews($entity->getRuntimeId()),
        );
        $entity->configureControllerMountHandlers(
            function (ApiEntity $vehicle, MountSeat $seat) use ($entity): void {
                $this->mountEntityFromController($entity, $vehicle, $seat);
            },
            function () use ($entity): void {
                $this->dismountEntityFromController($entity);
            },
        );
        if (!$entity instanceof AbstractLivingEntity) {
            return;
        }
        if ($this->itemCatalog !== null) {
            $entity->configureEquipmentCatalog($this->itemCatalog);
            if ($initialSpawn) {
                $this->prepareNaturalEntityEquipment($entity);
            }
        }
        if ($this->pluginEvents !== null) {
            $entity->equipmentState()->configureTransitionHooks(
                function (
                    ApiEquipmentSlot $slot,
                    ?ApiItemStack $previous,
                    float $previousDropChance,
                    ?ApiItemStack $item,
                    float $dropChance,
                ) use ($entity): ?EntityEquipmentTransition {
                    $event = $this->pluginEvents->entityEquipmentChange(
                        $entity,
                        $slot,
                        $previous,
                        $previousDropChance,
                        $item,
                        $dropChance,
                    );

                    return $event === null
                        ? null
                        : new EntityEquipmentTransition($event->item(), $event->dropChance());
                },
                function (
                    ApiEquipmentSlot $slot,
                    ?ApiItemStack $previous,
                    float $previousDropChance,
                    ?ApiItemStack $item,
                    float $dropChance,
                ) use ($entity): void {
                    $this->pluginEvents->entityEquipmentChanged(
                        $entity,
                        $slot,
                        $previous,
                        $previousDropChance,
                        $item,
                        $dropChance,
                    );
                },
            );
        }
        $entity->configureControllerDamageHandler(function (
            float $amount,
            ApiEntityDamageCause $cause,
            ?\Bedriox\Api\Entity\Entity $source,
        ) use ($entity): void {
            if (!$entity->isAlive()) {
                return;
            }
            $baseDamage = $cause === ApiEntityDamageCause::ATTACK
                ? $this->entityArmorReducedDamage($entity, $amount)
                : $amount;
            if ($cause !== ApiEntityDamageCause::KILL) {
                $baseDamage *= VanillaEffectBehavior::incomingDamageMultiplier(
                    $entity->effectState()->snapshot(),
                );
            }
            $event = $this->pluginEvents?->entityDamage($entity, $cause, $baseDamage, $source);
            if ($this->pluginEvents !== null && $event === null) {
                return;
            }
            $damage = $event?->damage() ?? $baseDamage;
            if ($damage <= 0.0) {
                return;
            }
            $result = $this->entityRuntime->damage($entity->getRuntimeId(), $damage);
            if ($result === null || $result->appliedDamage <= 0.0) {
                return;
            }
            if ($event !== null) {
                $this->entityLastDamageEvents[$entity->getRuntimeId()] = $event;
            }
            if (!$result->died && $cause === ApiEntityDamageCause::ATTACK) {
                $this->damageEntityArmor($entity, $amount);
            }
            $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
            $this->deferredEvents[] = new EntityActorDamaged(
                $entity,
                $this->tick,
                $this->players->recipients(),
            );
        });
        $entity->configureControllerCombustHandler(function (
            int $durationTicks,
            EntityCombustionCause $cause,
        ) use ($entity): void {
            $event = $this->pluginEvents?->combust($entity, $cause, $durationTicks);
            if ($this->pluginEvents !== null && $event === null) {
                return;
            }
            $entity->setOnFire($event?->durationTicks() ?? $durationTicks);
        });
        $entity->configureControllerEffectAddHandler(function (
            EffectInstance $effect,
            EffectCause $cause,
        ) use ($entity): void {
            $this->applyEffectToEntity($entity, $effect, $cause);
        });
        $entity->configureControllerEffectRemoveHandler(function (
            EffectType $type,
            EffectCause $cause,
        ) use ($entity): void {
            $this->removeEffectFromEntity($entity, $type, $cause);
        });
        $entity->configureControllerEffectClearHandler(function (EffectCause $cause) use ($entity): void {
            foreach (array_keys($entity->effectState()->snapshot()) as $type) {
                $this->removeEffectFromEntity($entity, EffectType::from($type), $cause);
            }
        });
        $entity->configureControllerTransformHandler(function (
            string $worldName,
            Position $position,
            float $yaw,
            float $pitch,
        ) use ($entity): void {
            $activeWorld = $this->worldId;
            if ($worldName !== $activeWorld
                || $this->entityRuntime->registry()->getByRuntimeId($entity->getRuntimeId()) !== $entity) {
                throw new InvalidArgumentException('Entity controller target world is unavailable.');
            }
            $this->entityRuntime->registry()->move(
                $entity->getRuntimeId(),
                $worldName,
                $position,
                $yaw,
                $pitch,
            );
            $this->deferredEvents[] = new EntityActorMoved(
                $entity,
                $this->tick,
                $this->players->recipients(),
                true,
            );
        });
        if ($entity instanceof AbstractMobEntity) {
            $entity->attachController($this->pluginActions);
        }
    }

    private function prepareNaturalEntityEquipment(AbstractLivingEntity $entity): void
    {
        if ($entity instanceof BreedableAnimalEntity && $entity->spawnOrigin() === SpawnCause::NATURAL
            && $this->dropRandom->integer(1, 20) === 1) {
            $entity->setBaby(true);
        }
        if ($entity instanceof SheepEntity && $entity->spawnOrigin() === SpawnCause::NATURAL) {
            $roll = $this->dropRandom->integer(1, 10_000);
            $entity->setWoolColor(match (true) {
                $roll <= 8_184 => WoolColor::WHITE,
                $roll <= 8_684 => WoolColor::LIGHT_GRAY,
                $roll <= 9_184 => WoolColor::GRAY,
                $roll <= 9_684 => WoolColor::BLACK,
                $roll <= 9_984 => WoolColor::BROWN,
                default => WoolColor::PINK,
            });
        }
        if ($entity instanceof RabbitEntity && $entity->spawnOrigin() === SpawnCause::NATURAL) {
            $variants = RabbitVariant::cases();
            $entity->setVariant($variants[$this->dropRandom->integer(0, count($variants) - 2)]);
        }
        if ($entity instanceof ZombieFamilyEntity && $entity->spawnOrigin() === SpawnCause::NATURAL
            && $this->dropRandom->integer(1, 20) === 1) {
            $entity->setBaby(true);
        }
        if ($entity->equipmentState()->getContents() !== []) {
            return;
        }
        if ($entity instanceof ApiSkeleton) {
            if ($this->itemCatalog?->has('minecraft:bow')) {
                $entity->equipmentState()->restoreItem(
                    ApiEquipmentSlot::MAIN_HAND,
                    new ApiItemStack('minecraft:bow', 1),
                    0.085,
                );
            }

            return;
        }
        if ($entity instanceof WitherSkeletonEntity) {
            if ($this->itemCatalog?->has('minecraft:stone_sword')) {
                $entity->equipmentState()->restoreItem(
                    ApiEquipmentSlot::MAIN_HAND,
                    new ApiItemStack('minecraft:stone_sword', 1),
                    0.085,
                );
            }

            return;
        }
        if ($entity->spawnOrigin() !== SpawnCause::NATURAL
            || !$entity instanceof ApiZombie) {
            return;
        }
        $difficulty = $this->blockWorld?->difficulty() ?? 2;
        $armorChance = match ($difficulty) {
            1 => 5,
            2 => 10,
            3 => 15,
            default => 0,
        };
        if ($armorChance > 0 && $this->dropRandom->integer(1, 100) <= $armorChance) {
            $tierRoll = $this->dropRandom->integer(1, 100);
            $tier = match (true) {
                $tierRoll <= 50 => 'leather',
                $tierRoll <= 75 => 'golden',
                $tierRoll <= 90 => 'chainmail',
                $tierRoll <= 99 => 'iron',
                default => 'diamond',
            };
            $pieces = [
                [ApiEquipmentSlot::HEAD, $tier . '_helmet'],
                [ApiEquipmentSlot::CHEST, $tier . '_chestplate'],
                [ApiEquipmentSlot::LEGS, $tier . '_leggings'],
                [ApiEquipmentSlot::FEET, $tier . '_boots'],
            ];
            $equipped = false;
            foreach ($pieces as [$slot, $suffix]) {
                if ($equipped && $this->dropRandom->integer(1, 100) > 65) {
                    continue;
                }
                $identifier = 'minecraft:' . $suffix;
                if (!$this->itemCatalog?->has($identifier)) {
                    continue;
                }
                $entity->equipmentState()->restoreItem(
                    $slot,
                    new ApiItemStack($identifier, 1),
                    0.085,
                );
                $equipped = true;
            }
        }
        if ($difficulty > 0 && $this->dropRandom->integer(1, 100) <= 3) {
            $identifier = $this->dropRandom->integer(0, 1) === 0
                ? 'minecraft:iron_sword'
                : 'minecraft:iron_shovel';
            if ($this->itemCatalog?->has($identifier)) {
                $entity->equipmentState()->restoreItem(
                    ApiEquipmentSlot::MAIN_HAND,
                    new ApiItemStack($identifier, 1),
                    0.085,
                );
            }
        }
    }

    public function entityAiMetrics(): ?AiSchedulerMetrics
    {
        return $this->entityRuntime->lastAiMetrics();
    }

    public function entityRuntimeMetrics(): ?\Bedriox\Server\Entity\EntityRuntimeMetrics
    {
        return $this->entityRuntime->lastRuntimeMetrics();
    }

    /** @internal Diagnostic counter used to prove idle stands do not consume recipe work. */
    public function brewingRecipeEvaluationCount(): int
    {
        return $this->brewingStands?->evaluationCount() ?? 0;
    }

    /** @return list<WorldEvent> */
    private function advanceNaturalEntities(): array
    {
        if ($this->naturalSpawns === null) {
            return [];
        }
        $natural = $this->naturalSpawns->tick(
            $this->tick,
            array_map(
                static fn(Player $player): NaturalSpawnPlayer => new NaturalSpawnPlayer(
                    $player->worldName(),
                    $player->movement->position,
                ),
                array_values(array_filter(
                    $this->players->players(),
                    static fn(Player $player): bool => $player->vitals->isAlive(),
                )),
            ),
        );
        $recipients = $this->players->recipients();
        $events = [];
        foreach ($natural->spawned() as $entity) {
            if ($this->entityPersistence !== null && $entity->isPersistent()) {
                $this->entityPersistence->registerSpawned($entity);
            }
            $this->announcedEntities[$entity->getRuntimeId()] = $entity;
            $entity->drainEquipmentChanges();
            $entity->drainEffectChanges();
            $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
            $this->publishedEntityPresentationRevisions[$entity->getRuntimeId()] = $entity->presentationRevision();
            $events[] = new EntityActorSpawned($entity, $recipients, !$this->entityAiEnabled);
        }
        foreach ($natural->removed() as $entity) {
            unset($this->announcedEntities[$entity->getRuntimeId()]);
            unset($this->publishedEntityHealth[$entity->getRuntimeId()]);
            unset($this->publishedEntityPresentationRevisions[$entity->getRuntimeId()]);
            $this->entityPersistence?->forgetEntity($entity->getUniqueId());
            $events[] = new EntityActorRemoved($entity, $recipients);
        }

        return $events;
    }

    private function projectileMotionChanged(
        Projectile $projectile,
    ): bool {
        $last = $this->projectilePublishedMotions[$projectile->runtimeEntityId] ?? null;
        if ($last === null) {
            $this->projectilePublishedMotions[$projectile->runtimeEntityId] = $projectile->motion;
            return false;
        }
        $dx = $projectile->motion->x - $last->x;
        $dy = $projectile->motion->y - $last->y;
        $dz = $projectile->motion->z - $last->z;
        $stopped = $projectile->motion->x === 0.0 && $projectile->motion->y === 0.0
            && $projectile->motion->z === 0.0
            && ($last->x !== 0.0 || $last->y !== 0.0 || $last->z !== 0.0);
        $changed = ($dx * $dx) + ($dy * $dy) + ($dz * $dz) >= 0.0025 || $stopped;
        if ($changed) {
            $this->projectilePublishedMotions[$projectile->runtimeEntityId] = $projectile->motion;
        }

        return $changed;
    }

    private static function projectileImpactFace(EntityMotion $motion): ApiBlockFace
    {
        $x = abs($motion->x);
        $y = abs($motion->y);
        $z = abs($motion->z);
        if ($y >= $x && $y >= $z) {
            return $motion->y >= 0.0 ? ApiBlockFace::DOWN : ApiBlockFace::UP;
        }
        if ($x >= $z) {
            return $motion->x >= 0.0 ? ApiBlockFace::WEST : ApiBlockFace::EAST;
        }

        return $motion->z >= 0.0 ? ApiBlockFace::NORTH : ApiBlockFace::SOUTH;
    }

    private static function projectileImpactFaceAt(
        Position $impactCenter,
        AxisAlignedBox $expandedCollisionBox,
        EntityMotion $motion,
    ): ApiBlockFace {
        $closestFace = self::projectileImpactFace($motion);
        $closestDistance = INF;
        foreach ([
            [ApiBlockFace::WEST, abs($impactCenter->x - $expandedCollisionBox->minX)],
            [ApiBlockFace::EAST, abs($impactCenter->x - $expandedCollisionBox->maxX)],
            [ApiBlockFace::DOWN, abs($impactCenter->y - $expandedCollisionBox->minY)],
            [ApiBlockFace::UP, abs($impactCenter->y - $expandedCollisionBox->maxY)],
            [ApiBlockFace::NORTH, abs($impactCenter->z - $expandedCollisionBox->minZ)],
            [ApiBlockFace::SOUTH, abs($impactCenter->z - $expandedCollisionBox->maxZ)],
        ] as [$face, $distance]) {
            if ($distance < $closestDistance) {
                $closestFace = $face;
                $closestDistance = $distance;
            }
        }

        return $closestFace;
    }

    private static function projectileEmbeddedPosition(
        Position $impactCenter,
        AxisAlignedBox $collisionBox,
        ApiBlockFace $face,
    ): Position {
        $outside = 0.001;
        $x = max($collisionBox->minX, min($collisionBox->maxX, $impactCenter->x));
        $y = max($collisionBox->minY, min($collisionBox->maxY, $impactCenter->y));
        $z = max($collisionBox->minZ, min($collisionBox->maxZ, $impactCenter->z));

        return match ($face) {
            ApiBlockFace::WEST => new Position($collisionBox->minX - $outside, $y, $z),
            ApiBlockFace::EAST => new Position($collisionBox->maxX + $outside, $y, $z),
            ApiBlockFace::DOWN => new Position($x, $collisionBox->minY - $outside, $z),
            ApiBlockFace::UP => new Position($x, $collisionBox->maxY + $outside, $z),
            ApiBlockFace::NORTH => new Position($x, $y, $collisionBox->minZ - $outside),
            ApiBlockFace::SOUTH => new Position($x, $y, $collisionBox->maxZ + $outside),
        };
    }

    /** @return list<WorldEvent> */
    private function advanceGeneralEntities(): array
    {
        $this->lastEntityRuntimeNanoseconds = 0;
        $this->lastEntityPersistenceNanoseconds = 0;
        $events = [];
        $this->entityAiPlayers = array_map(
            fn(Player $player): AiPlayerSnapshot => new AiPlayerSnapshot(
                $player->identity->uuid,
                $this->worldId,
                $player->movement->position,
                $player->vitals->isAlive() && $player->gameMode()->takesDamage(),
                $player->inventory->selectedStack()?->identifier,
            ),
            array_values(array_filter(
                $this->players->players(),
                static fn(Player $player): bool => $player->vitals->isAlive(),
            )),
        );
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity->controllerDespawnRequested()) {
                continue;
            }
            if (!($this->pluginEvents?->allowEntityDespawn($entity, 'plugin') ?? true)) {
                $entity->clearControllerDespawnRequest();
                continue;
            }
            if ($entity instanceof PluginMobEntity) {
                $this->pluginEntityLifecycle?->despawned($entity);
            }
            $this->entityPersistence?->forgetEntity($entity->getUniqueId());
            $this->entityRuntime->remove($entity->getRuntimeId());
            $this->pluginEvents?->entityDespawned($entity, 'plugin');
        }
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof PluginMobEntity) {
                continue;
            }
            if ($this->pluginEntityLifecycle === null
                || !$this->pluginEntityLifecycle->tick($entity, $this->tick)) {
                $this->entityPersistence?->deactivateEntity($entity->getUniqueId());
                $this->entityRuntime->remove($entity->getRuntimeId());
                continue;
            }
            if ($entity->pluginDespawnRequested()) {
                $this->pluginEntityLifecycle->despawned($entity);
                $this->entityPersistence?->forgetEntity($entity->getUniqueId());
                $this->entityRuntime->remove($entity->getRuntimeId());
                $this->pluginEvents?->entityDespawned($entity, 'plugin');
            }
        }
        array_push($events, ...$this->advanceEntityEffects());
        array_push($events, ...$this->advanceEntityFire());
        array_push($events, ...$this->advanceLivingEntityBreathing());
        array_push($events, ...$this->advanceCreepers());
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if ($entity instanceof BreedableAnimalEntity && $entity->isAlive()
                && ($this->tick + $entity->getRuntimeId()) % 20 === 0) {
                $entity->advanceSpeciesState(20);
                if ($entity instanceof SheepEntity && ($this->tick + $entity->getRuntimeId()) % 100 === 0) {
                    $this->trySheepEatGrass($entity);
                }
                if ($entity instanceof ChickenEntity && $entity->advanceEggLayTimer(20)) {
                    $entity->resetEggLayTimer($this->dropRandom->integer(6_000, 12_000));
                    try {
                        $item = $this->itemEntities->spawn(
                            new InventoryStack('minecraft:egg', 1, 1),
                            new Position($entity->internalPosition()->x, $entity->internalPosition()->y + 0.25, $entity->internalPosition()->z),
                            new ItemEntityMotion(0.0, 0.05, 0.0),
                            10,
                        );
                        $this->deferredEvents[] = new ItemEntitySpawned($item, $this->players->recipients());
                    } catch (InvalidArgumentException|OverflowException) {
                        // Capacity pressure defers the next bounded egg cycle instead of failing the world tick.
                    }
                }
            }
            if ($entity instanceof MutableAngerState && $entity->isAlive()
                && ($this->tick + $entity->getRuntimeId()) % 20 === 0) {
                $entity->advanceAngerState(20);
            }
        }
        $entityRuntimeStartedNanoseconds = hrtime(true);
        $tick = $this->entityRuntime->tick(
            $this->tick,
            $this->entityAiWorld,
            $this->entityAiEnabled,
        );
        $this->lastEntityRuntimeNanoseconds = hrtime(true) - $entityRuntimeStartedNanoseconds;
        foreach ($this->mounts->links() as $link) {
            $passenger = $link->passengerEntity;
            if ($passenger === null) {
                continue;
            }
            if ($passenger->isRemoved() || $link->vehicle->isRemoved()
                || ($passenger instanceof AbstractLivingEntity && !$passenger->isAlive())
                || ($link->vehicle instanceof AbstractLivingEntity && !$link->vehicle->isAlive())
                || $this->entityRuntime->registry()->getByRuntimeId($passenger->getRuntimeId()) !== $passenger
                || $this->entityRuntime->registry()->getByRuntimeId($link->vehicle->getRuntimeId()) !== $link->vehicle) {
                $this->mounts->dismountEntity($passenger->getRuntimeId());
                $events[] = new ActorDismounted(
                    $link->vehicle->getRuntimeId(),
                    $passenger->getRuntimeId(),
                    null,
                    $this->players->recipients(),
                );
                continue;
            }
            if ($passenger instanceof AbstractMobEntity) {
                $passenger->suppressAiMovementUntil($this->tick + 2);
            }
            $vehiclePosition = $link->vehicle->internalPosition();
            $passenger->setMotion(new EntityMotion());
            $passenger->moveTo(
                $link->vehicle->getWorldName(),
                new Position(
                    $vehiclePosition->x,
                    $vehiclePosition->y + $link->vehicle->collisionHeight(),
                    $vehiclePosition->z,
                ),
                $link->vehicle->getYaw(),
                $passenger->getPitch(),
            );
        }
        foreach ($this->players->players() as $mountedPlayer) {
            $link = $this->mounts->playerLink($mountedPlayer->sessionId);
            if ($link === null) {
                continue;
            }
            if ($link->vehicle->isRemoved()
                || ($link->vehicle instanceof AbstractLivingEntity && !$link->vehicle->isAlive())
                || $this->entityRuntime->registry()->getByRuntimeId($link->vehicle->getRuntimeId()) !== $link->vehicle) {
                $this->forceDismountPlayer($mountedPlayer, MountReason::VEHICLE_REMOVED);
                continue;
            }
            $before = $mountedPlayer->movement->position;
            $this->syncMountedPlayer($mountedPlayer, $link);
            if ($before != $mountedPlayer->movement->position) {
                $events[] = new PlayerMoved(
                    $mountedPlayer->snapshot(),
                    $this->players->recipients($mountedPlayer->sessionId),
                );
            }
        }
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof PluginMobEntity || ($this->pluginEntityLifecycle?->isAvailable($entity) ?? false)) {
                continue;
            }
            $this->entityPersistence?->deactivateEntity($entity->getUniqueId());
            $this->entityRuntime->remove($entity->getRuntimeId());
        }
        $recipients = $this->players->recipients();
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof AbstractLivingEntity || isset($this->announcedEntities[$entity->getRuntimeId()])) {
                continue;
            }
            $this->announcedEntities[$entity->getRuntimeId()] = $entity;
            $entity->drainEquipmentChanges();
            $entity->drainEffectChanges();
            $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
            $this->publishedEntityPresentationRevisions[$entity->getRuntimeId()] = $entity->presentationRevision();
            $events[] = new EntityActorSpawned($entity, $recipients, !$this->entityAiEnabled);
        }
        foreach ($this->announcedEntities as $runtimeId => $entity) {
            if ($this->entityRuntime->registry()->getByRuntimeId($runtimeId) !== null) {
                continue;
            }
            unset($this->announcedEntities[$runtimeId]);
            unset($this->publishedEntityHealth[$runtimeId]);
            unset($this->publishedEntityPresentationRevisions[$runtimeId]);
            unset($this->entityTargets[$runtimeId]);
            $events[] = new EntityActorRemoved($entity, $recipients);
        }
        foreach ($this->announcedEntities as $entity) {
            $equipmentChanges = $entity->drainEquipmentChanges();
            if ($equipmentChanges !== []) {
                $events[] = new EntityActorEquipmentChanged(
                    $entity,
                    $this->tick,
                    $equipmentChanges,
                    $recipients,
                );
            }
        }
        foreach ($this->announcedEntities as $entity) {
            foreach ($entity->drainEffectChanges() as $transition) {
                if ($transition->current !== null) {
                    $type = $transition->current->type;
                } elseif ($transition->previous !== null) {
                    $type = $transition->previous->type;
                } else {
                    continue;
                }
                $events[] = new EntityActorEffectChanged(
                    $entity,
                    $type,
                    $transition->current,
                    $this->tick,
                    $transition->previous !== null && $transition->current !== null,
                    $recipients,
                );
            }
        }
        foreach ($this->announcedEntities as $runtimeId => $entity) {
            $health = $entity->getHealth();
            $previous = $this->publishedEntityHealth[$runtimeId] ?? $health;
            if ($health === $previous) {
                continue;
            }
            $this->publishedEntityHealth[$runtimeId] = $health;
            if ($health < $previous && $entity->effectState()->has(EffectType::INFESTED)) {
                $this->triggerInfestedSpawn($entity->getPosition());
            }
            $events[] = $health < $previous
                ? new EntityActorDamaged($entity, $this->tick, $recipients)
                : new EntityActorHealthChanged($entity, $this->tick, $recipients);
        }
        foreach ($tick->moved as $entity) {
            if ($this->entityRuntime->registry()->getByRuntimeId($entity->getRuntimeId()) !== $entity) {
                continue;
            }
            $events[] = new EntityActorMoved(
                $entity,
                $this->tick,
                $recipients,
                $tick->motionChangedFor($entity->getRuntimeId()),
            );
        }
        foreach ($this->announcedEntities as $runtimeId => $entity) {
            $revision = $entity->presentationRevision();
            if (($this->publishedEntityPresentationRevisions[$runtimeId] ?? -1) === $revision) {
                continue;
            }
            $this->publishedEntityPresentationRevisions[$runtimeId] = $revision;
            $events[] = new EntityActorMetadataChanged(
                $entity,
                $this->tick,
                $recipients,
                !$this->entityAiEnabled || ($entity instanceof AbstractMobEntity && !$entity->isAiEnabled()),
            );
        }
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof AbstractMobEntity) {
                continue;
            }
            $this->reconcileEntityTarget($entity);
            $intent = $entity->aiRuntime()->takeMeleeIntent($this->tick);
            if ($intent !== null) {
                array_push($events, ...$this->applyEntityMeleeIntent($entity, $intent));
            }
            $rangedIntent = $entity->aiRuntime()->takeRangedIntent($this->tick);
            if ($rangedIntent !== null) {
                array_push($events, ...$this->applyEntityRangedIntent($entity, $rangedIntent));
            }
        }
        foreach ($tick->died as $entity) {
            $this->triggerDeathEffectConsequences(
                $entity->getPosition(),
                $entity->effectState()->snapshot(),
                'entity:' . $entity->getUniqueId(),
            );
            $lastDamage = $this->entityLastDamageEvents[$entity->getRuntimeId()] ?? null;
            $drops = $this->prepareEntityDeathDrops($entity, $lastDamage);
            if ($this->pluginEvents !== null) {
                $drops = $this->pluginEvents->entityDied($entity, $lastDamage, $drops);
            }
            $this->splitSlime($entity);
            $events[] = new EntityActorDied($entity, $recipients);
            array_push(
                $events,
                ...$this->spawnEntityDeathDrops($entity, $drops, $recipients),
            );
            $this->entityDeathRemovalTicks[$entity->getRuntimeId()] = $this->tick + 20;
        }
        foreach ($this->entityDeathRemovalTicks as $runtimeId => $removeAtTick) {
            if ($this->tick < $removeAtTick) {
                continue;
            }
            unset($this->entityDeathRemovalTicks[$runtimeId]);
            $entity = $this->entityRuntime->remove($runtimeId);
            if ($entity !== null) {
                if ($entity instanceof PluginMobEntity) {
                    $this->pluginEntityLifecycle?->despawned($entity);
                }
                unset($this->entityInvulnerableUntilTicks[$runtimeId], $this->entityLastDamageEvents[$runtimeId]);
                unset($this->announcedEntities[$runtimeId]);
                unset($this->publishedEntityHealth[$runtimeId]);
                unset($this->publishedEntityPresentationRevisions[$runtimeId]);
                $this->entityPersistence?->forgetEntity($entity->getUniqueId());
                $events[] = new EntityActorRemoved($entity, $recipients);
            }
        }

        $entityPersistenceStartedNanoseconds = hrtime(true);
        $this->entityPersistence?->synchronize(256);
        $this->lastEntityPersistenceNanoseconds = hrtime(true) - $entityPersistenceStartedNanoseconds;

        return $events;
    }

    private function splitSlime(AbstractLivingEntity $entity): void
    {
        if ((!$entity instanceof SlimeEntity && !$entity instanceof MagmaCubeEntity)
            || $entity->getSize() === SlimeSize::SMALL) {
            return;
        }
        $childSize = $entity->getSize() === SlimeSize::LARGE ? SlimeSize::MEDIUM : SlimeSize::SMALL;
        $childCount = $this->dropRandom->integer(2, 4);
        $childCount = $this->pluginEvents?->splitEntity($entity, $entity->getType(), $childCount)
            ?? ($this->pluginEvents === null ? $childCount : 0);
        $childCount = min($childCount, $this->entityRuntime->registry()->remainingCapacity());
        for ($index = 0; $index < $childCount; ++$index) {
            $offsetX = (($index % 2) - 0.5) * $childSize->value * 0.52;
            $offsetZ = ((int) floor($index / 2) - 0.5) * $childSize->value * 0.52;
            $position = $entity->internalPosition();
            $child = $this->entityRuntime->registry()->spawn(
                fn(string $uuid, int $runtimeId): AbstractLivingEntity => $entity instanceof SlimeEntity
                    ? new SlimeEntity($uuid, $runtimeId, $entity->getWorldName(), new Position($position->x + $offsetX, $position->y, $position->z + $offsetZ), $childSize)
                    : new MagmaCubeEntity($uuid, $runtimeId, $entity->getWorldName(), new Position($position->x + $offsetX, $position->y, $position->z + $offsetZ), $childSize),
            );
            $child->restoreSpawnOwnership(SpawnCause::EFFECT, EntityDespawnPolicy::forSpawnCause(SpawnCause::EFFECT));
            $this->prepareLivingEntity($child, true);
            if (!($this->pluginEvents?->allowEntitySpawn($child, SpawnCause::EFFECT) ?? true)) {
                $this->entityRuntime->remove($child->getRuntimeId());
                continue;
            }
            $this->entityPersistence?->registerSpawned($child);
            $this->pluginEvents?->entitySpawned($child, SpawnCause::EFFECT);
        }
    }

    /** @return list<ApiItemStack> */
    private function prepareEntityDeathDrops(
        AbstractLivingEntity $entity,
        ?\Bedriox\Api\Event\Entity\EntityDamageEvent $lastDamage,
    ): array {
        if ($this->entityLoot === null) {
            return [];
        }
        $killer = $lastDamage instanceof EntityDamageByEntityEvent ? $lastDamage->damager : null;
        $lootingLevel = $killer instanceof \Bedriox\Api\Player\Player
            ? EnchantmentEffects::level(
                $killer->getInventory()->getHeldItem()?->nbt,
                VanillaEnchantments::LOOTING,
            )
            : 0;
        $equipment = [];
        foreach (ApiEquipmentSlot::cases() as $slot) {
            $item = $entity->equipmentState()->getItem($slot);
            if ($item !== null) {
                $equipment[] = new EquippedLootItem(
                    $slot,
                    $item,
                    $entity->equipmentState()->getDropChance($slot),
                );
            }
        }

        return $this->entityLoot->prepare(new LootContext(
            $entity->getType(),
            $lastDamage,
            $killer,
            $entity->isOnFire(),
            $equipment,
            $entity->spawnOrigin(),
            $this->blockWorld?->difficulty() ?? 2,
            $lootingLevel,
            $entity,
        ))->drops();
    }

    /**
     * @param list<ApiItemStack> $drops
     * @param list<string> $recipients
     * @return list<ItemEntitySpawned>
     */
    private function spawnEntityDeathDrops(
        AbstractLivingEntity $entity,
        array $drops,
        array $recipients,
    ): array {
        $events = [];
        $position = $entity->internalPosition();
        foreach ($drops as $drop) {
            if (!$this->itemEntities->canSpawn()) {
                continue;
            }
            try {
                $stack = $this->inventoryStackFromApi($drop);
                $spreadX = $this->dropRandom->integer(-10, 10) / 100.0;
                $spreadZ = $this->dropRandom->integer(-10, 10) / 100.0;
                $item = $this->itemEntities->spawn(
                    $stack,
                    new Position($position->x, $position->y + 0.5, $position->z),
                    new ItemEntityMotion($spreadX, 0.15, $spreadZ),
                    10,
                );
                $events[] = new ItemEntitySpawned($item, $recipients);
            } catch (InvalidArgumentException|OverflowException) {
                // Invalid plugin-modified drops are isolated from the world tick.
            }
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceCreepers(): array
    {
        $events = [];
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof CreeperEntity || !$entity->isAlive()) {
                continue;
            }
            $position = $entity->internalPosition();
            $nearestDistance = INF;
            foreach ($this->players->players() as $player) {
                if (!$player->vitals->isAlive() || !$player->gameMode()->takesDamage()) {
                    continue;
                }
                $target = $player->movement->position;
                $distance = hypot(hypot($target->x - $position->x, $target->z - $position->z), $target->y - $position->y);
                $nearestDistance = min($nearestDistance, $distance);
            }
            if (!$entity->isIgnited() && $nearestDistance <= 2.5) {
                $entity->beginProximityFuse();
            } elseif ($nearestDistance > 6.0 && $entity->getFuseTicks() < 30) {
                $entity->cancelProximityFuse();
            }
            if (!$entity->isIgnited() || !$entity->advanceFuse()) {
                continue;
            }
            array_push($events, ...$this->explodeCreeper($entity));
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function explodeCreeper(CreeperEntity $creeper): array
    {
        $position = $creeper->internalPosition();
        $radius = $creeper->isCharged() ? 6.0 : 3.0;
        $apiPosition = new ApiPosition($position->x, $position->y, $position->z);
        $prime = $this->pluginEvents?->primeExplosion($creeper, $apiPosition, $radius, true, 0.0);
        if ($this->pluginEvents !== null && $prime === null) {
            $creeper->setIgnited(false);
            return [];
        }
        $radius = $prime?->radius() ?? $radius;
        $breaksBlocks = $prime?->breaksBlocks() ?? true;
        $fireChance = $prime?->fireChance() ?? 0.0;
        $events = [];
        $affectedActors = [];
        foreach ($this->players->players() as $player) {
            if (!$player->vitals->isAlive() || !$player->gameMode()->takesDamage()) {
                continue;
            }
            $target = $player->movement->position;
            $distance = hypot(hypot($target->x - $position->x, $target->z - $position->z), $target->y - $position->y);
            if ($distance > $radius * 2.0) {
                continue;
            }
            $exposure = max(0.0, 1.0 - ($distance / ($radius * 2.0)));
            $event = $this->damage(new DamagePlayer(
                $player->sessionId,
                max(1.0, (($exposure * $exposure + $exposure) / 2.0) * 42.0 * $radius / 3.0 + 1.0),
                DamageCause::Explosion,
            ));
            $events[] = $event;
            if ($event instanceof PlayerDamaged && $event->damage > 0.0 && $this->pluginEvents !== null) {
                $affectedActors[] = $this->pluginEvents->playerView($player);
            }
        }
        foreach ($this->entityRuntime->registry()->nearby($creeper->getWorldName(), $position, $radius * 2.0, 256) as $entity) {
            if (!$entity instanceof AbstractLivingEntity || $entity === $creeper || !$entity->isAlive()) {
                continue;
            }
            $distance = hypot(hypot($entity->internalPosition()->x - $position->x, $entity->internalPosition()->z - $position->z), $entity->internalPosition()->y - $position->y);
            $exposure = max(0.0, 1.0 - ($distance / ($radius * 2.0)));
            $damage = max(1.0, (($exposure * $exposure + $exposure) / 2.0) * 42.0 * $radius / 3.0 + 1.0);
            $damageEvent = $this->pluginEvents?->entityDamage($entity, ApiEntityDamageCause::EXPLOSION, $damage, $creeper);
            if ($this->pluginEvents !== null && $damageEvent === null) {
                continue;
            }
            $result = $this->entityRuntime->damage($entity->getRuntimeId(), $damageEvent?->damage() ?? $damage);
            if ($result !== null && $result->appliedDamage > 0.0) {
                $affectedActors[] = $entity;
            }
        }

        $affectedBlocks = [];
        if ($breaksBlocks && $this->blockWorld !== null && $this->blockStateRegistry !== null && $this->blockPalette !== null) {
            $plan = (new ExplosionPlanningService())->plan(
                new ExplosionRequest($position, $radius, true, $fireChance),
                new LoadedWorldExplosionView($this->blockWorld, $this->blockStateRegistry),
            );
            foreach ($plan->affectedBlocks as $blockPosition) {
                $previous = $this->blockWorld->loadedBlockStateAt($blockPosition->x, $blockPosition->y, $blockPosition->z);
                if ($previous === null || $previous->value === $this->blockPalette->air->value
                    || $this->blockIdentifier($previous->value) === 'minecraft:bedrock') {
                    continue;
                }
                $this->setBlockStateAndSchedule($blockPosition, $this->blockPalette->air);
                $affectedBlocks[] = new ApiBlockPosition($blockPosition->x, $blockPosition->y, $blockPosition->z);
                $events[] = new BlockChanged('server', $blockPosition, $this->blockPalette->air, $this->players->recipients(), destroyedState: $previous);
            }
        }
        $this->entityPersistence?->forgetEntity($creeper->getUniqueId());
        $this->entityRuntime->remove($creeper->getRuntimeId());
        $runtimeId = $creeper->getRuntimeId();
        unset(
            $this->announcedEntities[$runtimeId],
            $this->publishedEntityHealth[$runtimeId],
            $this->publishedEntityPresentationRevisions[$runtimeId],
            $this->entityTargets[$runtimeId],
            $this->entityInvulnerableUntilTicks[$runtimeId],
            $this->entityLastDamageEvents[$runtimeId],
            $this->entityDeathRemovalTicks[$runtimeId],
        );
        $events[] = new EntityExplosionPresented($position, $this->players->recipients());
        $events[] = new EntityActorRemoved($creeper, $this->players->recipients());
        $this->pluginEvents?->entityExploded($creeper, $apiPosition, $radius, $affectedBlocks !== [], $fireChance, $affectedBlocks, $affectedActors);

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceEntityEffects(): array
    {
        $events = [];
        $recipients = $this->players->recipients();
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof AbstractLivingEntity || !$entity->isAlive()
                || $entity->effectState()->snapshot() === []) {
                continue;
            }
            $healthBefore = $entity->getHealth();
            $absorptionBefore = $entity->getAbsorption();
            $motion = $entity->getMotion();
            $levitationVelocity = VanillaEffectBehavior::levitationVelocity(
                $entity->effectState()->snapshot(),
                $motion->y,
            );
            if ($levitationVelocity !== null) {
                $entity->setMotion(new EntityMotion($motion->x, $levitationVelocity, $motion->z));
            } elseif ($entity->effectState()->has(EffectType::SLOW_FALLING) && $motion->y < -0.125) {
                $entity->setMotion(new EntityMotion($motion->x, -0.125, $motion->z));
            }
            $transitions = $entity->effectState()->tick(1, function (EffectInstance $effect) use ($entity): void {
                if ($effect->type === EffectType::REGENERATION) {
                    $entity->heal(1.0);
                    return;
                }
                if (in_array($effect->type, [EffectType::POISON, EffectType::FATAL_POISON, EffectType::WITHER], true)) {
                    $minimum = $effect->type === EffectType::POISON ? 1.0 : 0.0;
                    $amount = min(1.0, max(0.0, $entity->getHealth() - $minimum));
                    if ($amount <= 0.0) {
                        return;
                    }
                    $amount *= VanillaEffectBehavior::incomingDamageMultiplier(
                        $entity->effectState()->snapshot(),
                    );
                    $event = $this->pluginEvents?->entityDamage(
                        $entity,
                        ApiEntityDamageCause::MAGIC,
                        $amount,
                    );
                    if ($this->pluginEvents !== null && $event === null) {
                        return;
                    }
                    $damage = $event?->damage() ?? $amount;
                    if ($damage > 0.0) {
                        $entity->damage(min($damage, max(0.0, $entity->getHealth() - $minimum)));
                        if ($event !== null) {
                            $this->entityLastDamageEvents[$entity->getRuntimeId()] = $event;
                        }
                    }
                }
            }, function (EffectInstance $effect) use ($entity): void {
                $this->pluginEvents?->allowEffectRemoval($entity, $effect, EffectCause::EXPIRATION);
            });
            $entity->recordEffectTransitions($transitions);
            foreach ($transitions as $transition) {
                if ($transition->previous !== null) {
                    $this->pluginEvents?->effectRemoved(
                        $entity,
                        $transition->previous,
                        EffectCause::EXPIRATION,
                        $transition->current,
                    );
                }
            }
            if ($entity->getHealth() === $healthBefore && $entity->getAbsorption() === $absorptionBefore) {
                continue;
            }
            $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
            $events[] = $entity->getHealth() < $healthBefore
                ? new EntityActorDamaged($entity, $this->tick, $recipients)
                : new EntityActorHealthChanged($entity, $this->tick, $recipients);
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceLivingEntityBreathing(): array
    {
        if ($this->entityEnvironment === null) {
            return [];
        }
        $events = [];
        $recipients = $this->players->recipients();
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof AbstractLivingEntity || !$entity->isAlive()) {
                continue;
            }
            $submerged = $this->entityEnvironment->isSubmerged($entity);
            if ($entity instanceof AquaticRuntimeState) {
                $entity->advanceAquaticState($submerged);
                $dryGraceTicks = match ($entity->getType()->identifier()) {
                    VanillaEntityType::DOLPHIN->value => 240,
                    VanillaEntityType::AXOLOTL->value => 6_000,
                    default => 100,
                };
                $drowning = !$entity->canBreatheUnderwater() && $entity->getAirSupplyTicks() === 0;
                $stranded = $entity->requiresWater() && !$submerged && $entity->getDryTicks() > $dryGraceTicks;
            } else {
                $entity->advanceBreathingState(
                    $submerged,
                    VanillaEffectBehavior::canBreatheUnderwater($entity->effectState()->snapshot()),
                );
                $drowning = $submerged && $entity->getBreathingAirSupplyTicks() === 0;
                $stranded = false;
            }
            if ((!$drowning && !$stranded) || ($this->tick + $entity->getRuntimeId()) % 20 !== 0) {
                continue;
            }
            $damageEvent = $this->pluginEvents?->entityDamage(
                $entity,
                ApiEntityDamageCause::DROWNING,
                self::DROWNING_DAMAGE,
            );
            if ($this->pluginEvents !== null && $damageEvent === null) {
                continue;
            }
            $result = $this->entityRuntime->damage(
                $entity->getRuntimeId(),
                $damageEvent?->damage() ?? self::DROWNING_DAMAGE,
            );
            if ($result !== null && $result->appliedDamage > 0.0) {
                if ($damageEvent !== null) {
                    $this->entityLastDamageEvents[$entity->getRuntimeId()] = $damageEvent;
                }
                $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
                $events[] = new EntityActorDamaged($entity, $this->tick, $recipients);
            }
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceEntityFire(): array
    {
        if ($this->entityEnvironment === null) {
            return [];
        }
        $events = [];
        $recipients = $this->players->recipients();
        foreach ($this->entityRuntime->registry()->all() as $entity) {
            if (!$entity instanceof AbstractLivingEntity || !$entity->isAlive()) {
                continue;
            }
            $wasOnFire = $entity->isOnFire();
            if ($entity instanceof MagmaCubeEntity) {
                $entity->extinguish();
            }
            if (VanillaEffectBehavior::hasFireResistance($entity->effectState()->snapshot())) {
                $entity->extinguish();
            }
            if ($wasOnFire && $this->entityEnvironment->isTouchingWater($entity)) {
                $entity->extinguish();
            }
            if ($entity->isOnFire()
                && $this->blockWorld?->weather()->weather->isRaining() === true
                && ($this->tick + $entity->getRuntimeId()) % 20 === 0
                && $this->entityEnvironment->hasSkyExposure($entity)) {
                $entity->extinguish();
            }
            if ($entity->isOnFire() && $entity->advanceFireTick()) {
                $damageEvent = $this->pluginEvents?->entityDamage(
                    $entity,
                    ApiEntityDamageCause::FIRE_TICK,
                    self::FIRE_TICK_DAMAGE,
                );
                if ($this->pluginEvents === null || $damageEvent !== null) {
                    $damage = $damageEvent?->damage() ?? self::FIRE_TICK_DAMAGE;
                    $result = $damage > 0.0
                        ? $this->entityRuntime->damage($entity->getRuntimeId(), $damage)
                        : null;
                    if ($result !== null && $result->appliedDamage > 0.0) {
                        if ($damageEvent !== null) {
                            $this->entityLastDamageEvents[$entity->getRuntimeId()] = $damageEvent;
                        }
                        $this->publishedEntityHealth[$entity->getRuntimeId()] = $entity->getHealth();
                        $events[] = new EntityActorDamaged($entity, $this->tick, $recipients);
                    }
                }
            }
            if ($entity->isAlive() && !$entity->isOnFire()
                && !VanillaEffectBehavior::hasFireResistance($entity->effectState()->snapshot())
                && $this->entityEnvironment->shouldCheckDaylight($entity, $this->tick)
                && $this->entityEnvironment->hasBurningDaylightExposure($entity)
                && !$this->consumeEntityDaylightHelmet($entity)) {
                $combust = $this->pluginEvents?->combust(
                    $entity,
                    EntityCombustionCause::SUNLIGHT,
                    self::DAYLIGHT_FIRE_DURATION_TICKS,
                );
                if ($this->pluginEvents === null || $combust !== null) {
                    $entity->setOnFire($combust?->durationTicks() ?? self::DAYLIGHT_FIRE_DURATION_TICKS);
                }
            }
            if ($wasOnFire !== $entity->isOnFire()) {
                $this->publishedEntityPresentationRevisions[$entity->getRuntimeId()] =
                    $entity->presentationRevision();
                $events[] = new EntityActorMetadataChanged(
                    $entity,
                    $this->tick,
                    $recipients,
                    !$this->entityAiEnabled,
                );
            }
            if ($entity instanceof EndermanEntity
                && ($this->tick + $entity->getRuntimeId()) % 20 === 0
                && $this->entityEnvironment->isTouchingWater($entity)) {
                $damageEvent = $this->pluginEvents?->entityDamage($entity, ApiEntityDamageCause::DROWNING, 1.0);
                if ($this->pluginEvents === null || $damageEvent !== null) {
                    $result = $this->entityRuntime->damage($entity->getRuntimeId(), $damageEvent?->damage() ?? 1.0);
                    if ($result !== null && $result->appliedDamage > 0.0) {
                        $events[] = new EntityActorDamaged($entity, $this->tick, $recipients);
                    }
                }
            }
        }

        return $events;
    }

    private function reconcileEntityTarget(AbstractMobEntity $entity): void
    {
        if ($this->pluginEvents === null || $entity->getCategory() !== EntityCategory::MONSTER) {
            return;
        }
        $runtimeId = $entity->getRuntimeId();
        $memory = $entity->aiRuntime()->memory();
        $candidate = $memory->get(VanillaAiMemories::nearestPlayer(), $this->tick);
        $candidatePlayer = $candidate instanceof AiPlayerSnapshot
            ? $this->players->playerByIdentity($candidate->playerId)
            : null;
        $candidateTarget = $candidatePlayer === null ? null : $this->pluginEvents->playerView($candidatePlayer);
        $previousTarget = $this->entityTargets[$runtimeId] ?? null;
        if (self::entityTargetKey($previousTarget) === self::entityTargetKey($candidateTarget)) {
            return;
        }
        $reason = $candidateTarget === null
            ? EntityTargetReason::FORGOT_TARGET
            : EntityTargetReason::CLOSEST_PLAYER;
        $event = $this->pluginEvents->entityTarget($entity, $previousTarget, $candidateTarget, $reason);
        if ($event->isCancelled()) {
            $this->restoreEntityTargetMemory($entity, $previousTarget);
            $entity->aiRuntime()->takeMeleeIntent($this->tick);
            $entity->aiRuntime()->takeRangedIntent($this->tick);

            return;
        }

        $target = $event->target();
        if ($target === null) {
            unset($this->entityTargets[$runtimeId]);
            $memory->forget(VanillaAiMemories::nearestPlayer());
        } else {
            $this->entityTargets[$runtimeId] = $target;
            $this->restoreEntityTargetMemory($entity, $target);
        }
        if (self::entityTargetKey($target) !== self::entityTargetKey($candidateTarget)) {
            $entity->aiRuntime()->takeMeleeIntent($this->tick);
            $entity->aiRuntime()->takeRangedIntent($this->tick);
        }
        $this->pluginEvents->entityTargetChanged($entity, $previousTarget, $target, $reason);
    }

    private function restoreEntityTargetMemory(AbstractMobEntity $entity, ApiEntity|ApiPlayer|null $target): void
    {
        $memory = $entity->aiRuntime()->memory();
        if (!$target instanceof ApiPlayer) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }
        $player = $this->players->playerByIdentity($target->uuid);
        if ($player === null || !$player->vitals->isAlive() || !$player->gameMode()->takesDamage()) {
            $memory->forget(VanillaAiMemories::nearestPlayer());

            return;
        }
        $memory->put(
            VanillaAiMemories::nearestPlayer(),
            new AiPlayerSnapshot(
                $player->identity->uuid,
                $this->worldId,
                $player->movement->position,
                true,
                $player->inventory->selectedStack()?->identifier,
            ),
            $this->tick + 30,
        );
    }

    private static function entityTargetKey(ApiEntity|ApiPlayer|null $target): ?string
    {
        return match (true) {
            $target instanceof ApiPlayer => 'player:' . $target->uuid,
            $target instanceof ApiEntity => 'entity:' . $target->getUniqueId(),
            default => null,
        };
    }

    private function consumeEntityDaylightHelmet(AbstractLivingEntity $entity): bool
    {
        $helmet = $entity->equipmentState()->getItem(ApiEquipmentSlot::HEAD);
        if ($helmet === null || $this->itemCatalog === null || !$this->itemCatalog->has($helmet->identifier)) {
            return false;
        }
        $type = $this->itemCatalog->type($helmet->identifier);
        if ($type->armor === null || $type->armor->slot !== ArmorSlot::Head) {
            return false;
        }
        $damage = $helmet->damage + 1;
        $replacement = $damage >= $type->armor->maximumDurability
            ? null
            : new ApiItemStack(
                $helmet->identifier,
                $helmet->count,
                $damage,
                $helmet->nbt,
                $helmet->auxValue,
            );
        $entity->equipmentState()->setItem(ApiEquipmentSlot::HEAD, $replacement);

        return true;
    }

    /** @return list<WorldEvent> */
    private function applyEntityMeleeIntent(AbstractMobEntity $attacker, AiMeleeIntent $intent): array
    {
        $target = $this->players->playerByIdentity($intent->targetPlayerId);
        if ($target === null || !$target->vitals->isAlive() || !$target->gameMode()->takesDamage()
            || $this->tick <= $target->vitals->invulnerableUntilTick) {
            return [];
        }
        $from = $attacker->internalPosition();
        $to = $target->movement->position;
        if (hypot(hypot($to->x - $from->x, $to->z - $from->z), $to->y - $from->y) > $intent->maximumReach
            || !$this->hostileEntityHasLineOfSight($attacker, $to)) {
            return [];
        }
        $baseDamage = $this->entityMeleeDamage($attacker, $intent->damage);
        $reducedDamage = $this->effectReducedDamage(
            $target,
            $this->armorReducedDamage($target, $baseDamage, DamageCause::Attack),
        );
        $damage = $this->pluginEvents?->damage($target, DamageCause::Attack, $reducedDamage)
            ?? ($this->pluginEvents === null ? $reducedDamage : null);
        if ($damage === null || $damage <= 0.0) {
            return [];
        }
        $applied = $target->vitals->applyDamage($damage);
        if ($applied > 0.0 && $target->effects->has(EffectType::INFESTED)) {
            $this->triggerInfestedSpawn($target->movement->position);
        }
        $target->vitals->invulnerableUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $equipmentChanged = $target->vitals->isAlive()
            && $this->damageArmor($target, $baseDamage, DamageCause::Attack);
        $length = hypot($to->x - $from->x, $to->z - $from->z);
        if ($length > 0.000_001) {
            $directionX = ($to->x - $from->x) / $length;
            $directionZ = ($to->z - $from->z) / $length;
        } else {
            $yaw = deg2rad($attacker->getYaw());
            $directionX = -sin($yaw);
            $directionZ = cos($yaw);
        }
        $movement = $target->movement;
        [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
            $movement->velocityX,
            $movement->verticalVelocity,
            $movement->velocityZ,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE,
            CombatRules::KNOCKBACK_FORCE,
            $movement->verticalState === VerticalState::GROUNDED,
            $target->inventory->knockbackResistance(),
        );
        $knockback = new ApiKnockbackVector($motionX, $motionY, $motionZ);
        if ($this->pluginEvents !== null) {
            $knockback = $this->pluginEvents->knockback(
                $this->pluginEvents->playerView($target),
                $attacker,
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        if ($knockback !== null) {
            $motionX = $knockback->x;
            $motionY = $knockback->y;
            $motionZ = $knockback->z;
            $movement->velocityX = $motionX;
            $movement->verticalVelocity = $motionY;
            $movement->velocityZ = $motionZ;
            if ($motionY > 0.0) {
                $movement->verticalState = VerticalState::AIRBORNE;
            }
            $movement->jumpAuthorizedUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
            $target->markDirty();
            $this->pluginEvents?->knockedBack(
                $this->pluginEvents->playerView($target),
                $attacker,
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        $this->pluginEvents?->damaged($target, DamageCause::Attack, $applied);

        if ($applied > 0.0 && $target->vitals->isAlive() && $attacker instanceof CaveSpiderEntity) {
            $duration = match ($this->blockWorld?->difficulty() ?? 2) {
                1 => 0,
                3 => 300,
                default => 140,
            };
            if ($duration > 0) {
                $effectEvent = $this->addPlayerEffect(new AddPlayerEffect(
                    $target->sessionId,
                    new EffectInstance(EffectType::POISON, $duration),
                    EffectCause::ENTITY_ATTACK,
                ));
                if ($effectEvent !== null) {
                    $this->deferredEvents[] = $effectEvent;
                }
            }
        }

        $recipients = $this->players->recipients();
        $events = [
            new PlayerDamaged(
                $target->snapshot(),
                $applied,
                DamageCause::Attack,
                $recipients,
                $equipmentChanged,
            ),
        ];
        if ($knockback !== null) {
            $events[] = new PlayerKnockedBack(
                $target->sessionId,
                $target->snapshot(),
                $motionX,
                $motionY,
                $motionZ,
                $movement->clientTick,
                $recipients,
            );
        }
        $events[] = new EntityActorAttackStarted($attacker, $recipients);
        if (!$target->vitals->isAlive()) {
            $target->movement->fallDistance = 0.0;
            $events[] = $this->deathEvent($target, DamageCause::Attack, $damage, $attacker);
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function applyEntityRangedIntent(AbstractMobEntity $shooter, AiRangedIntent $intent): array
    {
        $target = $this->players->playerByIdentity($intent->targetPlayerId);
        if ($target === null || !$target->vitals->isAlive() || !$target->gameMode()->takesDamage()) {
            return [];
        }
        $held = $shooter->equipmentState()->getItem(ApiEquipmentSlot::MAIN_HAND);
        if (!$shooter instanceof WitchEntity && $held?->identifier !== 'minecraft:bow') {
            return [];
        }
        $from = $shooter->internalPosition();
        $to = $target->movement->position;
        $dx = $to->x - $from->x;
        $dz = $to->z - $from->z;
        $horizontal = hypot($dx, $dz);
        $distance = hypot($horizontal, $to->y - $from->y);
        if ($distance > $intent->maximumRange || !$this->hostileEntityHasLineOfSight($shooter, $to)) {
            return [];
        }
        $spawn = new Position($from->x, $from->y + ($shooter->collisionHeight() * 0.72), $from->z);
        $targetY = $to->y + 0.9;
        $aimY = ($targetY - $spawn->y) + ($horizontal * 0.04);
        $yaw = $horizontal < 0.000_001 ? $shooter->getYaw() : rad2deg(atan2(-$dx, $dz));
        $pitch = -rad2deg(atan2($aimY, max(0.000_001, $horizontal)));
        $shooter->moveTo(
            $shooter->getWorldName(),
            $from,
            $yaw,
            max(-90.0, min(90.0, $pitch)),
        );
        $arrowPotion = match (true) {
            $shooter instanceof BoggedEntity => PotionType::POISON,
            $shooter instanceof StrayEntity => PotionType::SLOWNESS,
            default => null,
        };
        $projectile = $shooter instanceof WitchEntity
            ? $this->projectiles->spawn(
                $shooter->getUniqueId(),
                $shooter->getRuntimeId(),
                $distance <= 3.0 ? PotionType::HARMING : PotionType::POISON,
                false,
                $spawn,
                $yaw,
                max(-90.0, min(90.0, $pitch)),
                ProjectileOwnerType::ENTITY,
            )
            : ($arrowPotion !== null ? $this->projectiles->spawnTippedArrow(
                $shooter->getUniqueId(),
                $shooter->getRuntimeId(),
                $arrowPotion,
                $spawn,
                $yaw,
                max(-90.0, min(90.0, $pitch)),
                $intent->projectileSpeed,
                ArrowPickupMode::NONE,
                ownerType: ProjectileOwnerType::ENTITY,
            ) : $this->projectiles->spawnArrow(
                $shooter->getUniqueId(),
                $shooter->getRuntimeId(),
                $spawn,
                $yaw,
                max(-90.0, min(90.0, $pitch)),
                $intent->projectileSpeed,
                ArrowPickupMode::NONE,
                ownerType: ProjectileOwnerType::ENTITY,
            ));
        $projectileIdentifier = $shooter instanceof WitchEntity ? 'minecraft:splash_potion' : 'minecraft:arrow';
        $admitted = $this->pluginEvents?->projectileLaunch($shooter, $projectile, $projectileIdentifier)
            ?? ($this->pluginEvents === null ? $projectile : null);
        if ($admitted === null) {
            $this->projectiles->remove($projectile->runtimeEntityId);

            return [];
        }
        $this->projectiles->replace($admitted);
        $this->pluginEvents?->projectileLaunched($shooter, $admitted, $projectileIdentifier);

        $recipients = $this->players->recipients();

        return [
            new EntityActorMoved($shooter, $this->tick, $recipients, false),
            new ProjectileSpawned($admitted, $recipients),
        ];
    }

    private function hostileEntityHasLineOfSight(AbstractMobEntity $attacker, Position $targetFeet): bool
    {
        if ($attacker->getCategory() !== EntityCategory::MONSTER || $this->blockCollisions === null) {
            return true;
        }
        $position = $attacker->internalPosition();

        return $this->blockCollisions->hasLoadedLineOfSight(
            new Position(
                $position->x,
                $position->y + ($attacker->collisionHeight() * 0.85),
                $position->z,
            ),
            new Position($targetFeet->x, $targetFeet->y + 1.62, $targetFeet->z),
        );
    }

    public function queuedCommands(): int
    {
        return $this->commands->count() + $this->lifecycleCommands->count() + count($this->movements);
    }

    public function queuedBytes(): int
    {
        return $this->queuedBytes;
    }

    public function ticksPerSecond(): int
    {
        return $this->limits->ticksPerSecond;
    }

    /** @return array{int, list<WorldEvent>} */
    private function processLifecycleCommands(int $maximumCommands): array
    {
        $events = [];
        $processed = 0;
        while (!$this->lifecycleCommands->isEmpty() && $processed < $maximumCommands) {
            $command = $this->lifecycleCommands->dequeue();
            $bytes = $command->estimatedBytes();
            $this->queuedLifecycleBytes -= $bytes;
            unset($this->pendingDisconnects[self::sessionKey($command->session)]);
            $this->queuedBytes -= $bytes;
            ++$processed;
            $event = $this->apply($command);
            if ($event !== null) {
                $events[] = $event;
            }
            array_push($events, ...$this->drainDeferredEvents());
        }

        return [$processed, $events];
    }

    /** @return list<\Bedriox\Api\Player\Player> */
    public function pluginPlayers(): array
    {
        $views = [];
        foreach ($this->players->snapshots() as $snapshot) {
            $player = $this->players->player($snapshot->sessionId);
            if ($player !== null) {
                $views[] = $this->pluginEvents?->playerView($player)
                    ?? PluginGameplayEventBridge::detachedPlayerView($player);
            }
        }

        return $views;
    }

    public function pluginPlayer(string $identity): ?\Bedriox\Api\Player\Player
    {
        $player = $this->players->playerByIdentity($identity);

        return $player === null ? null : ($this->pluginEvents?->playerView($player)
            ?? PluginGameplayEventBridge::detachedPlayerView($player));
    }

    /** Returns the world-owned aggregate for main-thread orchestration only. */
    public function authoritativePlayer(string $identity): ?Player
    {
        return $this->players->playerByIdentity($identity);
    }

    /** Applies the cancellable teleport event without mutating this world's authoritative state. */
    public function authorizeTransferTeleport(
        Player $player,
        Position $destination,
        float $yaw,
        float $pitch,
    ): ?PlayerTeleportDecision {
        return $this->pluginEvents === null
            ? new PlayerTeleportDecision($destination, $yaw, $pitch)
            : $this->pluginEvents->teleport($player, $destination, $yaw, $pitch);
    }

    /** Publishes the post-teleport event after destination ownership has committed. */
    public function publishTransferredTeleport(Player $player, Position $from): void
    {
        $this->pluginEvents?->teleported($player, $from);
    }

    public function pluginWorldContainerView(BlockPosition $position): ?ApiContainerView
    {
        $resolved = $this->resolveWorldContainer($position);

        return $resolved === null ? null : self::containerInventoryView(
            $resolved->type,
            $resolved->inventory,
            $resolved->position,
            $resolved->pairedPosition,
            $resolved->customName,
        );
    }

    /** @param list<ApiItemStack|null> $contents */
    public function pluginReplaceWorldContainer(
        BlockPosition $position,
        array $contents,
        string $expectedRevision,
    ): bool {
        $resolved = $this->resolveWorldContainer($position);
        if ($resolved === null || !hash_equals($resolved->inventory->revision(), $expectedRevision)) {
            return false;
        }
        $this->validateApiContainerContents($contents, $resolved->inventory->size());
        try {
            if ($this->worldContainers === null
                || !$this->worldContainers->replaceAndPersist($resolved, $contents, $expectedRevision)) {
                return false;
            }
            if ($resolved->type === ApiContainerType::BREWING_STAND) {
                $this->scheduleBrewingStand($resolved->position);
            }
            if (in_array($resolved->type, [ApiContainerType::FURNACE, ApiContainerType::BLAST_FURNACE, ApiContainerType::SMOKER], true)) {
                $this->scheduleFurnace($resolved->position);
            }
        } catch (ContainerRevisionMismatchException) {
            return false;
        }
        $this->synchronizeContainerInventory($resolved->inventory);

        return true;
    }

    public function createPluginVirtualContainer(
        string $plugin,
        ApiContainerLayout $layout,
        ?string $title,
    ): string {
        if (count($this->virtualContainers) >= self::MAXIMUM_VIRTUAL_CONTAINERS) {
            throw new OverflowException('Virtual container capacity has been reached.');
        }
        do {
            if ($this->nextVirtualContainerId === PHP_INT_MAX) {
                $this->nextVirtualContainerId = 1;
            }
            $identifier = 'virtual/' . $this->nextVirtualContainerId++;
        } while (isset($this->virtualContainers[$identifier]));
        $this->virtualContainers[$identifier] = new VirtualContainer(
            $identifier,
            $plugin,
            $layout,
            $title,
            new SimpleContainerInventory($identifier, $layout->size()),
        );

        return $identifier;
    }

    public function removePluginVirtualContainer(string $plugin, string $identifier): bool
    {
        $virtual = $this->virtualContainers[$identifier] ?? null;
        if (!$virtual instanceof VirtualContainer || strcasecmp($virtual->ownerPlugin, $plugin) !== 0) {
            return false;
        }
        foreach ($this->openContainers as $key => $session) {
            if ($session->inventory->identifier() !== $identifier) {
                continue;
            }
            $player = $this->players->player(substr($key, strlen('session:')));
            if ($player === null) {
                continue;
            }
            $closed = $this->closeContainer($player, ApiInventoryCloseReason::PLUGIN, true);
            if ($closed !== null) {
                $this->deferredEvents[] = $closed;
            }
        }
        unset($this->virtualContainers[$identifier]);

        return true;
    }

    public function pluginVirtualContainerView(string $plugin, string $identifier): ?ApiContainerView
    {
        $virtual = $this->ownedVirtualContainer($plugin, $identifier);

        return $virtual === null ? null : self::containerInventoryView(
            ApiContainerType::VIRTUAL,
            $virtual->inventory,
            title: $virtual->title,
        );
    }

    /** @param list<ApiItemStack|null> $contents */
    public function pluginReplaceVirtualContainer(
        string $plugin,
        string $identifier,
        array $contents,
        string $expectedRevision,
    ): bool {
        $virtual = $this->ownedVirtualContainer($plugin, $identifier);
        if ($virtual === null || !hash_equals($virtual->inventory->revision(), $expectedRevision)) {
            return false;
        }
        $this->validateApiContainerContents($contents, $virtual->inventory->size());
        try {
            if (!$virtual->inventory->replaceContents($contents, $expectedRevision)) {
                return false;
            }
        } catch (ContainerRevisionMismatchException) {
            return false;
        }
        $this->synchronizeContainerInventory($virtual->inventory);

        return true;
    }

    public function pluginOpenWorldContainer(string $identity, BlockPosition $position): bool
    {
        $player = $this->players->playerByIdentity($identity);
        if ($player === null || $this->blockWorld === null) {
            return false;
        }
        $identifier = $this->blockIdentifier($this->blockWorld->blockStateAt(
            $position->x,
            $position->y,
            $position->z,
        )->value);
        $event = $this->openWorldContainer($player, $position, $identifier);
        if ($event instanceof CommandRejected) {
            return false;
        }
        $this->deferredEvents[] = $event;

        return true;
    }

    public function pluginCloseWorldContainer(string $identity, BlockPosition $position): bool
    {
        $player = $this->players->playerByIdentity($identity);
        $session = $player === null ? null : $this->openContainers[self::sessionKey($player->sessionId)] ?? null;
        if (!$player instanceof Player || !$session instanceof PlayerContainerSession
            || ($session->position?->equals($position) !== true
                && $session->pairedPosition?->equals($position) !== true)) {
            return false;
        }
        $event = $this->closeContainer($player, ApiInventoryCloseReason::PLUGIN, true);
        if ($event === null) {
            return false;
        }
        $this->deferredEvents[] = $event;

        return true;
    }

    public function pluginOpenVirtualContainer(string $plugin, string $identifier, string $identity): bool
    {
        $virtual = $this->ownedVirtualContainer($plugin, $identifier);
        $player = $this->players->playerByIdentity($identity);
        if ($virtual === null || $player === null) {
            return false;
        }
        $event = $this->openContainerInventory(
            $player,
            ApiContainerType::VIRTUAL,
            $virtual->inventory,
            title: $virtual->title,
            layout: $virtual->layout,
            owningPlugin: $virtual->ownerPlugin,
        );
        if ($event instanceof CommandRejected) {
            return false;
        }
        $this->deferredEvents[] = $event;

        return true;
    }

    public function pluginCloseVirtualContainer(string $plugin, string $identifier, string $identity): bool
    {
        $virtual = $this->ownedVirtualContainer($plugin, $identifier);
        $player = $this->players->playerByIdentity($identity);
        $session = $player === null ? null : $this->openContainers[self::sessionKey($player->sessionId)] ?? null;
        if ($virtual === null || !$player instanceof Player || !$session instanceof PlayerContainerSession
            || $session->inventory !== $virtual->inventory) {
            return false;
        }
        $event = $this->closeContainer($player, ApiInventoryCloseReason::PLUGIN, true);
        if ($event === null) {
            return false;
        }
        $this->deferredEvents[] = $event;

        return true;
    }

    public function enqueuePluginMessage(string $identity, string $message): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null && $this->enqueue($this->validator->pluginMessage($player->sessionId, $message));
    }

    public function enqueuePluginArmSwing(string $identity): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->swingArm($player->sessionId, ArmSwingSource::Plugin));
    }

    public function enqueuePluginTeleport(string $identity, Position $position): bool
    {
        return $this->enqueueTeleport($identity, $position);
    }

    public function enqueueTeleport(
        string $identity,
        Position $position,
        ?float $yaw = null,
        ?float $pitch = null,
    ): bool {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null && $this->enqueue($this->validator->teleport(
            $player->sessionId,
            $position->x,
            $position->y,
            $position->z,
            $yaw,
            $pitch,
        ));
    }

    public function enqueuePluginDamage(string $identity, float $amount): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->damage($player->sessionId, $amount, DamageCause::Plugin));
    }

    public function enqueuePluginEffect(string $identity, EffectInstance $effect, EffectCause $cause): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->addPlayerEffect($player->sessionId, $effect, $cause));
    }

    public function enqueuePluginEffectRemoval(string $identity, EffectType $type, EffectCause $cause): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->removePlayerEffect($player->sessionId, $type, $cause));
    }

    public function enqueuePluginEffectClear(string $identity, EffectCause $cause): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->clearPlayerEffects($player->sessionId, $cause));
    }

    public function enqueuePlayerExperience(string $identity, int $totalPoints, \Bedriox\Api\Player\ExperienceChangeCause $cause): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->setPlayerExperience($player->sessionId, $totalPoints, $cause));
    }

    public function enqueuePluginBlock(string $plugin, BlockPosition $position, string $identifier): bool
    {
        return $this->enqueue($this->validator->pluginBlock($plugin, $position, $identifier));
    }

    /** @param list<string>|null $targetIdentities */
    public function enqueuePluginParticle(
        string $plugin,
        Position $position,
        \Bedriox\Api\World\Particle\Particle $particle,
        ?array $targetIdentities,
    ): bool {
        return $this->enqueue($this->validator->pluginParticle($plugin, $position, $particle, $targetIdentities));
    }

    public function enqueuePluginInventorySlot(string $identity, int $slot, ?InventoryStack $stack): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->pluginInventorySlot($player->sessionId, $slot, $stack));
    }

    /** @param array<int, InventoryStack|null> $contents */
    public function enqueuePluginInventoryContents(string $identity, array $contents): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->pluginInventoryContents($player->sessionId, $contents));
    }

    /** @param array<int, InventoryStack|null> $contents */
    public function enqueuePluginArmorContents(string $identity, array $contents): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->pluginArmorContents($player->sessionId, $contents));
    }

    public function enqueuePluginInventoryRemoval(string $identity, InventoryStack $stack): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->removePluginInventoryStack($player->sessionId, $stack));
    }

    public function enqueuePluginEquipmentSlot(
        string $identity,
        ApiEquipmentSlot $slot,
        ?InventoryStack $stack,
    ): bool {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->pluginEquipmentSlot($player->sessionId, $slot, $stack));
    }

    public function enqueuePluginSelectedHotbarSlot(string $identity, int $slot): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null && $this->enqueue($this->validator->selectHotbarSlot($player->sessionId, $slot));
    }

    public function enqueueGameMode(string $identity, GameMode $gameMode): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->changeGameMode($player->sessionId, $gameMode));
    }

    public function mountedVehicle(string $identity): ?ApiEntity
    {
        $player = $this->players->playerByIdentity($identity);

        return $player === null ? null : $this->mounts->playerLink($player->sessionId)?->vehicle;
    }

    public function enqueueMount(string $identity, ApiEntity $vehicle, MountSeat $seat = MountSeat::DRIVER): bool
    {
        $player = $this->players->playerByIdentity($identity);
        if ($player === null || !$vehicle instanceof AbstractEntity
            || $this->entityRuntime->registry()->getByRuntimeId($vehicle->getRuntimeId()) !== $vehicle) {
            return false;
        }

        return $this->enqueue($this->validator->mountPlayer(
            $player->sessionId,
            $vehicle->getRuntimeId(),
            $vehicle->getUniqueId(),
            $seat,
            MountReason::PLUGIN,
        ));
    }

    public function enqueueDismount(string $identity): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->dismountPlayer($player->sessionId, MountReason::PLUGIN));
    }

    public function enqueueGiveItem(
        string $identity,
        string $identifier,
        int $amount,
        int $damage = 0,
        ?\Bedriox\Api\Inventory\ItemNbt $nbt = null,
        int $auxValue = 0,
    ): bool {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->giveItem(
                $player->sessionId,
                $identifier,
                $amount,
                $damage,
                $nbt,
                $auxValue,
            ));
    }

    public function enqueueKillPlayer(string $identity): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->damage($player->sessionId, 1_000_000.0, DamageCause::Kill));
    }

    public function enqueueKillEntity(int $runtimeId, string $uniqueId): bool
    {
        return $this->enqueue($this->validator->damageEntity(
            'command:kill',
            $runtimeId,
            $uniqueId,
            1_000_000.0,
            ApiEntityDamageCause::KILL,
        ));
    }

    private function apply(WorldCommand $command): ?WorldEvent
    {
        $player = $this->players->player($command->sessionId());
        if ($player !== null && !$player->vitals->isAlive()
            && !$command instanceof RespawnPlayer
            && !$command instanceof AcknowledgeRespawn
            && !$command instanceof DisconnectPlayer) {
            return new CommandRejected($command->sessionId(), 'player_dead');
        }

        $event = match (true) {
            $command instanceof JoinPlayer => $this->join($command),
            $command instanceof SendChat => $this->chat($command),
            $command instanceof PerformEmote => $this->emote($command),
            $command instanceof SwingArm => $this->swingArm($command),
            $command instanceof BreakBlock => $this->breakBlock($command),
            $command instanceof PlaceBlock => $this->placeBlock($command),
            $command instanceof ApplyInventoryStackRequest => $this->inventoryStackRequest($command),
            $command instanceof DropItem => $this->dropItem($command),
            $command instanceof AttackPlayer => $this->attack($command),
            $command instanceof InteractEntity => $this->interactEntity($command),
            $command instanceof MountPlayer => $this->mountPlayer($command),
            $command instanceof DismountPlayer => $this->dismountPlayer($command),
            $command instanceof ChangeGameMode => $this->changeGameMode($command),
            $command instanceof GiveItem => $this->giveItem($command),
            $command instanceof SyncInventory => $this->syncInventory($command),
            $command instanceof SyncInventorySlots => $this->syncInventorySlots($command),
            $command instanceof CloseCraftingGrid => $this->closeCraftingGrid($command),
            $command instanceof CloseContainer => $this->closeContainerCommand($command),
            $command instanceof SelectHotbarSlot => $this->selectHotbarSlot($command),
            $command instanceof DisconnectPlayer => $this->disconnect($command),
            $command instanceof SendPluginMessage => $this->pluginMessage($command),
            $command instanceof TeleportPlayer => $this->pluginTeleport($command),
            $command instanceof SetPluginBlock => $this->pluginBlock($command),
            $command instanceof SpawnPluginParticle => $this->pluginParticle($command),
            $command instanceof SetPluginInventorySlot => $this->pluginInventorySlot($command),
            $command instanceof SetPluginInventoryContents => $this->pluginInventoryContents($command),
            $command instanceof SetPluginArmorContents => $this->pluginArmorContents($command),
            $command instanceof RemovePluginInventoryStack => $this->removePluginInventoryStack($command),
            $command instanceof SetPluginEquipmentSlot => $this->pluginEquipmentSlot($command),
            $command instanceof DamageEntity => $this->damageEntity($command),
            $command instanceof DamagePlayer => $this->damage($command),
            $command instanceof AddPlayerEffect => $this->addPlayerEffect($command),
            $command instanceof RemovePlayerEffect => $this->removePlayerEffect($command),
            $command instanceof ClearPlayerEffects => $this->clearPlayerEffects($command),
            $command instanceof SetPlayerExperience => $this->setPlayerExperience($command),
            $command instanceof RespawnPlayer => $this->respawn($command),
            $command instanceof AcknowledgeRespawn => $this->acknowledgeRespawn($command),
            $command instanceof UseItem => $this->useItem($command),
            $command instanceof ReleaseItem => $this->releaseItem($command),
            default => null,
        };

        $this->reconcileActiveItemUse($this->players->player($command->sessionId()));

        return $event;
    }

    private function setPlayerExperience(SetPlayerExperience $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $previous = $player->experience->snapshot();
        $requested = $this->pluginEvents === null
            ? new \Bedriox\Api\Player\ExperienceSnapshot($command->totalPoints)
            : $this->pluginEvents->experienceChange($player, $command->totalPoints, $command->cause);
        if ($requested === null) {
            return new CommandRejected($command->session, 'experience_change_cancelled');
        }
        $player->experience->setTotalPoints($requested->totalPoints);
        $event = new PlayerExperienceChanged($player->snapshot(), $previous, $command->cause);
        $this->pluginEvents?->experienceChanged($player, $previous, $command->cause);

        return $event;
    }

    private function changeGameMode(ChangeGameMode $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $requested = $command->gameMode;
        if ($requested === $player->gameMode()) {
            return new CommandRejected($command->session, 'gamemode_unchanged');
        }
        if ($this->pluginEvents !== null) {
            $requested = $this->pluginEvents->gameModeChange($player, $requested);
            if ($requested === null) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
        }
        $previous = $player->setGameMode($requested);
        $this->deferItemUseCancellation($player, ItemUseCancellationReason::GAME_MODE_CHANGED);
        $player->movement->fallDistance = 0.0;
        if ($requested === GameMode::SPECTATOR) {
            $player->movement->verticalState = VerticalState::AIRBORNE;
        } elseif ($this->collisionResolver !== null) {
            $player->movement->verticalState = $this->collisionResolver->isGrounded($player->movement->position)
                ? VerticalState::GROUNDED
                : VerticalState::AIRBORNE;
        }
        if ($previous !== $requested) {
            $this->pluginEvents?->gameModeChanged($player, $previous);
        }

        return new PlayerGameModeChanged(
            $player->snapshot(),
            $previous,
            $requested,
            $this->tick,
            $this->players->recipients(),
        );
    }

    private function giveItem(GiveItem $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null || $this->itemCatalog === null || !$this->itemCatalog->has($command->identifier)) {
            return new CommandRejected($command->session, 'unsupported_item');
        }
        $inventoryBefore = clone $player->inventory;
        $proposed = clone $player->inventory;
        $type = $this->itemCatalog->type($command->identifier);
        $placed = $type->placedBlockState === null || $this->blockStateRegistry === null
            ? null
            : $this->blockStateRegistry->internalId($type->placedBlockState);
        $prototype = new InventoryStack(
            $command->identifier,
            1,
            1,
            $placed,
            $command->damage,
            $command->nbt,
            $command->auxValue,
        );
        $overflow = max(0, $command->amount - $proposed->addableQuantity($prototype));
        $requiredEntities = (int) ceil($overflow / $type->maximumStackSize);
        if ($requiredEntities > self::MAXIMUM_ITEM_ENTITIES_SPAWNED_PER_COMMAND
            || $requiredEntities > $this->itemEntities->remainingCapacity()) {
            return new CommandRejected($command->session, 'item_entity_capacity');
        }
        $remaining = $command->amount;
        $overflowStacks = [];
        while ($remaining > 0) {
            $count = min($remaining, $type->maximumStackSize);
            $overflow = $proposed->add(new InventoryStack(
                $command->identifier,
                $count,
                1,
                $placed,
                $command->damage,
                $command->nbt,
                $command->auxValue,
            ));
            $remaining -= $count;
            if ($overflow !== null) {
                $overflowStacks[] = $overflow;
            }
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowInventoryChange($player, $inventoryBefore, $proposed)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $player->inventory->replaceMainContents($proposed->slots());
        foreach ($overflowStacks as $overflowStack) {
            $entity = $this->itemEntities->spawn(
                $overflowStack,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.0,
                    $player->movement->position->z,
                ),
                new ItemEntityMotion(0.0, 0.1, 0.0),
                10,
            );
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
        }
        $player->markDirty();
        $this->pluginEvents?->inventoryChanged($player, $inventoryBefore);
        $affected = self::changedMainInventorySlots($inventoryBefore, $player->inventory);

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            $affected,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            !self::sameInventoryStack(
                $inventoryBefore->selectedStack(),
                $player->inventory->selectedStack(),
            ),
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function dropItem(DropItem $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new InventoryStackRequestProcessed(
                $command->session,
                $command->requestId,
                false,
                [$command->source],
                array_fill(0, PlayerInventory::SLOT_COUNT, null),
                null,
                0,
                null,
                false,
                0,
                [],
                'not_joined',
                $command->responseMode,
            );
        }
        if ($command->source->container === InventoryContainer::OpenedContainer) {
            return $this->dropOpenedContainerItem($player, $command);
        }
        $stack = match ($command->source->container) {
            InventoryContainer::Main => $player->inventory->stackAt($command->source->slot),
            InventoryContainer::Cursor => $player->inventory->cursorStack(),
            InventoryContainer::Armor => $player->inventory->armorStack($command->source->slot),
            InventoryContainer::Offhand => $player->inventory->offhandStack(),
            InventoryContainer::CraftingInput => $player->inventory->craftingStack($command->source->slot),
            default => null,
        };
        $reason = match (true) {
            $player->gameMode() === GameMode::SPECTATOR => 'gamemode',
            $stack === null => 'source_count',
            !$this->itemEntities->canSpawn() => 'item_entity_capacity',
            default => null,
        };
        $dropCount = $command->count;
        if ($reason === null && $this->pluginEvents !== null) {
            $dropCount = $this->pluginEvents->dropItem(
                $player,
                $stack->withCountAndNetworkId($dropCount, $stack->stackNetworkId),
            ) ?? 0;
            if ($dropCount === 0) {
                $reason = 'plugin_cancelled';
            }
        }

        $before = clone $player->inventory;
        $proposed = clone $player->inventory;
        if ($reason === null) {
            $preview = $proposed->removeForDrop(
                $command->requestId,
                $command->source,
                $dropCount,
                $command->expectedStack,
            );
            if (!$preview->success) {
                $reason = $preview->reason;
            }
        }
        if ($reason === null && $this->pluginEvents !== null
            && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
            $reason = 'plugin_cancelled';
        }

        $result = null;
        if ($reason === null) {
            $result = $player->inventory->removeForDrop(
                $command->requestId,
                $command->source,
                $dropCount,
                $command->expectedStack,
            );
            if (!$result->success || $result->removed === null) {
                $reason = $result->reason === '' ? 'drop_rejected' : $result->reason;
            }
        }
        if ($reason === null && $result !== null) {
            $yaw = deg2rad($player->movement->yaw);
            $pitch = deg2rad($player->movement->pitch);
            $horizontal = cos($pitch) * 0.4;
            $entity = $this->itemEntities->spawn(
                $result->removed,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.3,
                    $player->movement->position->z,
                ),
                new ItemEntityMotion(-sin($yaw) * $horizontal, -sin($pitch) * 0.4, cos($yaw) * $horizontal),
                40,
            );
            $player->markDirty();
            if ($this->pluginEvents !== null) {
                $this->pluginEvents->inventoryChanged($player, $before);
                $this->pluginEvents->droppedItem($player, $result->removed);
            }
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
        }

        $affected = [$command->source];
        return new InventoryStackRequestProcessed(
            $command->session,
            $command->requestId,
            $reason === null,
            $affected,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            $reason === null && $result->selectedStackChanged,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            $reason ?? '',
            $command->responseMode,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function dropOpenedContainerItem(Player $player, DropItem $command): InventoryStackRequestProcessed
    {
        $session = $this->openContainers[self::sessionKey($player->sessionId)] ?? null;
        if (!$session instanceof PlayerContainerSession) {
            return new InventoryStackRequestProcessed(
                $player->sessionId,
                $command->requestId,
                false,
                [$command->source],
                $player->inventory->slots(),
                $player->inventory->cursorStack(),
                $player->inventory->selectedHotbarSlot(),
                $player->inventory->selectedStack(),
                false,
                $player->runtimeActorId,
                $this->players->recipients($player->sessionId),
                'container_not_open',
                $command->responseMode,
                armorInventory: $player->inventory->armorSlots(),
                offhandStack: $player->inventory->offhandStack(),
                craftingInventory: $player->inventory->craftingSlots(),
            );
        }
        $reason = null;
        if (!hash_equals($session->canonicalRevision, $session->inventory->revision())) {
            $reason = 'container_revision';
            $this->refreshContainerProjection($player, $session);
        } elseif ($command->source->slot < 0 || $command->source->slot >= $session->projection->size) {
            $reason = 'slot';
        } elseif ($command->responseMode === InventoryResponseMode::ItemStackResponse
            && $command->source->expectedStackNetworkId === 0) {
            $reason = 'stack_network_id';
        }
        $stack = $reason === null ? $session->projection->stackAt($command->source->slot) : null;
        if ($reason === null && $player->gameMode() === GameMode::SPECTATOR) {
            $reason = 'gamemode';
        } elseif ($reason === null && $stack === null) {
            $reason = 'source_count';
        } elseif ($reason === null && !$this->itemEntities->canSpawn()) {
            $reason = 'item_entity_capacity';
        }
        $dropCount = $command->count;
        if ($reason === null && $this->pluginEvents !== null) {
            $dropCount = $this->pluginEvents->dropItem(
                $player,
                $stack->withCountAndNetworkId($dropCount, $stack->stackNetworkId),
            ) ?? 0;
            if ($dropCount === 0) {
                $reason = 'plugin_cancelled';
            }
        }

        $beforePlayer = clone $player->inventory;
        $beforeProjection = clone $session->projection;
        $proposedPlayer = clone $player->inventory;
        $proposedProjection = clone $session->projection;
        $result = $reason === null
            ? $proposedPlayer->removeOpenedContainerForDrop(
                $command->requestId,
                $command->source,
                $dropCount,
                $proposedProjection,
                $command->expectedStack,
            )
            : new \Bedriox\Server\Player\InventoryStackRemovalResult(false, reason: $reason);
        $transaction = null;
        if ($result->success) {
            $transaction = $this->containerDropTransactionView(
                $player,
                $command,
                $beforePlayer,
                $beforeProjection,
                $proposedPlayer,
                $proposedProjection,
                $session->inventory,
            );
            if ($this->pluginEvents !== null
                && !$this->pluginEvents->allowContainerTransaction($player, $transaction)) {
                $result = new \Bedriox\Server\Player\InventoryStackRemovalResult(
                    false,
                    reason: 'plugin_cancelled',
                );
            }
        }
        if ($result->success && $result->removed !== null) {
            try {
                if (($this->openContainers[self::sessionKey($player->sessionId)] ?? null) !== $session
                    || !hash_equals($session->canonicalRevision, $session->inventory->revision())
                    || !$player->inventory->matchesState($beforePlayer)) {
                    throw new ContainerRevisionMismatchException();
                }
                if ($session->playerOwnedEnderChest) {
                    $this->stageEnderChestContents($proposedPlayer, $proposedProjection);
                }
                $this->commitContainerContents($session, array_map(
                    static fn(?InventoryStack $item): ?ApiItemStack => $item === null
                        ? null
                        : self::apiInventoryStack($item),
                    $proposedProjection->slots(),
                ));
                if (!$player->inventory->commitStagedState($beforePlayer, $proposedPlayer)) {
                    throw new ContainerRevisionMismatchException();
                }
                $session->projection->commit(
                    $proposedProjection->indexedStacks(),
                    $proposedProjection->lastRequestIds(),
                );
                $session->canonicalRevision = $session->inventory->revision();
                if ($session->playerOwnedEnderChest) {
                    $player->markDirty();
                }
                $yaw = deg2rad($player->movement->yaw);
                $pitch = deg2rad($player->movement->pitch);
                $horizontal = cos($pitch) * 0.4;
                $entity = $this->itemEntities->spawn(
                    $result->removed,
                    new Position(
                        $player->movement->position->x,
                        $player->movement->position->y + 1.3,
                        $player->movement->position->z,
                    ),
                    new ItemEntityMotion(-sin($yaw) * $horizontal, -sin($pitch) * 0.4, cos($yaw) * $horizontal),
                    40,
                );
                if ($transaction !== null) {
                    $this->pluginEvents?->containerTransactionCommitted($player, $transaction);
                }
                $this->pluginEvents?->droppedItem($player, $result->removed);
                $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
                $this->deferContainerViewerSync($player, $session, [$command->source]);
            } catch (InvalidArgumentException|OverflowException|ContainerRevisionMismatchException) {
                $result = new \Bedriox\Server\Player\InventoryStackRemovalResult(
                    false,
                    reason: 'container_commit',
                );
                $this->refreshContainerProjection($player, $session);
            }
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            $command->requestId,
            $result->success,
            [$command->source],
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            $result->reason,
            $command->responseMode,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
            openedContainerInventory: $session->projection->slots(),
            openedContainerWindowId: $session->windowId,
        );
    }

    private function syncInventory(SyncInventory $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $affected = [];
        for ($slot = 0; $slot < PlayerInventory::SLOT_COUNT; ++$slot) {
            $affected[] = new InventorySlotReference(InventoryContainer::Main, $slot, 0);
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            $affected,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            [],
            responseMode: InventoryResponseMode::LegacySlotSync,
            fullSync: true,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function syncInventorySlots(SyncInventorySlots $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            $command->slots,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            [],
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function closeCraftingGrid(CloseCraftingGrid $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $before = clone $player->inventory;
        $gridWidth = $before->craftingGridWidth();
        $gridSlotCount = $gridWidth ** 2;
        $responseOffset = $gridWidth === 2 ? 28 : 32;
        $this->evacuateCraftingGrid($player, $this->players->recipients());
        $affected = self::changedMainInventorySlots($before, $player->inventory);
        for ($slot = 0; $slot < $gridSlotCount; ++$slot) {
            $affected[] = new InventorySlotReference(
                InventoryContainer::CraftingInput,
                $slot,
                0,
                responseSlot: $responseOffset + $slot,
            );
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            $affected,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            !self::sameInventoryStack($before->selectedStack(), $player->inventory->selectedStack()),
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: array_fill(0, $gridSlotCount, null),
        );
    }

    private function join(JoinPlayer $command): WorldEvent
    {
        if ($this->players->hasSession($command->session)) {
            return new CommandRejected($command->session, 'duplicate_session');
        }
        if ($this->players->hasIdentity($command->identity)) {
            return new CommandRejected($command->session, 'duplicate_identity');
        }
        $runtimeActorId = $command->runtimeActorId ?? $this->nextAvailableRuntimeActorId();
        if ($this->players->hasActorId($runtimeActorId)) {
            return new CommandRejected($command->session, 'duplicate_actor_id');
        }
        if ($this->players->isFull()) {
            return new CommandRejected($command->session, 'world_full');
        }
        if (!$command->loginApproved && $this->pluginEvents !== null
            && !$this->pluginEvents->allowJoin($command->displayName, $command->identity)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }

        $peers = $this->players->snapshots();
        $bootstrap = $command->bootstrap;
        $position = $this->spawn;
        $identity = new PlayerIdentity($command->identity, $command->displayName);
        $worldName = 'world';
        $firstPlayedAt = 0;
        $gamemode = 'survival';
        if ($bootstrap !== null) {
            $position = $bootstrap->position;
            $identity = $bootstrap->identity;
            $worldName = $bootstrap->worldName;
            $firstPlayedAt = $bootstrap->firstPlayedAt;
            $gamemode = $bootstrap->gamemode;
        }
        $inventory = $bootstrap !== null && $this->blockPalette !== null
            ? PlayerInventory::restore(
                $bootstrap->inventory,
                $this->blockPalette,
                $this->itemCatalog,
                $this->blockStateRegistry,
            )
            : ($this->blockPalette === null
                ? PlayerInventory::empty($this->itemCatalog)
                : PlayerInventory::starter($this->blockPalette, $this->itemCatalog));
        $player = new Player(
            $command->session,
            $runtimeActorId,
            $identity,
            $position,
            $this->limits->chatBucketCapacity,
            $this->tick,
            $this->limits->flatGroundY,
            $inventory,
            $worldName,
            $firstPlayedAt,
            $gamemode,
            $bootstrap === null ? \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH : $bootstrap->health,
            $bootstrap === null ? \Bedriox\Server\Player\PlayerVitals::MAX_FOOD : $bootstrap->food,
            $bootstrap === null ? \Bedriox\Server\Player\PlayerVitals::MAX_SATURATION : $bootstrap->saturation,
            $bootstrap === null ? 0.0 : $bootstrap->exhaustion,
            $bootstrap === null ? [] : $bootstrap->effects,
            $bootstrap === null ? 0.0 : $bootstrap->absorption,
            $bootstrap === null ? \Bedriox\Server\Player\PlayerVitals::MAX_AIR_TICKS : $bootstrap->airTicks,
            $bootstrap === null ? 0 : $bootstrap->fireTicks,
            $bootstrap === null ? null : $bootstrap->effectPersistenceState,
            $bootstrap === null ? 0 : $bootstrap->totalExperience,
        );
        if ($bootstrap !== null) {
            $player->movement->yaw = $bootstrap->yaw;
            $player->movement->headYaw = $bootstrap->yaw;
            $player->movement->pitch = $bootstrap->pitch;
        }
        if ($this->collisionResolver !== null) {
            $player->movement->verticalState = $this->collisionResolver->isGrounded($position)
                ? VerticalState::GROUNDED
                : VerticalState::AIRBORNE;
        }
        $player->markDirty();
        $this->players->add($player);
        foreach ($this->itemEntities->all() as $entity) {
            $this->deferredEvents[] = new ItemEntitySpawned($entity, [$player->sessionId]);
        }
        foreach ($this->experienceOrbs->all() as $entity) {
            $this->deferredEvents[] = new ExperienceOrbSpawned($entity, [$player->sessionId]);
        }
        foreach ($this->projectiles->all() as $projectile) {
            $this->deferredEvents[] = new ProjectileSpawned($projectile, [$player->sessionId]);
        }
        foreach ($this->areaEffectClouds->all() as $cloud) {
            $this->deferredEvents[] = new AreaEffectCloudSpawned($cloud, [$player->sessionId]);
        }
        foreach ($this->announcedEntities as $entity) {
            if ($entity->isAlive()) {
                $this->deferredEvents[] = new EntityActorSpawned(
                    $entity,
                    [$player->sessionId],
                    !$this->entityAiEnabled || ($entity instanceof AbstractMobEntity && !$entity->isAiEnabled()),
                );
            }
        }
        foreach ($this->announcedEntities as $vehicle) {
            foreach ($this->mounts->linksForVehicle($vehicle->getRuntimeId()) as $link) {
                $mountedPlayer = $link->playerSessionId === null
                    ? null
                    : $this->players->player($link->playerSessionId);
                $this->deferredEvents[] = new ActorMounted(
                    $vehicle->getRuntimeId(),
                    $link->passenger->runtimeId,
                    $link->seat,
                    $vehicle->mountedPassengerOffsetY(
                        $link->seat,
                        $mountedPlayer === null ? $link->passengerEntity?->collisionHeight() ?? 0.0 : PlayerCollisionShape::HEIGHT,
                        $mountedPlayer !== null,
                    ),
                    false,
                    $mountedPlayer?->snapshot(),
                    [$player->sessionId],
                );
            }
        }
        if ($runtimeActorId >= $this->nextRuntimeActorId && $runtimeActorId < PHP_INT_MAX) {
            $this->nextRuntimeActorId = $runtimeActorId + 1;
        }
        $this->pluginEvents?->joined($player);

        return new PlayerJoined(
            $player->snapshot(),
            $peers,
            $this->players->recipients(),
            $this->blockWorld?->weather()->weather,
        );
    }

    private function acceptMovementInput(MovePlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$player->vitals->isAlive()) {
            return new MovementCorrected($player->snapshot(), 'player_dead', clientTick: $command->clientTick);
        }
        $movement = $player->movement;
        if ($command->sequence <= $movement->sequence) {
            return new CommandRejected($command->session, 'stale_sequence');
        }
        $movement->sequence = $command->sequence;
        $movement->clientTick = $command->clientTick;
        $mount = $this->mounts->playerLink($player->sessionId);
        if ($mount !== null) {
            return $this->acceptMountedMovement($player, $mount, $command);
        }
        $previousPosition = $movement->position;
        if ($movement->budgetTick !== $this->tick) {
            $movement->budgetTick = $this->tick;
            $movement->distanceThisTick = 0.0;
        }
        $elapsed = min(
            $this->limits->maximumMovementCreditTicks,
            max(1, $this->tick - $movement->lastTick),
        );
        $distance = $movement->position->distanceTo($command->position);
        $budget = $this->limits->maximumMovementPerTick
            * VanillaEffectBehavior::movementValidationMultiplier($player->effects->snapshot())
            * $this->movementEnchantmentValidationMultiplier($player)
            * $elapsed;
        if ($distance > $budget - $movement->distanceThisTick) {
            return new MovementCorrected($player->snapshot(), 'movement_rate', clientTick: $command->clientTick);
        }

        $wasGrounded = $movement->verticalState === VerticalState::GROUNDED;
        $this->movementGroundedInputs += (int) $wasGrounded;
        $flying = ($command->flying && $player->gameMode()->allowsFlight())
            || $player->gameMode() === GameMode::SPECTATOR;
        $collidedVertically = false;
        $terrainConstrained = false;
        if ($flying) {
            $position = $command->position;
            $grounded = false;
            $stepped = false;
        } elseif ($this->collisionResolver === null) {
            if ($command->position->y < $this->limits->flatGroundY - $this->limits->flatGroundTolerance) {
                return new MovementCorrected($player->snapshot(), 'terrain_collision', clientTick: $command->clientTick);
            }
            $position = $command->position->y <= $this->limits->flatGroundY + $this->limits->flatGroundTolerance
                ? new Position($command->position->x, $this->limits->flatGroundY, $command->position->z)
                : $command->position;
            $grounded = $position->y === $this->limits->flatGroundY;
            $stepped = false;
        } else {
            $requested = $wasGrounded
                && abs($command->position->y - $movement->position->y) <= $this->limits->flatGroundTolerance
                ? new Position($command->position->x, $movement->position->y, $command->position->z)
                : $command->position;
            $collisionStartedNanoseconds = hrtime(true);
            $resolved = $this->collisionResolver->resolve(
                $movement->position,
                $requested,
                $wasGrounded,
                $command->verticalCollision,
            );
            $this->movementCollisionNanoseconds += hrtime(true) - $collisionStartedNanoseconds;
            $this->movementFastCollisions += (int) $resolved->fastPath;
            $this->movementCollisionObstacles += $resolved->obstacleCount;
            if (!$resolved->terrainLoaded) {
                return new MovementCorrected(
                    $player->snapshot(),
                    'terrain_unavailable',
                    clientTick: $command->clientTick,
                );
            }
            $position = $resolved->position;
            $grounded = $resolved->grounded;
            $stepped = $resolved->stepped;
            $collidedVertically = $resolved->collidedY;
            $terrainConstrained = $position->distanceTo($command->position) > $this->limits->flatGroundTolerance;
        }
        if (
            !$flying
            && $wasGrounded
            && $position->y > $movement->position->y + $this->limits->flatGroundTolerance
            && !$stepped
            && !$command->jumpRequested
            && $this->tick > $movement->jumpAuthorizedUntilTick
        ) {
            return new MovementCorrected($player->snapshot(), 'jump_required', clientTick: $command->clientTick);
        }

        if (
            !$flying
            && $wasGrounded
            && $grounded
            && $command->jumpRequested
        ) {
            $movement->jumpAuthorizedUntilTick = $this->tick + $this->limits->jumpAuthorizationTicks;
        }
        if (!$grounded) {
            $movement->jumpAuthorizedUntilTick = -1;
        }
        $sneaking = $command->sneaking ?? ($command->mode === MovementMode::CROUCHING);
        $sprinting = $command->sprinting ?? ($command->mode === MovementMode::SPRINTING);
        $postureChanged = $movement->sneaking !== $sneaking || $movement->sprinting !== $sprinting;
        $pluginStartedNanoseconds = hrtime(true);
        $movementAllowed = $this->pluginEvents === null || $this->pluginEvents->allowMove($player, $position);
        $this->movementPluginNanoseconds += hrtime(true) - $pluginStartedNanoseconds;
        if (!$movementAllowed) {
            return new MovementCorrected($player->snapshot(), 'plugin_cancelled', clientTick: $command->clientTick);
        }
        $velocityX = ($position->x - $previousPosition->x) / $elapsed;
        $verticalDistance = $position->y - $previousPosition->y;
        $velocityZ = ($position->z - $previousPosition->z) / $elapsed;
        $movement->position = $position;
        $movement->yaw = $command->yaw;
        $movement->headYaw = $command->headYaw ?? $command->yaw;
        $movement->pitch = $command->pitch;
        $movement->mode = $command->mode;
        $movement->sneaking = $sneaking;
        $movement->sprinting = $sprinting;
        $movement->verticalState = $grounded ? VerticalState::GROUNDED : VerticalState::AIRBORNE;
        $movement->velocityX = $velocityX;
        $movement->verticalVelocity = $grounded || $collidedVertically ? 0.0 : $verticalDistance / $elapsed;
        $movement->velocityZ = $velocityZ;
        $movement->distanceThisTick += $distance;
        $movement->lastTick = $this->tick;
        $player->markDirty();

        $horizontalDistance = hypot(
            $position->x - $previousPosition->x,
            $position->z - $previousPosition->z,
        );
        $visibleNutritionChange = null;
        if (!$flying && $sprinting && $player->gameMode()->consumesItems() && $horizontalDistance > 0.0) {
            $visibleNutritionChange = $this->applyMovementExhaustion(
                $player,
                self::SPRINTING_EXHAUSTION_PER_BLOCK * $horizontalDistance,
            );
        }

        $snapshotStartedNanoseconds = hrtime(true);
        $snapshot = $player->snapshot();
        $this->movementSnapshotNanoseconds += hrtime(true) - $snapshotStartedNanoseconds;
        if ($visibleNutritionChange !== null) {
            $this->deferredEvents[] = new NutritionChanged(
                $snapshot,
                $visibleNutritionChange->foodLevel,
                $visibleNutritionChange->saturationLevel,
                $visibleNutritionChange->exhaustionLevel,
                NutritionChangeReason::EXHAUSTION,
                [$player->sessionId],
            );
        }
        $pluginStartedNanoseconds = hrtime(true);
        $this->pluginEvents?->moved($player);
        $this->movementPluginNanoseconds += hrtime(true) - $pluginStartedNanoseconds;
        if ($flying || !$player->gameMode()->takesDamage()) {
            $movement->fallDistance = 0.0;
        } elseif ($verticalDistance < $movement->fallDistance) {
            $movement->fallDistance -= $verticalDistance;
        } else {
            $movement->fallDistance = 0.0;
        }
        if ($grounded && $movement->fallDistance > 0.0) {
            $damage = VanillaEffectBehavior::fallDamage($player->effects->snapshot(), $movement->fallDistance);
            $movement->fallDistance = 0.0;
            if ($damage > 0.0) {
                $damageEvent = $this->damage(new DamagePlayer($player->sessionId, $damage, DamageCause::Fall));
                $deathEvents = $this->drainDeferredEvents();
                $this->deferredEvents[] = $damageEvent;
                array_push($this->deferredEvents, ...$deathEvents);
            }
        }
        if ($terrainConstrained) {
            return new MovementCorrected(
                $snapshot,
                'terrain_collision',
                $this->players->recipients($player->sessionId),
                $postureChanged,
                $command->clientTick,
            );
        }
        if ($grounded) {
            $this->applyFrostWalker($player);
        }
        $openContainer = $this->openContainers[self::sessionKey($player->sessionId)] ?? null;
        if ($openContainer instanceof PlayerContainerSession && $openContainer->position !== null
            && !$this->blockIsReachable($snapshot, $openContainer->position)) {
            $closed = $this->closeContainer($player, ApiInventoryCloseReason::OUT_OF_RANGE, true);
            if ($closed !== null) {
                $this->deferredEvents[] = $closed;
            }
        }

        $recipientStartedNanoseconds = hrtime(true);
        $recipients = $this->players->recipients($player->sessionId);
        $this->movementRecipientNanoseconds += hrtime(true) - $recipientStartedNanoseconds;

        return new PlayerMoved($snapshot, $recipients, $postureChanged);
    }

    private function acceptMountedMovement(Player $player, MountLink $link, MovePlayer $command): WorldEvent
    {
        $vehicle = $link->vehicle;
        if ($vehicle->isRemoved() || !$vehicle instanceof AbstractLivingEntity || !$vehicle->isAlive()) {
            $this->forceDismountPlayer($player, MountReason::VEHICLE_REMOVED);

            return new MovementCorrected($player->snapshot(), 'vehicle_unavailable', clientTick: $command->clientTick);
        }
        if ($command->predictedVehicleActorId !== null
            && $command->predictedVehicleActorId !== $vehicle->getRuntimeId()) {
            return new MovementCorrected(
                $player->snapshot(),
                'vehicle_identity',
                clientTick: $command->clientTick,
            );
        }
        $player->movement->yaw = $command->yaw;
        $player->movement->headYaw = $command->headYaw ?? $command->yaw;
        $player->movement->pitch = $command->pitch;
        $player->movement->sneaking = $command->sneaking ?? false;
        $player->movement->sprinting = $command->sprinting ?? false;
        $player->movement->lastTick = $this->tick;
        $vehicleYaw = $command->vehicleYaw ?? $command->yaw;
        $vehicleControlYaw = $command->vehicleControlYaw ?? $vehicleYaw;
        if ($vehicle instanceof PigEntity && $link->seat->controlsVehicle()) {
            $vehicle->suppressAiMovementUntil($this->tick + 2);
            $held = $player->inventory->selectedStack();
            if ($held?->identifier === 'minecraft:carrot_on_a_stick') {
                $forward = max(-1.0, min(1.0, $command->moveZ));
                $strafe = max(-1.0, min(1.0, $command->moveX));
                $radians = deg2rad($vehicleControlYaw);
                $speed = $command->sprinting === true ? 0.24 : 0.18;
                $vehicle->applyControlledMotion(new EntityMotion(
                    (-sin($radians) * $forward + cos($radians) * $strafe) * $speed,
                    $vehicle->getMotion()->y,
                    (cos($radians) * $forward + sin($radians) * $strafe) * $speed,
                ), $this->tick);
                $vehicle->moveTo($vehicle->getWorldName(), $vehicle->internalPosition(), $vehicleYaw, 0.0);
            } else {
                $motion = $vehicle->getMotion();
                $vehicle->setMotion(new EntityMotion(0.0, $motion->y, 0.0));
            }
        } elseif ($vehicle instanceof Rideable && $vehicle instanceof Tameable && $vehicle instanceof AbstractMobEntity
            && ($vehicle->isSaddled() || $vehicle instanceof SkeletonHorseEntity)
            && $vehicle->isTamed() && $link->seat->controlsVehicle()) {
            $vehicle->suppressAiMovementUntil($this->tick + 2);
            $forward = max(-1.0, min(1.0, $command->moveZ));
            $strafe = max(-1.0, min(1.0, $command->moveX));
            $radians = deg2rad($vehicleControlYaw);
            $speed = $command->sprinting === true ? 0.30 : 0.22;
            $verticalMotion = $vehicle->getMotion()->y;
            if ($command->jumpRequested && $vehicle->isOnGround()) {
                $verticalMotion = 0.42;
            }
            $vehicle->applyControlledMotion(new EntityMotion(
                (-sin($radians) * $forward + cos($radians) * $strafe) * $speed,
                $verticalMotion,
                (cos($radians) * $forward + sin($radians) * $strafe) * $speed,
            ), $this->tick);
            $vehicle->moveTo($vehicle->getWorldName(), $vehicle->internalPosition(), $vehicleYaw, 0.0);
        }
        $this->syncMountedPlayer($player, $link);

        return new PlayerMoved($player->snapshot(), $this->players->recipients($player->sessionId));
    }

    /** Returns the previous state only when a client-visible nutrition attribute changed. */
    private function applyMovementExhaustion(Player $player, float $amount): ?ApiNutrition
    {
        if ($this->pluginEvents === null || !$this->pluginEvents->hasNutritionListeners()) {
            $previousFood = $player->vitals->food;
            $previousSaturation = $player->vitals->saturation;
            $previousExhaustion = $player->vitals->exhaustion;
            $player->vitals->exhaust($amount);
            $player->markDirty();

            return $player->vitals->food !== $previousFood || $player->vitals->saturation !== $previousSaturation
                ? new ApiNutrition((int) $previousFood, $previousSaturation, $previousExhaustion)
                : null;
        }
        $previousNutrition = PluginGameplayEventBridge::nutrition($player);
        $stagedVitals = clone $player->vitals;
        $stagedVitals->exhaust($amount);
        $proposedNutrition = new ApiNutrition(
            (int) $stagedVitals->food,
            $stagedVitals->saturation,
            $stagedVitals->exhaustion,
        );
        $proposedNutrition = $this->pluginEvents->nutritionChange(
            $player,
            $previousNutrition,
            $proposedNutrition,
            ApiFoodLevelChangeCause::EXHAUSTION,
        );
        if ($proposedNutrition === null) {
            return null;
        }
        if ($proposedNutrition == $previousNutrition) {
            return null;
        }
        $player->vitals->setNutrition(
            $proposedNutrition->foodLevel,
            $proposedNutrition->saturationLevel,
            $proposedNutrition->exhaustionLevel,
        );
        $player->markDirty();
        $currentNutrition = PluginGameplayEventBridge::nutrition($player);
        $this->pluginEvents->nutritionChanged(
            $player,
            $previousNutrition,
            $currentNutrition,
            ApiFoodLevelChangeCause::EXHAUSTION,
        );

        return $currentNutrition->foodLevel !== $previousNutrition->foodLevel
            || $currentNutrition->saturationLevel !== $previousNutrition->saturationLevel
            ? $previousNutrition
            : null;
    }

    private function damage(
        DamagePlayer $command,
        ?AbstractLivingEntity $entityAttacker = null,
    ): WorldEvent {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$player->vitals->isAlive()) {
            return new CommandRejected($command->session, 'player_dead');
        }
        $isKill = $command->cause === DamageCause::Kill;
        $attacker = $entityAttacker ?? ($command->sourceSession === null
            ? null
            : $this->players->player($command->sourceSession));
        if (!$isKill && !$player->gameMode()->takesDamage()) {
            return new CommandRejected($command->session, 'gamemode_invulnerable');
        }
        if (!$isKill && $this->tick <= $player->vitals->invulnerableUntilTick) {
            return new CommandRejected($command->session, 'damage_cooldown');
        }
        $baseDamage = $command->amount;
        $reducedDamage = $isKill ? $baseDamage : $this->effectReducedDamage(
            $player,
            $this->armorReducedDamage($player, $baseDamage, $command->cause),
        );
        $damage = $this->pluginEvents === null
            ? $reducedDamage
            : $this->pluginEvents->damage($player, $command->cause, $reducedDamage);
        if ($damage === null) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $applied = $player->vitals->applyDamage($damage);
        if ($applied > 0.0 && $player->effects->has(EffectType::INFESTED)) {
            $this->triggerInfestedSpawn($player->movement->position);
        }
        if (!$isKill) {
            $player->vitals->invulnerableUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        }
        $equipmentChanged = !$isKill && $player->vitals->isAlive()
            && $this->damageArmor($player, $baseDamage, $command->cause);
        $player->markDirty();
        $this->pluginEvents?->damaged($player, $command->cause, $applied);
        if (!$player->vitals->isAlive()) {
            $player->movement->fallDistance = 0.0;
            $this->deferredEvents[] = $this->deathEvent($player, $command->cause, $damage, $attacker);
        }

        return new PlayerDamaged(
            $player->snapshot(),
            $applied,
            $command->cause,
            $this->players->recipients(),
            $equipmentChanged,
        );
    }

    private function addPlayerEffect(AddPlayerEffect $command, bool $dispatchPreEvent = true): ?WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $effect = $dispatchPreEvent
            ? ($this->pluginEvents?->addEffect($player, $command->effect, $command->cause)
                ?? ($this->pluginEvents === null ? $command->effect : null))
            : $command->effect;
        if ($effect === null) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($effect->type === EffectType::INSTANT_HEALTH) {
            $maximumHealth = VanillaEffectBehavior::maximumHealth($player->effects->snapshot());
            $amount = min(
                (float) (4 << min(20, $effect->amplifier)) * $command->intensity,
                $maximumHealth - $player->vitals->health,
            );
            if ($amount <= 0.0) {
                return null;
            }
            $amount = $this->pluginEvents?->regainHealth($player, ApiHealthRegainCause::EFFECT, $amount)
                ?? ($this->pluginEvents === null ? $amount : 0.0);
            if ($amount <= 0.0) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            $amount = min($amount, $maximumHealth - $player->vitals->health);
            $player->vitals->health += $amount;
            $player->markDirty();
            $this->pluginEvents?->effectAdded($player, $effect, $command->cause, null);
            $this->pluginEvents?->regainedHealth($player, ApiHealthRegainCause::EFFECT, $amount);

            return new PlayerHealed(
                $player->snapshot(),
                $amount,
                HealthRegainCause::EFFECT,
                $this->players->recipients(),
            );
        }
        if ($effect->type === EffectType::INSTANT_DAMAGE) {
            $this->pluginEvents?->effectAdded($player, $effect, $command->cause, null);
            return $this->damage(new DamagePlayer(
                $player->sessionId,
                (float) (6 << min(20, $effect->amplifier)) * $command->intensity,
                DamageCause::Magic,
            ));
        }
        if ($effect->type === EffectType::SATURATION) {
            $player->vitals->addNutrition($effect->level(), 2.0 * $effect->level());
            $player->markDirty();
            $this->pluginEvents?->effectAdded($player, $effect, $command->cause, null);

            return null;
        }
        $transition = $player->effects->add($effect);
        if (!$transition->visibleStateChanged) {
            return null;
        }
        if ($effect->type === EffectType::ABSORPTION) {
            $player->vitals->setAbsorption(max(
                $player->vitals->absorption,
                VanillaEffectBehavior::absorptionCapacity($player->effects->snapshot()),
            ));
        }
        $player->markDirty();
        $this->pluginEvents?->effectAdded($player, $effect, $command->cause, $transition->previous);

        return new PlayerEffectChanged(
            $player->snapshot(),
            $effect->type,
            $transition->current,
            $this->players->recipients(),
            $this->tick,
            $transition->previous !== null,
        );
    }

    private function removePlayerEffect(RemovePlayerEffect $command, bool $dispatchPreEvent = true): ?WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $effect = $player->effects->get($command->type);
        if ($effect === null) {
            return null;
        }
        if ($dispatchPreEvent && $this->pluginEvents !== null
            && !$this->pluginEvents->allowEffectRemoval($player, $effect, $command->cause)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $transition = $player->effects->remove($command->type);
        if ($command->type === EffectType::ABSORPTION) {
            $player->vitals->setAbsorption(0.0);
        }
        if ($command->type === EffectType::HEALTH_BOOST) {
            $player->vitals->health = min(
                $player->vitals->health,
                VanillaEffectBehavior::maximumHealth($player->effects->snapshot()),
            );
        }
        $player->markDirty();
        $this->pluginEvents?->effectRemoved($player, $effect, $command->cause);

        return new PlayerEffectChanged(
            $player->snapshot(),
            $command->type,
            $transition->current,
            $this->players->recipients(),
            $this->tick,
        );
    }

    private function clearPlayerEffects(ClearPlayerEffects $command): ?WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        foreach ($player->effects->snapshot() as $effect) {
            $event = $this->removePlayerEffect(new RemovePlayerEffect($command->session, $effect->type, $command->cause));
            if ($event instanceof PlayerEffectChanged) {
                $this->deferredEvents[] = $event;
            }
        }

        return null;
    }

    private function damageEntity(DamageEntity $command): WorldEvent
    {
        $target = $this->entityRuntime->registry()->getByRuntimeId($command->runtimeId);
        if (!$target instanceof AbstractLivingEntity
            || !hash_equals($target->getUniqueId(), $command->uniqueId)
            || !$target->isAlive()) {
            return new CommandRejected($command->source, 'target_unavailable');
        }
        $damageEvent = $this->pluginEvents?->entityDamage($target, $command->cause, $command->amount);
        if ($this->pluginEvents !== null && $damageEvent === null) {
            return new CommandRejected($command->source, 'plugin_cancelled');
        }
        $damage = $damageEvent?->damage() ?? $command->amount;
        if ($damage <= 0.0) {
            return new CommandRejected($command->source, 'plugin_cancelled');
        }
        $result = $this->entityRuntime->damage($target->getRuntimeId(), $damage);
        if ($result === null || $result->appliedDamage <= 0.0) {
            return new CommandRejected($command->source, 'target_unavailable');
        }
        if ($damageEvent !== null) {
            $this->entityLastDamageEvents[$target->getRuntimeId()] = $damageEvent;
        }
        $this->publishedEntityHealth[$target->getRuntimeId()] = $target->getHealth();

        return new EntityActorDamaged($target, $this->tick, $this->players->recipients());
    }

    private function attack(AttackPlayer $command): WorldEvent
    {
        $attacker = $this->players->player($command->session);
        if ($attacker === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $target = $this->players->playerByActorId($command->targetRuntimeActorId);
        if ($target === null) {
            return $this->attackEntity($attacker, $command);
        }
        $reason = match (true) {
            !$this->pvp => 'pvp_disabled',
            $attacker->gameMode() === GameMode::SPECTATOR => 'attacker_gamemode',
            $target->sessionId === $attacker->sessionId => 'self_attack',
            !$target->vitals->isAlive() => 'target_dead',
            !$target->gameMode()->takesDamage() => 'target_gamemode',
            $command->hotbarSlot !== $attacker->inventory->selectedHotbarSlot() => 'selected_slot',
            !$this->entityIsReachable($attacker, $target) => 'reach',
            $this->tick <= $target->vitals->invulnerableUntilTick => 'damage_cooldown',
            default => null,
        };
        if ($reason !== null) {
            return new CommandRejected($command->session, $reason);
        }

        $heldEnchantments = $this->heldEnchantments($attacker);
        $baseDamage = $this->meleeDamage($attacker)
            + EnchantmentEffects::meleeDamageBonus($heldEnchantments, false, false)
            + $this->maceDensityDamage($attacker, $heldEnchantments);
        $baseDamage = max(0.0, $baseDamage + VanillaEffectBehavior::attackDamageModifier(
            $attacker->effects->snapshot(),
            $baseDamage,
        ));
        $reducedDamage = $this->effectReducedDamage(
            $target,
            $this->armorReducedDamage(
                $target,
                $baseDamage,
                DamageCause::Attack,
                $heldEnchantments[VanillaEnchantments::BREACH] ?? 0,
            ),
        );
        $damage = $this->pluginEvents?->attack($attacker, $target, $reducedDamage)
            ?? ($this->pluginEvents === null ? $reducedDamage : null);
        if ($damage === null || $damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }

        $applied = $target->vitals->applyDamage($damage);
        if ($applied > 0.0 && $target->effects->has(EffectType::INFESTED)) {
            $this->triggerInfestedSpawn($target->movement->position);
        }
        $target->vitals->invulnerableUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $equipmentChanged = $target->vitals->isAlive()
            && $this->damageArmor($target, $baseDamage, DamageCause::Attack);
        [$directionX, $directionZ] = $this->knockbackDirection($attacker, $target);
        $movement = $target->movement;
        $wasGrounded = $movement->verticalState === VerticalState::GROUNDED;
        $resistance = $target->inventory->knockbackResistance();
        [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
            $movement->velocityX,
            $movement->verticalVelocity,
            $movement->velocityZ,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE
                + ($attacker->movement->sprinting ? CombatRules::SPRINT_KNOCKBACK_BONUS : 0.0)
                + (($heldEnchantments[VanillaEnchantments::KNOCKBACK] ?? 0)
                    * EnchantmentEffects::KNOCKBACK_HORIZONTAL_BONUS_PER_LEVEL),
            CombatRules::KNOCKBACK_FORCE,
            $wasGrounded,
            $resistance,
        );
        $sprintingAttack = $attacker->movement->sprinting;
        $knockback = new ApiKnockbackVector($motionX, $motionY, $motionZ);
        if ($this->pluginEvents !== null) {
            $knockback = $this->pluginEvents->knockback(
                $this->pluginEvents->playerView($target),
                $this->pluginEvents->playerView($attacker),
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        if ($knockback !== null) {
            $motionX = $knockback->x;
            $motionY = $knockback->y;
            $motionZ = $knockback->z;
            $movement->velocityX = $motionX;
            $movement->verticalVelocity = $motionY;
            $movement->velocityZ = $motionZ;
            if ($motionY > 0.0) {
                $movement->verticalState = VerticalState::AIRBORNE;
            }
            $target->movement->jumpAuthorizedUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
            $target->markDirty();
            $this->pluginEvents?->knockedBack(
                $this->pluginEvents->playerView($target),
                $this->pluginEvents->playerView($attacker),
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        $this->damageHeldTool($attacker, $this->heldItemType($attacker), attack: true);
        $fireAspect = $heldEnchantments[VanillaEnchantments::FIRE_ASPECT] ?? 0;
        if ($applied > 0.0 && $target->vitals->isAlive() && $fireAspect > 0) {
            $target->vitals->fireTicks = max(
                $target->vitals->fireTicks,
                $this->fireProtectionAdjustedTicks(
                    $target,
                    $fireAspect * EnchantmentEffects::FIRE_ASPECT_TICKS_PER_LEVEL,
                ),
            );
            $target->markDirty();
            $this->deferredEvents[] = new PlayerEnvironmentChanged(
                $target->snapshot(),
                $this->players->recipients(),
                $this->tick,
                false,
                true,
            );
        }
        if ($applied > 0.0 && $attacker->vitals->isAlive()) {
            $equipmentChanged = $this->applyPlayerThorns($target, $attacker) || $equipmentChanged;
        }
        if ($applied > 0.0) {
            $this->applyWindBurst($attacker, $heldEnchantments);
            $this->applySpearLunge($attacker, $heldEnchantments);
        }

        $this->pluginEvents?->damaged($target, DamageCause::Attack, $applied);
        $this->pluginEvents?->attacked($attacker, $target, $applied);
        if ($knockback !== null) {
            $this->deferredEvents[] = new PlayerKnockedBack(
                $target->sessionId,
                $target->snapshot(),
                $motionX,
                $motionY,
                $motionZ,
                $movement->clientTick,
                $this->players->recipients(),
            );
        }
        if ($sprintingAttack) {
            $attacker->movement->velocityX *= CombatRules::SPRINT_ATTACKER_DAMPING;
            $attacker->movement->velocityZ *= CombatRules::SPRINT_ATTACKER_DAMPING;
            $attacker->movement->sprinting = false;
            if ($attacker->movement->mode === MovementMode::SPRINTING) {
                $attacker->movement->mode = MovementMode::WALKING;
            }
            $attacker->markDirty();
            $this->deferredEvents[] = new PlayerMotionChanged(
                $attacker->sessionId,
                $attacker->snapshot(),
                $attacker->movement->velocityX,
                $attacker->movement->verticalVelocity,
                $attacker->movement->velocityZ,
                $attacker->movement->clientTick,
                true,
                $this->players->recipients(),
            );
        }
        if (!$target->vitals->isAlive()) {
            $target->movement->fallDistance = 0.0;
            $this->deferredEvents[] = $this->deathEvent($target, DamageCause::Attack, $damage, $attacker);
        }
        $swing = $this->armSwingEvent($attacker, ArmSwingSource::Attack);
        if ($swing !== null) {
            $this->deferredEvents[] = $swing;
        }

        return new PlayerDamaged(
            $target->snapshot(),
            $applied,
            DamageCause::Attack,
            $this->players->recipients(),
            $equipmentChanged,
        );
    }

    private function attackEntity(Player $attacker, AttackPlayer $command): WorldEvent
    {
        $target = $this->entityRuntime->registry()->getByRuntimeId($command->targetRuntimeActorId);
        if (!$target instanceof AbstractLivingEntity) {
            return new CommandRejected($command->session, 'target_unavailable');
        }
        $reason = match (true) {
            $attacker->gameMode() === GameMode::SPECTATOR => 'attacker_gamemode',
            !$target->isAlive() => 'target_dead',
            $command->hotbarSlot !== $attacker->inventory->selectedHotbarSlot() => 'selected_slot',
            !$this->generalEntityIsReachable($attacker, $target) => 'reach',
            $this->tick <= ($this->entityInvulnerableUntilTicks[$target->getRuntimeId()] ?? -1) => 'damage_cooldown',
            default => null,
        };
        if ($reason !== null) {
            return new CommandRejected($command->session, $reason);
        }

        $heldEnchantments = $this->heldEnchantments($attacker);
        $baseDamage = $this->meleeDamage($attacker) + EnchantmentEffects::meleeDamageBonus(
            $heldEnchantments,
            $target instanceof Undead,
            $target instanceof Arthropod,
        ) + $this->maceDensityDamage($attacker, $heldEnchantments);
        $reducedDamage = $this->entityArmorReducedDamage(
            $target,
            $baseDamage,
            $heldEnchantments[VanillaEnchantments::BREACH] ?? 0,
        );
        $damageEvent = $this->pluginEvents?->entityDamage(
            $target,
            ApiEntityDamageCause::ATTACK,
            $reducedDamage,
            $this->pluginEvents->playerView($attacker),
        );
        if ($this->pluginEvents !== null && $damageEvent === null) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $damage = $damageEvent?->damage() ?? $reducedDamage;
        if ($damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $result = $this->entityRuntime->damage($target->getRuntimeId(), $damage);
        if ($result === null || $result->appliedDamage <= 0.0) {
            return new CommandRejected($command->session, 'target_unavailable');
        }
        if ($damageEvent !== null) {
            $this->entityLastDamageEvents[$target->getRuntimeId()] = $damageEvent;
        }
        if ($target instanceof MutableAngerState
            && (!($target instanceof Tameable) || $target->getOwnerUniqueId() !== $attacker->identity->uuid)) {
            $target->setAngerTargetUniqueId($attacker->identity->uuid, $this->dropRandom->integer(400, 800));
        }
        $this->entityInvulnerableUntilTicks[$target->getRuntimeId()] =
            $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        if (!$result->died) {
            $this->damageEntityArmor($target, $baseDamage);
        }

        $from = $attacker->movement->position;
        $to = $target->internalPosition();
        $length = hypot($to->x - $from->x, $to->z - $from->z);
        $directionX = $length > 0.000_001 ? ($to->x - $from->x) / $length : 0.0;
        $directionZ = $length > 0.000_001 ? ($to->z - $from->z) / $length : 0.0;
        $motion = $target->getMotion();
        [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
            $motion->x,
            $motion->y,
            $motion->z,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE
                + (($heldEnchantments[VanillaEnchantments::KNOCKBACK] ?? 0)
                    * EnchantmentEffects::KNOCKBACK_HORIZONTAL_BONUS_PER_LEVEL),
            CombatRules::KNOCKBACK_FORCE,
            $target->isOnGround(),
        );
        $knockback = new ApiKnockbackVector($motionX, $motionY, $motionZ);
        if ($this->pluginEvents !== null) {
            $knockback = $this->pluginEvents->knockback(
                $target,
                $this->pluginEvents->playerView($attacker),
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        if ($knockback !== null) {
            $target->setMotion(new EntityMotion($knockback->x, $knockback->y, $knockback->z));
            if ($target instanceof AbstractMobEntity) {
                $target->suppressAiMovementUntil($this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS);
            }
            $this->pluginEvents?->knockedBack(
                $target,
                $this->pluginEvents->playerView($attacker),
                ApiKnockbackCause::MELEE,
                $knockback,
            );
        }
        $this->damageHeldTool($attacker, $this->heldItemType($attacker), attack: true);
        $fireAspect = $heldEnchantments[VanillaEnchantments::FIRE_ASPECT] ?? 0;
        if ($result->appliedDamage > 0.0 && !$result->died && $fireAspect > 0) {
            $duration = $fireAspect * EnchantmentEffects::FIRE_ASPECT_TICKS_PER_LEVEL;
            $combust = $this->pluginEvents?->combust(
                $target,
                EntityCombustionCause::ENCHANTMENT,
                $duration,
            );
            if ($this->pluginEvents === null || $combust !== null) {
                $target->setOnFire($combust?->durationTicks() ?? $duration);
            }
        }
        $baneLevel = $heldEnchantments[VanillaEnchantments::BANE_OF_ARTHROPODS] ?? 0;
        if ($result->appliedDamage > 0.0 && !$result->died && $target instanceof Arthropod && $baneLevel > 0) {
            $this->applyEffectToEntity(
                $target,
                new EffectInstance(
                    EffectType::SLOWNESS,
                    $this->dropRandom->integer(20, 35) * $baneLevel,
                    3,
                ),
                EffectCause::ENTITY_ATTACK,
            );
        }
        if ($result->appliedDamage > 0.0 && $attacker->vitals->isAlive()) {
            $this->applyEntityThorns($target, $attacker);
        }
        if ($result->appliedDamage > 0.0) {
            $this->applyWindBurst($attacker, $heldEnchantments);
            $this->applySpearLunge($attacker, $heldEnchantments);
        }
        $swing = $this->armSwingEvent($attacker, ArmSwingSource::Attack);
        if ($swing !== null) {
            $this->deferredEvents[] = $swing;
        }
        $this->publishedEntityHealth[$target->getRuntimeId()] = $target->getHealth();

        return new EntityActorDamaged($target, $this->tick, $this->players->recipients());
    }

    private function interactEntity(InteractEntity $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $target = $this->entityRuntime->registry()->getByRuntimeId($command->targetRuntimeActorId);
        if (!$target instanceof AbstractLivingEntity) {
            return new CommandRejected($command->session, 'target_unavailable');
        }
        $reason = match (true) {
            $player->gameMode() === GameMode::SPECTATOR => 'player_gamemode',
            !$target->isAlive() => 'target_unavailable',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            !$this->generalEntityIsReachable($player, $target) => 'reach',
            default => null,
        };
        if ($reason !== null) {
            return new CommandRejected($command->session, $reason);
        }
        if ($this->pluginEvents !== null
            && !$this->pluginEvents->allowEntityInteract($player, $target, $command->interaction)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $heldBefore = $player->inventory->selectedStack();
        $bucketResult = $heldBefore?->identifier === 'minecraft:water_bucket'
            ? AquaticBucketRegistry::bucketForType($target->getType())
        : null;
        if ($bucketResult !== null) {
            $this->replaceConsumedContainer($player, $bucketResult);
            $this->pluginEvents?->entityInteracted($player, $target, $command->interaction, $heldBefore);
            $this->entityPersistence?->forgetEntity($target->getUniqueId());
            $this->entityRuntime->remove($target->getRuntimeId());
            $this->pluginEvents?->entityDespawned($target, 'bucket');
            $this->deferredEvents[] = new EntityActorRemoved($target, $this->players->recipients());

            return new EntityInteracted($command->session, $target->getRuntimeId(), $command->interaction);
        }
        if ($target instanceof SheepEntity
            && !$this->interactWithSheep($player, $target)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($target instanceof WolfEntity || $target instanceof CatEntity) {
            $taming = $this->interactWithTameableAnimal($player, $target);
            if ($taming === false) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            if ($taming === true) {
                $this->pluginEvents?->entityInteracted($player, $target, $command->interaction, $heldBefore);

                return new EntityInteracted($command->session, $target->getRuntimeId(), $command->interaction);
            }
        }
        if ($target instanceof BreedableAnimalEntity
            && !$this->interactWithBreedableAnimal($player, $target)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($target instanceof PigEntity && !$target->isBaby() && $target->isSaddled()
            && $this->mounts->playerLink($player->sessionId) === null
            && ($heldBefore === null || ($heldBefore->identifier !== 'minecraft:saddle'
                && !in_array($heldBefore->identifier, $this->breedingFoods($target), true)))) {
            $result = $this->mountPlayer(new MountPlayer(
                $player->sessionId,
                $target->getRuntimeId(),
                $target->getUniqueId(),
                MountSeat::DRIVER,
            ));
            if ($result instanceof ActorMounted) {
                $this->pluginEvents?->entityInteracted($player, $target, $command->interaction, $heldBefore);
            }

            return $result;
        }
        if ($target instanceof Rideable
            && ($heldBefore === null
                || !($target instanceof BreedableAnimalEntity)
                || !in_array($heldBefore->identifier, $this->breedingFoods($target), true))) {
            $result = $this->interactWithRideable($player, $target);
            if ($result !== null) {
                if ($result instanceof ActorMounted) {
                    $this->pluginEvents?->entityInteracted($player, $target, $command->interaction, $heldBefore);
                }

                return $result;
            }
        }
        $this->pluginEvents?->entityInteracted($player, $target, $command->interaction, $heldBefore);

        return new EntityInteracted(
            $command->session,
            $target->getRuntimeId(),
            $command->interaction,
        );
    }

    private function mountPlayer(MountPlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        $vehicle = $this->entityRuntime->registry()->getByRuntimeId($command->vehicleRuntimeId);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$vehicle instanceof AbstractLivingEntity || !$vehicle->isAlive()
            || $vehicle->getUniqueId() !== $command->vehicleUniqueId
            || $vehicle->getWorldName() !== $this->worldId
            || $this->mounts->playerLink($player->sessionId) !== null) {
            return new CommandRejected($command->session, 'vehicle_unavailable');
        }
        if ($vehicle instanceof Rideable && $command->seat->value >= $vehicle->getSeatCapacity()) {
            return new CommandRejected($command->session, 'seat_unavailable');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowMount(
            $this->pluginEvents->playerView($player),
            $vehicle,
            $command->seat,
            $command->reason,
        )) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $link = $this->mounts->mountPlayer($player, $vehicle, $command->seat);
        if ($link === null) {
            return new CommandRejected($command->session, 'seat_unavailable');
        }
        if ($vehicle instanceof AbstractMobEntity) {
            $vehicle->suppressAiMovementUntil($this->tick + 1);
        }
        $this->syncMountedPlayer($player, $link);
        $this->pluginEvents?->mounted(
            $this->pluginEvents->playerView($player),
            $vehicle,
            $command->seat,
            $command->reason,
        );

        return new ActorMounted(
            $vehicle->getRuntimeId(),
            $player->runtimeActorId,
            $command->seat,
            $vehicle->mountedPassengerOffsetY($command->seat, PlayerCollisionShape::HEIGHT, true),
            true,
            $player->snapshot(),
            $this->players->recipients(),
        );
    }

    private function dismountPlayer(DismountPlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        $link = $player === null ? null : $this->mounts->playerLink($player->sessionId);
        if ($player === null || $link === null) {
            return new CommandRejected($command->session, 'not_mounted');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowDismount(
            $this->pluginEvents->playerView($player),
            $link->vehicle,
            $link->seat,
            $command->reason,
        )) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $this->mounts->dismountPlayer($player->sessionId);
        $this->placeDismountedPlayer($player, $link);
        $this->pluginEvents?->dismounted(
            $this->pluginEvents->playerView($player),
            $link->vehicle,
            $link->seat,
            $command->reason,
        );

        return new ActorDismounted(
            $link->vehicle->getRuntimeId(),
            $player->runtimeActorId,
            $player->snapshot(),
            $this->players->recipients(),
        );
    }

    private function forceDismountPlayer(Player $player, MountReason $reason): void
    {
        $link = $this->mounts->playerLink($player->sessionId);
        if ($link === null) {
            return;
        }
        $this->mounts->dismountPlayer($player->sessionId);
        $this->placeDismountedPlayer($player, $link);
        $this->pluginEvents?->dismounted(
            $this->pluginEvents->playerView($player),
            $link->vehicle,
            $link->seat,
            $reason,
        );
        $this->deferredEvents[] = new ActorDismounted(
            $link->vehicle->getRuntimeId(),
            $player->runtimeActorId,
            $player->snapshot(),
            $this->players->recipients(),
        );
    }

    private function mountEntityFromController(AbstractEntity $passenger, ApiEntity $vehicle, MountSeat $seat): void
    {
        if (!$vehicle instanceof AbstractEntity || $passenger->isRemoved() || $vehicle->isRemoved()
            || $passenger->getWorldName() !== $vehicle->getWorldName()
            || $this->entityRuntime->registry()->getByRuntimeId($passenger->getRuntimeId()) !== $passenger
            || $this->entityRuntime->registry()->getByRuntimeId($vehicle->getRuntimeId()) !== $vehicle) {
            throw new InvalidArgumentException('Passenger and vehicle must be live entities in the same authoritative world.');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowMount(
            $passenger,
            $vehicle,
            $seat,
            MountReason::PLUGIN,
        )) {
            throw new \LogicException('The entity mount was cancelled.');
        }
        if ($this->mounts->mountEntity($passenger, $vehicle, $seat) === null) {
            throw new \LogicException('The requested vehicle seat is unavailable.');
        }
        $this->pluginEvents?->mounted($passenger, $vehicle, $seat, MountReason::PLUGIN);
        $this->deferredEvents[] = new ActorMounted(
            $vehicle->getRuntimeId(),
            $passenger->getRuntimeId(),
            $seat,
            $vehicle->mountedPassengerOffsetY($seat, $passenger->collisionHeight(), false),
            false,
            null,
            $this->players->recipients(),
        );
    }

    private function dismountEntityFromController(AbstractEntity $passenger): void
    {
        $link = $this->mounts->entityLink($passenger->getRuntimeId());
        if ($link === null) {
            return;
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowDismount(
            $passenger,
            $link->vehicle,
            $link->seat,
            MountReason::PLUGIN,
        )) {
            throw new \LogicException('The entity dismount was cancelled.');
        }
        $this->mounts->dismountEntity($passenger->getRuntimeId());
        $this->pluginEvents?->dismounted($passenger, $link->vehicle, $link->seat, MountReason::PLUGIN);
        $this->deferredEvents[] = new ActorDismounted(
            $link->vehicle->getRuntimeId(),
            $passenger->getRuntimeId(),
            null,
            $this->players->recipients(),
        );
    }

    private function syncMountedPlayer(Player $player, MountLink $link): void
    {
        $vehiclePosition = $link->vehicle->internalPosition();
        $player->movement->position = new Position(
            $vehiclePosition->x,
            $vehiclePosition->y + $link->vehicle->collisionHeight(),
            $vehiclePosition->z,
        );
        $player->movement->verticalVelocity = 0.0;
        $player->movement->fallDistance = 0.0;
        $player->markDirty();
    }

    private function placeDismountedPlayer(Player $player, MountLink $link): void
    {
        $vehicle = $link->vehicle->internalPosition();
        $origin = $player->movement->position;
        $candidates = [
            new Position($vehicle->x + 1.0, $vehicle->y, $vehicle->z),
            new Position($vehicle->x - 1.0, $vehicle->y, $vehicle->z),
            new Position($vehicle->x, $vehicle->y, $vehicle->z + 1.0),
            new Position($vehicle->x, $vehicle->y, $vehicle->z - 1.0),
            new Position($vehicle->x, $vehicle->y + $link->vehicle->collisionHeight(), $vehicle->z),
        ];
        $destination = $candidates[0];
        if ($this->collisionResolver !== null) {
            foreach ($candidates as $candidate) {
                $resolved = $this->collisionResolver->resolve($origin, $candidate, false, false);
                if ($resolved->terrainLoaded && $resolved->position->distanceTo($candidate) < 0.1) {
                    $destination = $resolved->position;
                    break;
                }
            }
        }
        $player->movement->position = $destination;
        $player->movement->velocityX = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->velocityZ = 0.0;
        $player->movement->fallDistance = 0.0;
        $player->markDirty();
        $this->deferredEvents[] = new MovementCorrected($player->snapshot(), 'dismounted');
    }

    private function interactWithSheep(
        Player $player,
        SheepEntity $sheep,
    ): bool {
        $held = $player->inventory->selectedStack();
        if ($held === null) {
            return true;
        }
        if ($held->identifier === 'minecraft:shears') {
            if ($sheep->isBaby() || $sheep->isSheared()) {
                return true;
            }
            $drops = [new ApiItemStack(
                $sheep->getWoolColor()->woolIdentifier(),
                $this->dropRandom->integer(1, 3),
            )];
            if ($this->pluginEvents !== null) {
                $drops = $this->pluginEvents->shearEntity($player, $sheep, $held, $drops);
                if ($drops === null) {
                    return false;
                }
            }
            $sheep->setSheared(true);
            $this->damageHeldItem($player, ApiItemDamageCause::ITEM_USE, 1);
            foreach ($drops as $drop) {
                try {
                    $item = $this->itemEntities->spawn(
                        $this->inventoryStackFromApi($drop),
                        new Position(
                            $sheep->internalPosition()->x,
                            $sheep->internalPosition()->y + 0.5,
                            $sheep->internalPosition()->z,
                        ),
                        new ItemEntityMotion(
                            $this->dropRandom->integer(-10, 10) / 100.0,
                            0.15,
                            $this->dropRandom->integer(-10, 10) / 100.0,
                        ),
                        10,
                    );
                    $this->deferredEvents[] = new ItemEntitySpawned($item, $this->players->recipients());
                } catch (InvalidArgumentException|OverflowException) {
                    // Invalid plugin-modified drops are isolated from the simulation tick.
                }
            }
            $this->pluginEvents?->entitySheared($player, $sheep, $held, $drops);

            return true;
        }

        $color = $this->woolColorFromDye($held->identifier);
        if ($color !== null) {
            if ($sheep->getWoolColor() !== $color) {
                $sheep->setWoolColor($color);
                $this->consumeSelectedItem($player);
            }

            return true;
        }
        return true;
    }

    private function interactWithBreedableAnimal(Player $player, BreedableAnimalEntity $animal): bool
    {
        $held = $player->inventory->selectedStack();
        if ($held === null) {
            return true;
        }
        if (($animal instanceof CowEntity || $animal instanceof GoatEntity || $animal instanceof MooshroomEntity)
            && !$animal->isBaby() && $held->identifier === 'minecraft:bucket') {
            $this->replaceConsumedContainer($player, 'minecraft:milk_bucket');
            return true;
        }
        if ($animal instanceof MooshroomEntity && !$animal->isBaby() && $held->identifier === 'minecraft:bowl') {
            $this->replaceConsumedContainer($player, 'minecraft:mushroom_stew');
            return true;
        }
        if ($animal instanceof PigEntity && !$animal->isBaby() && !$animal->isSaddled()
            && $held->identifier === 'minecraft:saddle') {
            $animal->setSaddled(true);
            $this->consumeSelectedItem($player);
            return true;
        }
        if (!in_array($held->identifier, $this->breedingFoods($animal), true)) {
            return true;
        }
        if ($animal instanceof Tameable && !$animal->isTamed()) {
            return true;
        }
        if ($animal->isBaby()) {
            $animal->accelerateGrowth(2_400);
            $this->consumeBreedingFood($player, $held->identifier);
            return true;
        }
        if ($animal->getLoveTicks() === 0) {
            $animal->setLoveTicks(BreedableAnimalEntity::MAXIMUM_LOVE_TICKS);
            $this->consumeBreedingFood($player, $held->identifier);
        }
        if (!$animal->isReadyToBreed()) {
            return true;
        }
        $partner = null;
        foreach ($this->entityRuntime->registry()->nearby($animal->getWorldName(), $animal->internalPosition(), 8.0, 16) as $candidate) {
            if ($candidate !== $animal && $candidate instanceof BreedableAnimalEntity
                && $candidate->getType() === $animal->getType() && $candidate->isReadyToBreed()) {
                $partner = $candidate;
                break;
            }
        }
        if ($partner === null) {
            return true;
        }
        $experience = $this->dropRandom->integer(1, 7);
        if ($this->pluginEvents !== null) {
            $experience = $this->pluginEvents->breedEntities($animal, $partner, $experience);
            if ($experience === null) {
                return true;
            }
        }
        $position = new Position(
            ($animal->internalPosition()->x + $partner->internalPosition()->x) / 2.0,
            min($animal->internalPosition()->y, $partner->internalPosition()->y),
            ($animal->internalPosition()->z + $partner->internalPosition()->z) / 2.0,
        );
        $outcome = $this->spawnEntity(new EntitySpawnRequest($animal->getType(), SpawnCause::BREEDING, $animal->getWorldName(), $position));
        if (!$outcome->entity instanceof BreedableAnimalEntity) {
            return true;
        }
        $child = $outcome->entity;
        $child->setBaby(true);
        if ($child instanceof SheepEntity && $animal instanceof SheepEntity && $partner instanceof SheepEntity) {
            $child->setWoolColor($this->dropRandom->integer(0, 1) === 0 ? $animal->getWoolColor() : $partner->getWoolColor());
        }
        if ($child instanceof RabbitEntity && $animal instanceof RabbitEntity && $partner instanceof RabbitEntity) {
            $child->setVariant($this->dropRandom->integer(0, 1) === 0 ? $animal->getVariant() : $partner->getVariant());
        }
        if ($child instanceof TameableAnimalEntity && $animal instanceof TameableAnimalEntity
            && $partner instanceof TameableAnimalEntity
            && $animal->getOwnerUniqueId() !== null
            && $animal->getOwnerUniqueId() === $partner->getOwnerUniqueId()) {
            $child->setOwnerUniqueId($animal->getOwnerUniqueId());
        }
        $animal->beginBreedingCooldown();
        $partner->beginBreedingCooldown();
        array_push($this->deferredEvents, ...$this->spawnExperienceOrbs($experience, $position));
        $this->pluginEvents?->entitiesBred($animal, $partner, $child, $experience);
        return true;
    }

    /** True means the interaction was consumed, false means a plugin cancelled it, and null means continue. */
    private function interactWithTameableAnimal(Player $player, TameableAnimalEntity $animal): ?bool
    {
        $held = $player->inventory->selectedStack();
        if ($animal->isTamed()) {
            $heldIsBreedingFood = $held !== null
                && in_array($held->identifier, $this->breedingFoods($animal), true);
            if ($animal->getOwnerUniqueId() === $player->identity->uuid
                && ($held === null || $animal->isSitting() || !$heldIsBreedingFood)) {
                $animal->setSitting(!$animal->isSitting());

                return true;
            }

            return null;
        }
        $tamingItem = match (true) {
            $animal instanceof WolfEntity => 'minecraft:bone',
            $animal instanceof CatEntity && $held !== null
                => in_array($held->identifier, ['minecraft:cod', 'minecraft:salmon'], true)
                ? $held->identifier
                : null,
            default => null,
        };
        if ($tamingItem === null || $held?->identifier !== $tamingItem) {
            return null;
        }
        $this->consumeSelectedItem($player);
        if ($this->dropRandom->integer(1, 3) !== 1) {
            $this->deferredEvents[] = new TameAttemptPresented(
                $animal,
                false,
                $this->players->recipients(),
            );
            return true;
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowTame($animal, $player)) {
            return false;
        }
        $animal->setOwnerUniqueId($player->identity->uuid);
        $animal->setSitting(true);
        $animal->heal($animal->getMaximumHealth() - $animal->getHealth());
        $this->deferredEvents[] = new TameAttemptPresented(
            $animal,
            true,
            $this->players->recipients(),
        );
        $this->pluginEvents?->entityTamed($animal, $player);

        return true;
    }

    private function interactWithRideable(Player $player, Rideable $rideable): ?WorldEvent
    {
        if (!$rideable instanceof AbstractLivingEntity || !$rideable instanceof Tameable) {
            return null;
        }
        $held = $player->inventory->selectedStack();
        $mount = match (true) {
            $rideable instanceof HorseFamilyEntity => $rideable,
            $rideable instanceof UndeadHorseEntity => $rideable,
            default => null,
        };
        if ($mount === null) {
            return null;
        }
        if (!$mount->isTamed()) {
            $temper = min(HorseFamilyEntity::MAXIMUM_TEMPER, $mount->getTemper() + 20);
            $mount->setTemper($temper);
            $tamed = $mount instanceof CamelEntity
                || $this->dropRandom->integer(1, HorseFamilyEntity::MAXIMUM_TEMPER) <= $temper;
            if ($tamed) {
                if ($this->pluginEvents !== null && !$this->pluginEvents->allowTame($mount, $player)) {
                    return new CommandRejected($player->sessionId, 'plugin_cancelled');
                }
                $mount->setOwnerUniqueId($player->identity->uuid);
                $this->deferredEvents[] = new TameAttemptPresented(
                    $mount,
                    true,
                    $this->players->recipients(),
                );
                $this->pluginEvents?->entityTamed($mount, $player);
            } else {
                $this->deferredEvents[] = new TameAttemptPresented(
                    $mount,
                    false,
                    $this->players->recipients(),
                );
            }
        }
        if ($held?->identifier === 'minecraft:saddle') {
            if (!$mount instanceof LlamaEntity && !$mount instanceof TraderLlamaEntity
                && $mount->isTamed() && !$mount->isSaddled()) {
                $mount->setSaddled(true);
                $this->consumeSelectedItem($player);
            }

            return null;
        }
        $requiresSaddle = !$mount instanceof LlamaEntity
            && !$mount instanceof TraderLlamaEntity
            && !$mount instanceof SkeletonHorseEntity;
        if (!$mount->isTamed() || ($requiresSaddle && !$mount->isSaddled())) {
            return null;
        }
        $occupied = count($this->mounts->linksForVehicle($mount->getRuntimeId()));
        if ($occupied >= $mount->getSeatCapacity()) {
            return new CommandRejected($player->sessionId, 'seat_unavailable');
        }
        $seat = $occupied === 0 ? MountSeat::DRIVER : MountSeat::PASSENGER_1;

        return $this->mountPlayer(new MountPlayer(
            $player->sessionId,
            $mount->getRuntimeId(),
            $mount->getUniqueId(),
            $seat,
        ));
    }

    /** @return list<string> */
    private function breedingFoods(BreedableAnimalEntity $animal): array
    {
        return match (true) {
            $animal instanceof CowEntity, $animal instanceof SheepEntity => ['minecraft:wheat'],
            $animal instanceof MooshroomEntity, $animal instanceof GoatEntity => ['minecraft:wheat'],
            $animal instanceof PigEntity => ['minecraft:carrot', 'minecraft:potato', 'minecraft:beetroot'],
            $animal instanceof ChickenEntity => ['minecraft:wheat_seeds', 'minecraft:beetroot_seeds', 'minecraft:melon_seeds', 'minecraft:pumpkin_seeds', 'minecraft:torchflower_seeds', 'minecraft:pitcher_pod'],
            $animal instanceof RabbitEntity => ['minecraft:carrot', 'minecraft:golden_carrot', 'minecraft:dandelion'],
            $animal instanceof WolfEntity => ['minecraft:beef', 'minecraft:cooked_beef', 'minecraft:chicken', 'minecraft:cooked_chicken', 'minecraft:mutton', 'minecraft:cooked_mutton', 'minecraft:porkchop', 'minecraft:cooked_porkchop', 'minecraft:rabbit', 'minecraft:cooked_rabbit'],
            $animal instanceof CatEntity, $animal instanceof OcelotEntity => ['minecraft:cod', 'minecraft:salmon'],
            $animal instanceof FoxEntity => ['minecraft:sweet_berries', 'minecraft:glow_berries'],
            $animal instanceof PandaEntity => ['minecraft:bamboo'],
            $animal instanceof ArmadilloEntity => ['minecraft:spider_eye'],
            $animal instanceof SnifferEntity => ['minecraft:torchflower_seeds'],
            $animal instanceof HorseFamilyEntity => ['minecraft:golden_carrot', 'minecraft:golden_apple'],
            $animal instanceof TurtleEntity => ['minecraft:seagrass'],
            $animal instanceof AxolotlEntity => ['minecraft:tropical_fish_bucket'],
            default => [],
        };
    }

    private function replaceConsumedContainer(Player $player, string $resultIdentifier): void
    {
        if (!$player->gameMode()->consumesItems()) {
            return;
        }
        $slot = $player->inventory->selectedHotbarSlot();
        $held = $player->inventory->selectedStack();
        if ($held === null || $this->itemCatalog === null || !$this->itemCatalog->has($resultIdentifier)) {
            return;
        }
        $result = new InventoryStack($resultIdentifier, 1, 1);
        if ($held->count === 1) {
            $player->inventory->replaceSlot($slot, $result);
        } else {
            $player->inventory->replaceSlot($slot, $held->decrement());
            $overflow = $player->inventory->add($result);
            if ($overflow !== null) {
                $item = $this->itemEntities->spawn($overflow, $player->movement->position, new ItemEntityMotion(0.0, 0.1, 0.0), 10);
                $this->deferredEvents[] = new ItemEntitySpawned($item, $this->players->recipients());
            }
        }
        $player->markDirty();
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $slot,
            $player->inventory->selectedStack(),
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
    }

    private function consumeBreedingFood(Player $player, string $identifier): void
    {
        if ($identifier === 'minecraft:tropical_fish_bucket') {
            $this->replaceConsumedContainer($player, 'minecraft:water_bucket');

            return;
        }
        $this->consumeSelectedItem($player);
    }

    private function woolColorFromDye(string $identifier): ?WoolColor
    {
        foreach (WoolColor::cases() as $color) {
            if ($identifier === 'minecraft:' . $color->value . '_dye') {
                return $color;
            }
        }

        return null;
    }

    private function trySheepEatGrass(SheepEntity $sheep): void
    {
        if ($this->blockWorld === null || $this->blockPalette === null
            || $this->dropRandom->integer(1, 10) !== 1) {
            return;
        }
        $position = $sheep->internalPosition();
        $belowY = (int) floor($position->y - 0.1);
        $below = $this->blockWorld->loadedBlockStateAt(
            (int) floor($position->x),
            $belowY,
            (int) floor($position->z),
        );
        if ($below === null || $below->value !== $this->blockPalette->grassBlock->value) {
            return;
        }
        $block = new BlockPosition((int) floor($position->x), $belowY, (int) floor($position->z));
        $previous = $this->setBlockStateAndSchedule($block, $this->blockPalette->dirt, false);
        $this->deferredEvents[] = new BlockChanged(
            'server',
            $block,
            $this->blockPalette->dirt,
            $this->players->recipients(),
            false,
            $previous,
        );
        if ($sheep->isSheared()) {
            $sheep->setSheared(false);
        }
        if ($sheep->isBaby()) {
            $sheep->accelerateGrowth(1_200);
        }
    }

    private function consumeSelectedItem(Player $player): void
    {
        if (!$player->gameMode()->consumesItems()) {
            return;
        }
        $slot = $player->inventory->selectedHotbarSlot();
        $held = $player->inventory->selectedStack();
        if ($held === null) {
            return;
        }
        $player->inventory->replaceSlot($slot, $held->decrement());
        $player->markDirty();
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $slot,
            $player->inventory->selectedStack(),
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
    }

    private function damageHeldItem(Player $player, ApiItemDamageCause $cause, int $wear): void
    {
        $slot = $player->inventory->selectedHotbarSlot();
        $held = $player->inventory->selectedStack();
        $maximum = $held === null ? null : VanillaItemDurability::maximum($held->identifier);
        if ($held === null || $maximum === null) {
            return;
        }
        $wear = $this->pluginEvents?->itemDamage(
            $player,
            $held,
            $cause,
            ApiEquipmentSlot::MAIN_HAND,
            $wear,
        ) ?? ($this->pluginEvents === null ? $wear : null);
        if ($wear === null || $wear < 1) {
            return;
        }
        $replacement = $held->damage + $wear >= $maximum ? null : $held->withDamage($held->damage + $wear);
        $player->inventory->replaceSlot($slot, $replacement);
        $player->markDirty();
        if ($replacement === null) {
            $this->pluginEvents?->itemBroken($player, $held, $cause, ApiEquipmentSlot::MAIN_HAND);
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $slot,
            $replacement,
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
    }

    private function generalEntityIsReachable(Player $attacker, AbstractLivingEntity $target): bool
    {
        $from = $attacker->movement->position;
        $to = $target->internalPosition();

        return hypot(
            hypot($to->x - $from->x, $to->z - $from->z),
            ($to->y + ($target->collisionHeight() / 2.0)) - ($from->y + 1.62),
        ) <= $this->maximumMeleeReach($attacker);
    }

    private function entityIsReachable(Player $attacker, Player $target): bool
    {
        $from = $attacker->movement->position;
        $to = $target->movement->position;
        $eyeX = $from->x;
        $eyeY = $from->y + 1.62;
        $eyeZ = $from->z;
        if (hypot(hypot($to->x - $eyeX, $to->z - $eyeZ), $to->y - $eyeY) > $this->maximumMeleeReach($attacker)) {
            return false;
        }

        $yaw = deg2rad($attacker->movement->yaw);
        $pitch = deg2rad($attacker->movement->pitch);
        $directionX = -sin($yaw) * cos($pitch);
        $directionY = -sin($pitch);
        $directionZ = cos($yaw) * cos($pitch);
        $forward = $directionX * ($to->x - $eyeX)
            + $directionY * ($to->y - $eyeY)
            + $directionZ * ($to->z - $eyeZ);

        return $forward >= -(sqrt(3.0) / 2.0);
    }

    private function maximumMeleeReach(Player $attacker): float
    {
        return $this->heldItemType($attacker)?->tool?->type === \Bedriox\Server\Gameplay\Item\ToolType::Spear
            ? 4.5
            : CombatRules::MAXIMUM_ENTITY_REACH;
    }

    /** @param array<string, int> $enchantments */
    private function applySpearLunge(Player $player, array $enchantments): void
    {
        $level = $enchantments[VanillaEnchantments::LUNGE] ?? 0;
        if ($level < 1
            || $this->heldItemType($player)?->tool?->type !== \Bedriox\Server\Gameplay\Item\ToolType::Spear
            || $player->vitals->food < 6.0
            || $this->playerIsSubmerged($player)) {
            return;
        }
        $yaw = deg2rad($player->movement->yaw);
        $speed = 0.55 + (0.2 * min(3, $level));
        $motion = new ApiKnockbackVector(
            -sin($yaw) * $speed,
            $player->movement->verticalVelocity,
            cos($yaw) * $speed,
        );
        if ($this->pluginEvents !== null) {
            $motion = $this->pluginEvents->knockback(
                $this->pluginEvents->playerView($player),
                $this->pluginEvents->playerView($player),
                ApiKnockbackCause::LUNGE,
                $motion,
            );
        }
        if ($motion === null) {
            return;
        }
        $player->movement->velocityX = $motion->x;
        $player->movement->verticalVelocity = $motion->y;
        $player->movement->velocityZ = $motion->z;
        $player->movement->verticalState = VerticalState::AIRBORNE;
        $player->markDirty();
        $this->applyMovementExhaustion($player, 4.0 * $level);
        $this->damageHeldTool($player, $this->heldItemType($player), attack: true);
        $this->pluginEvents?->knockedBack(
            $this->pluginEvents->playerView($player),
            $this->pluginEvents->playerView($player),
            ApiKnockbackCause::LUNGE,
            $motion,
        );
        $this->deferredEvents[] = new PlayerMotionChanged(
            $player->sessionId,
            $player->snapshot(),
            $motion->x,
            $motion->y,
            $motion->z,
            $player->movement->clientTick,
            true,
            $this->players->recipients(),
        );
    }

    /** @return array{float, float, float} */
    private function resolveKnockback(
        float $motionX,
        float $motionY,
        float $motionZ,
        float $directionX,
        float $directionZ,
        float $horizontalStrength,
        float $verticalStrength,
        bool $grounded,
        float $resistance = 0.0,
    ): array {
        $motion = $this->knockbackResolver->resolve(
            new KnockbackMotion($motionX, $motionY, $motionZ),
            $directionX,
            $directionZ,
            $horizontalStrength,
            $verticalStrength,
            $resistance,
            $grounded,
            CombatRules::KNOCKBACK_VERTICAL_LIMIT,
        );

        return [
            $motion->x,
            $motion->y,
            $motion->z,
        ];
    }

    private function deathEvent(
        Player $player,
        DamageCause $cause,
        float $damage,
        Player|AbstractLivingEntity|null $attacker = null,
    ): PlayerDied {
        $this->forceDismountPlayer($player, MountReason::DEATH);
        $this->removeVanishingItems($player);
        $this->triggerDeathEffectConsequences(
            $player->movement->position,
            $player->effects->snapshot(),
            $player->identity->uuid,
        );
        $closed = $this->closeContainer($player, ApiInventoryCloseReason::DEATH, true);
        if ($closed !== null) {
            $this->deferredEvents[] = $closed;
        }
        $this->evacuateCraftingGrid($player, $this->players->recipients());
        $player->movement->velocityX = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->velocityZ = 0.0;
        foreach ($player->effects->clear(fn(EffectInstance $effect): bool => $this->pluginEvents === null
            || $this->pluginEvents->allowEffectRemoval($player, $effect, EffectCause::DEATH)) as $transition) {
            if ($transition->previous === null) {
                continue;
            }
            $this->pluginEvents?->effectRemoved(
                $player,
                $transition->previous,
                EffectCause::DEATH,
            );
            $this->deferredEvents[] = new PlayerEffectChanged(
                $player->snapshot(),
                $transition->previous->type,
                null,
                $this->players->recipients(),
                $this->tick,
            );
        }
        $player->vitals->setAbsorption(
            min(
                $player->vitals->absorption,
                VanillaEffectBehavior::absorptionCapacity($player->effects->snapshot()),
            ),
        );
        $killer = $attacker instanceof Player ? $attacker : null;
        $message = match (true) {
            $cause === DamageCause::Attack && $attacker instanceof Player => new TranslatableMessage(
                'death.attack.player',
                [$player->identity->displayName, $attacker->identity->displayName],
            ),
            $cause === DamageCause::Attack && $attacker instanceof AbstractLivingEntity => new TranslatableMessage(
                'death.attack.mob',
                [$player->identity->displayName, self::deathAttackerName($attacker)],
            ),
            $cause === DamageCause::Attack => new TranslatableMessage(
                'death.attack.generic',
                [$player->identity->displayName],
            ),
            $cause === DamageCause::Fall => new TranslatableMessage(
                $damage > 2.0 ? 'death.fell.accident.generic' : 'death.attack.fall',
                [$player->identity->displayName],
            ),
            $cause === DamageCause::Drowning => new TranslatableMessage(
                'death.attack.drown',
                [$player->identity->displayName],
            ),
            $cause === DamageCause::Fire => new TranslatableMessage(
                'death.attack.onFire',
                [$player->identity->displayName],
            ),
            $cause === DamageCause::Projectile && $attacker instanceof Player => new TranslatableMessage(
                'death.attack.arrow',
                [$player->identity->displayName, $attacker->identity->displayName],
            ),
            $cause === DamageCause::Kill,
            $cause === DamageCause::Plugin,
            $cause === DamageCause::Projectile,
            $cause === DamageCause::Magic,
            $cause === DamageCause::Explosion,
            $cause === DamageCause::Thorns => new TranslatableMessage(
                'death.attack.generic',
                [$player->identity->displayName],
            ),
        };
        $presentation = $this->pluginEvents?->death($player, $cause, $damage, $killer, $message, $message)
            ?? new DeathPresentation($message, $message);

        return new PlayerDied(
            $player->snapshot(),
            $cause,
            $killer?->snapshot(),
            $presentation->deathMessage,
            $presentation->deathScreenMessage,
            $this->players->recipients(),
            $this->players->recipients(),
        );
    }

    private function removeVanishingItems(Player $player): void
    {
        $slots = $player->inventory->slots();
        $changed = false;
        foreach ($slots as $slot => $stack) {
            if (EnchantmentEffects::level($stack?->nbt, VanillaEnchantments::VANISHING) === 0) {
                continue;
            }
            $slots[$slot] = null;
            $changed = true;
        }
        if ($changed) {
            $player->inventory->replaceMainContents($slots);
        }
        foreach (ArmorSlot::cases() as $slot) {
            $stack = $player->inventory->armorStack($slot);
            if (EnchantmentEffects::level($stack?->nbt, VanillaEnchantments::VANISHING) > 0) {
                $player->inventory->replaceArmorSlot($slot, null);
            }
        }
        $offhand = $player->inventory->offhandStack();
        if (EnchantmentEffects::level($offhand?->nbt, VanillaEnchantments::VANISHING) > 0) {
            $player->inventory->replaceOffhand(null);
        }
        $player->markDirty();
    }

    private static function deathAttackerName(AbstractLivingEntity $attacker): string
    {
        if ($attacker->nameTag() !== '') {
            return $attacker->nameTag();
        }
        $identifier = $attacker->getType()->identifier();
        $separator = strpos($identifier, ':');
        $path = $separator === false ? $identifier : substr($identifier, $separator + 1);
        $name = preg_replace('/[_.\/-]+/', ' ', $path);

        return is_string($name) && $name !== '' ? ucwords($name) : $identifier;
    }

    /** @return array{float, float} */
    private function knockbackDirection(Player $attacker, Player $target): array
    {
        $deltaX = $target->movement->position->x - $attacker->movement->position->x;
        $deltaZ = $target->movement->position->z - $attacker->movement->position->z;
        $length = hypot($deltaX, $deltaZ);
        if ($length <= 0.000001) {
            $yaw = deg2rad($attacker->movement->yaw);
            $deltaX = -sin($yaw);
            $deltaZ = cos($yaw);
            $length = 1.0;
        }

        return [
            $deltaX / $length,
            $deltaZ / $length,
        ];
    }

    private function respawn(RespawnPlayer $command): ?WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($player->vitals->isAlive()) {
            return new CommandRejected($command->session, 'already_alive');
        }
        $this->beginRespawn($player, false);

        return null;
    }

    private function beginRespawn(Player $player, bool $acknowledge): void
    {
        $key = self::sessionKey($player->sessionId);
        $pending = $this->pendingRespawns[$key] ?? null;
        if ($pending !== null) {
            if ($acknowledge && !$pending['acknowledge']) {
                $pending['acknowledge'] = true;
                $this->pendingRespawns[$key] = $pending;
            }

            return;
        }
        $this->pendingRespawns[$key] = [
            'session' => $player->sessionId,
            'position' => $this->pluginEvents?->respawn($player, $this->spawn) ?? $this->spawn,
            'acknowledge' => $acknowledge,
        ];
    }

    private function completeRespawn(Player $player, Position $position): PlayerRespawned
    {
        $player->movement->position = $position;
        $player->movement->yaw = 0.0;
        $player->movement->headYaw = 0.0;
        $player->movement->pitch = 0.0;
        $player->movement->velocityX = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->velocityZ = 0.0;
        $player->movement->fallDistance = 0.0;
        $player->movement->verticalState = $this->collisionResolver?->isGrounded($position) === false
            ? VerticalState::AIRBORNE
            : VerticalState::GROUNDED;
        $player->vitals->health = \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH;
        $player->vitals->airTicks = \Bedriox\Server\Player\PlayerVitals::MAX_AIR_TICKS;
        $player->vitals->fireTicks = 0;
        $player->vitals->resetNutrition();
        $player->vitals->invulnerableUntilTick = $this->tick + 60;
        $player->markDirty();
        $this->pluginEvents?->respawned($player);

        return new PlayerRespawned(
            $player->snapshot(),
            $this->players->recipients(),
            $player->inventory->slots(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
        );
    }

    private function acknowledgeRespawn(AcknowledgeRespawn $command): ?WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($player->vitals->isAlive()) {
            return new RespawnAcknowledged($player->snapshot());
        }
        $this->beginRespawn($player, true);

        return null;
    }

    /** @return list<WorldEvent> */
    private function advancePendingRespawns(): array
    {
        $events = [];
        foreach ($this->pendingRespawns as $key => $pending) {
            $player = $this->players->player($pending['session']);
            if ($player === null || $player->vitals->isAlive()) {
                unset($this->pendingRespawns[$key]);

                continue;
            }
            $retainedChunks = [];
            if ($this->blockWorld !== null) {
                $ready = true;
                foreach (self::respawnChunkPositions($pending['position']) as $chunk) {
                    if ($this->blockWorld->requestRetainChunk($chunk)) {
                        $retainedChunks[] = $chunk;
                    } else {
                        $ready = false;
                    }
                }
                if (!$ready) {
                    foreach ($retainedChunks as $chunk) {
                        $this->blockWorld->releaseChunk($chunk);
                    }

                    continue;
                }
            }
            try {
                $respawned = $this->completeRespawn($player, $pending['position']);
            } finally {
                foreach ($retainedChunks as $chunk) {
                    $this->blockWorld?->releaseChunk($chunk);
                }
            }
            unset($this->pendingRespawns[$key]);
            if ($pending['acknowledge']) {
                $events[] = new RespawnAcknowledged($respawned->player);
            }
            $events[] = $respawned;
        }

        return $events;
    }

    /** @return list<ChunkPosition> */
    private static function respawnChunkPositions(Position $position): array
    {
        $area = PlayerCollisionShape::at($position)->offset(0.0, -0.05, 0.0);
        $chunks = [];
        $minimumChunkX = (int) floor($area->minX / 16.0);
        $maximumChunkX = (int) floor($area->maxX / 16.0);
        $minimumChunkZ = (int) floor($area->minZ / 16.0);
        $maximumChunkZ = (int) floor($area->maxZ / 16.0);
        for ($chunkZ = $minimumChunkZ; $chunkZ <= $maximumChunkZ; ++$chunkZ) {
            for ($chunkX = $minimumChunkX; $chunkX <= $maximumChunkX; ++$chunkX) {
                $chunks[] = new ChunkPosition($chunkX, $chunkZ);
            }
        }

        return $chunks;
    }

    /** @return list<WorldEvent> */
    private function drainDeferredEvents(): array
    {
        $events = $this->deferredEvents;
        $this->deferredEvents = [];

        return $events;
    }

    private function chat(SendChat $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($command->sequence <= $player->chatSequence) {
            return new CommandRejected($command->session, 'stale_chat_sequence');
        }
        $player->chatSequence = $command->sequence;
        $elapsed = $this->tick - $player->lastChatRefillTick;
        $refills = intdiv($elapsed, $this->limits->chatRefillTicks);
        if ($refills > 0) {
            $player->chatTokens = min($this->limits->chatBucketCapacity, $player->chatTokens + $refills);
            $player->lastChatRefillTick += $refills * $this->limits->chatRefillTicks;
        }
        if ($player->chatTokens === 0) {
            return new CommandRejected($command->session, 'chat_rate');
        }

        $message = $command->message;
        if ($this->pluginEvents !== null) {
            $message = $this->pluginEvents->chat($player, $message);
            if ($message === null) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            try {
                $this->validator->chat($command->session, $command->sequence, $message);
            } catch (CommandValidationException) {
                return new CommandRejected($command->session, 'plugin_invalid_chat');
            }
        }
        --$player->chatTokens;
        $event = new ChatBroadcast(
            $player->sessionId,
            $player->identity->uuid,
            $player->identity->displayName,
            $command->sequence,
            $message,
            $this->players->recipients(),
        );
        $this->pluginEvents?->chatBroadcast($player, $message);

        return $event;
    }

    private function emote(PerformEmote $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($player->lastEmoteTick !== null
            && $this->tick - $player->lastEmoteTick < self::EMOTE_COOLDOWN_TICKS) {
            return new CommandRejected($command->session, 'emote_rate');
        }

        $player->lastEmoteTick = $this->tick;

        return new EmotePerformed(
            $player->sessionId,
            $command->emoteId,
            $this->players->recipients($player->sessionId),
        );
    }

    private function swingArm(SwingArm $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($command->source === ArmSwingSource::Missed
            && $this->pluginEvents !== null
            && !$this->pluginEvents->allowMissSwing($player)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $event = $this->armSwingEvent($player, $command->source);

        return $event ?? new CommandRejected($command->session, 'arm_swing_rate');
    }

    private function disconnect(DisconnectPlayer $command): WorldEvent
    {
        $key = self::sessionKey($command->session);
        unset($this->breakingBlocks[$key], $this->pendingRespawns[$key]);
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $this->forceDismountPlayer($player, MountReason::DISCONNECT);
        $this->deferItemUseCancellation($player, ItemUseCancellationReason::DISCONNECTED);
        $closed = $this->closeContainer($player, ApiInventoryCloseReason::DISCONNECT, false);
        if ($closed !== null) {
            $this->deferredEvents[] = $closed;
        }
        unset($this->itemCooldowns[$key], $this->lastItemUseCompletionTicks[$key]);
        $this->evacuateCraftingGrid($player, $this->players->recipients($player->sessionId));
        $this->pluginEvents?->quit($player);
        $this->playerPersistence?->save($player);
        $this->players->remove($command->session);

        return new PlayerDisconnected(
            $player->sessionId,
            $player->identity->uuid,
            $player->runtimeActorId,
            $this->players->recipients(),
        );
    }

    /** @param list<string> $recipients */
    private function evacuateCraftingGrid(Player $player, array $recipients): void
    {
        $hadContents = array_filter($player->inventory->craftingSlots()) !== [];
        $wasTable = $player->inventory->craftingGridWidth() === 3;
        foreach ($player->inventory->closeCraftingGrid() as $overflow) {
            if (!$this->itemEntities->canSpawn()) {
                continue;
            }
            $entity = $this->itemEntities->spawn(
                $overflow,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.0,
                    $player->movement->position->z,
                ),
                new ItemEntityMotion(0.0, 0.05, 0.0),
                10,
            );
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $recipients);
        }
        if ($hadContents || $wasTable) {
            $player->markDirty();
        }
    }

    private function isValid(WorldCommand $command): bool
    {
        try {
            match (true) {
                $command instanceof JoinPlayer => $this->validator->join(
                    $command->session,
                    $command->identity,
                    $command->displayName,
                    $command->runtimeActorId,
                    $command->bootstrap,
                    $command->loginApproved,
                ),
                $command instanceof MovePlayer => $this->validator->move(
                    $command->session,
                    $command->sequence,
                    $command->position->x,
                    $command->position->y,
                    $command->position->z,
                    $command->yaw,
                    $command->pitch,
                    $command->mode,
                    $command->deltaX,
                    $command->deltaY,
                    $command->deltaZ,
                    $command->jumpRequested,
                    headYaw: $command->headYaw,
                    sneaking: $command->sneaking,
                    sprinting: $command->sprinting,
                    clientTick: $command->clientTick,
                    flying: $command->flying,
                    verticalCollision: $command->verticalCollision,
                    moveX: $command->moveX,
                    moveZ: $command->moveZ,
                ),
                $command instanceof SendChat => $this->validator->chat(
                    $command->session,
                    $command->sequence,
                    $command->message,
                ),
                $command instanceof PerformEmote => $this->validator->emote(
                    $command->session,
                    $command->emoteId,
                ),
                $command instanceof SwingArm => $this->validator->swingArm(
                    $command->session,
                    $command->source,
                ),
                $command instanceof DisconnectPlayer => $this->validator->disconnect($command->session),
                $command instanceof BreakBlock => $this->validator->breakBlock(
                    $command->session,
                    $command->sequence,
                    $command->action,
                    $command->position,
                    $command->face,
                ),
                $command instanceof PlaceBlock => $this->validator->placeBlock(
                    $command->session,
                    $command->sequence,
                    $command->clickedPosition,
                    $command->face,
                    $command->hotbarSlot,
                    $command->hand,
                    $command->clickX,
                    $command->clickY,
                    $command->clickZ,
                ),
                $command instanceof ApplyInventoryStackRequest => $this->validator->inventoryStackRequest(
                    $command->session,
                    $command->requestId,
                    $command->actions,
                    $command->rejectionReason,
                    $command->responseMode,
                    $command->authoritativeCreativeStack,
                    $command->crafting,
                ),
                $command instanceof DropItem => $this->validator->dropItem(
                    $command->session,
                    $command->requestId,
                    $command->source,
                    $command->count,
                    $command->responseMode,
                    $command->expectedStack,
                ),
                $command instanceof AttackPlayer => $this->validator->attack(
                    $command->session,
                    $command->targetRuntimeActorId,
                    $command->hotbarSlot,
                ),
                $command instanceof InteractEntity => $this->validator->interactEntity(
                    $command->session,
                    $command->targetRuntimeActorId,
                    $command->hotbarSlot,
                    $command->interaction,
                ),
                $command instanceof MountPlayer => $this->validator->mountPlayer(
                    $command->session,
                    $command->vehicleRuntimeId,
                    $command->vehicleUniqueId,
                    $command->seat,
                    $command->reason,
                ),
                $command instanceof DismountPlayer => $this->validator->dismountPlayer(
                    $command->session,
                    $command->reason,
                ),
                $command instanceof ChangeGameMode => $this->validator->changeGameMode(
                    $command->session,
                    $command->gameMode,
                ),
                $command instanceof GiveItem => $this->validator->giveItem(
                    $command->session,
                    $command->identifier,
                    $command->amount,
                    $command->damage,
                    $command->nbt,
                ),
                $command instanceof SyncInventory => $this->validator->syncInventory($command->session),
                $command instanceof SyncInventorySlots => $this->validator->syncInventorySlots(
                    $command->session,
                    $command->slots,
                ),
                $command instanceof CloseContainer => $this->validator->closeContainer(
                    $command->session,
                    $command->windowId,
                ),
                $command instanceof CloseCraftingGrid => $this->validator->closeCraftingGrid($command->session),
                $command instanceof SelectHotbarSlot => $this->validator->selectHotbarSlot(
                    $command->session,
                    $command->hotbarSlot,
                ),
                $command instanceof SendPluginMessage => $this->validator->pluginMessage(
                    $command->session,
                    $command->message,
                ),
                $command instanceof TeleportPlayer => $this->validator->teleport(
                    $command->session,
                    $command->position->x,
                    $command->position->y,
                    $command->position->z,
                    $command->yaw,
                    $command->pitch,
                ),
                $command instanceof AddPlayerEffect => $this->validator->addPlayerEffect(
                    $command->session,
                    $command->effect,
                    $command->cause,
                ),
                $command instanceof RemovePlayerEffect => $this->validator->removePlayerEffect(
                    $command->session,
                    $command->type,
                    $command->cause,
                ),
                $command instanceof ClearPlayerEffects => $this->validator->clearPlayerEffects(
                    $command->session,
                    $command->cause,
                ),
                $command instanceof SetPlayerExperience => $this->validator->setPlayerExperience(
                    $command->session,
                    $command->totalPoints,
                    $command->cause,
                ),
                $command instanceof SetPluginBlock => $this->validator->pluginBlock(
                    $command->plugin,
                    $command->position,
                    $command->identifier,
                ),
                $command instanceof SpawnPluginParticle => $this->validator->pluginParticle(
                    $command->plugin,
                    $command->position,
                    $command->particle,
                    $command->targetIdentities,
                ),
                $command instanceof SetPluginInventorySlot => $this->validator->pluginInventorySlot(
                    $command->session,
                    $command->slot,
                    $command->stack,
                ),
                $command instanceof SetPluginInventoryContents => $this->validator->pluginInventoryContents(
                    $command->session,
                    $command->contents,
                ),
                $command instanceof SetPluginArmorContents => $this->validator->pluginArmorContents(
                    $command->session,
                    $command->contents,
                ),
                $command instanceof RemovePluginInventoryStack => $this->validator->removePluginInventoryStack(
                    $command->session,
                    $command->stack,
                ),
                $command instanceof SetPluginEquipmentSlot => $this->validator->pluginEquipmentSlot(
                    $command->session,
                    $command->slot,
                    $command->stack,
                ),
                $command instanceof DamageEntity => $this->validator->damageEntity(
                    $command->source,
                    $command->runtimeId,
                    $command->uniqueId,
                    $command->amount,
                    $command->cause,
                ),
                $command instanceof DamagePlayer => $this->validator->damage(
                    $command->session,
                    $command->amount,
                    $command->cause,
                ),
                $command instanceof RespawnPlayer => $this->validator->respawn($command->session),
                $command instanceof AcknowledgeRespawn => $this->validator->acknowledgeRespawn($command->session),
                $command instanceof UseItem => $this->validator->useItem($command->session, $command->hotbarSlot),
                $command instanceof ReleaseItem => $this->validator->releaseItem($command->session, $command->hotbarSlot),
                default => throw new CommandValidationException('Unsupported world command.'),
            };
        } catch (CommandValidationException) {
            return false;
        }

        return true;
    }

    private function enqueueDisconnect(DisconnectPlayer $command): bool
    {
        $key = self::sessionKey($command->session);
        if (isset($this->pendingDisconnects[$key])) {
            return true;
        }
        $this->removeQueuedCommandsForSession($command->session);
        $this->removePendingMovement($key);
        if (!$this->players->hasSession($command->session)) {
            return true;
        }
        $bytes = $command->estimatedBytes();
        if (
            $this->lifecycleCommands->count() >= $this->limits->maximumQueuedLifecycleCommands
            || $bytes > $this->limits->maximumQueuedLifecycleBytes - $this->queuedLifecycleBytes
        ) {
            return false;
        }
        $this->lifecycleCommands->enqueue($command);
        $this->pendingDisconnects[$key] = true;
        $this->queuedLifecycleBytes += $bytes;
        $this->queuedBytes += $bytes;

        return true;
    }

    private function removeQueuedCommandsForSession(string $sessionId): void
    {
        $queuedCommands = $this->commands->count();
        while ($queuedCommands-- > 0) {
            $queued = $this->commands->dequeue();
            if ($queued->sessionId() === $sessionId) {
                $queuedBytes = $queued->estimatedBytes();
                $this->queuedCommandBytes -= $queuedBytes;
                $this->queuedBytes -= $queuedBytes;
            } else {
                $this->commands->enqueue($queued);
            }
        }
    }

    private function enqueueMovement(MovePlayer $command): bool
    {
        $key = self::sessionKey($command->session);
        $previous = $this->movements[$key] ?? null;
        if ($previous !== null && $command->sequence <= $previous->sequence) {
            return true;
        }
        $bytes = $command->estimatedBytes();
        $previousBytes = $previous?->estimatedBytes() ?? 0;
        if (
            ($previous === null && $this->commands->count() + count($this->movements) >= $this->limits->maximumQueuedCommands)
            || $bytes - $previousBytes > $this->limits->maximumQueuedBytes - $this->queuedCommandBytes
        ) {
            return false;
        }
        if ($previous === null) {
            $this->movementOrder->enqueue($key);
        }
        if ($previous?->jumpRequested === true && !$command->jumpRequested) {
            $command = new MovePlayer(
                $command->session,
                $command->sequence,
                $command->position,
                $command->yaw,
                $command->pitch,
                $command->mode,
                $command->deltaX,
                $command->deltaY,
                $command->deltaZ,
                true,
                $command->headYaw,
                $command->sneaking,
                $command->sprinting,
                $command->clientTick,
                $command->flying,
                $command->verticalCollision,
                $command->moveX,
                $command->moveZ,
                $command->vehiclePitch,
                $command->vehicleYaw,
                $command->vehicleControlYaw,
                $command->predictedVehicleActorId,
            );
            $bytes = $command->estimatedBytes();
        }
        $this->movements[$key] = $command;
        $this->queuedCommandBytes += $bytes - $previousBytes;
        $this->queuedBytes += $bytes - $previousBytes;

        return true;
    }

    private function removePendingMovement(string $key): void
    {
        $movement = $this->movements[$key] ?? null;
        if ($movement === null) {
            return;
        }
        unset($this->movements[$key]);
        $bytes = $movement->estimatedBytes();
        $this->queuedCommandBytes -= $bytes;
        $this->queuedBytes -= $bytes;
        $remaining = $this->movementOrder->count();
        while ($remaining-- > 0) {
            $queuedKey = $this->movementOrder->dequeue();
            if ($queuedKey !== $key) {
                $this->movementOrder->enqueue($queuedKey);
            }
        }
    }

    private static function sessionKey(string $session): string
    {
        return 'session:' . $session;
    }

    private static function transientWorkstationInventoryIdentifier(
        string $session,
        BlockPosition $position,
        ApiContainerType $type,
    ): string {
        return implode('/', [
            'workstation',
            hash('sha256', $session),
            (string) $position->x,
            (string) $position->y,
            (string) $position->z,
            $type->value,
        ]);
    }

    private function nextAvailableRuntimeActorId(): int
    {
        while ($this->players->hasActorId($this->nextRuntimeActorId)) {
            if ($this->nextRuntimeActorId === PHP_INT_MAX) {
                throw new \OverflowException('Runtime actor ID space is exhausted.');
            }
            ++$this->nextRuntimeActorId;
        }

        return $this->nextRuntimeActorId;
    }

    /** @return list<WorldEvent> */
    private function advanceBlockBreakParticles(): array
    {
        if ($this->blockWorld === null) {
            return [];
        }
        $events = [];
        foreach ($this->breakingBlocks as $key => $active) {
            $particleDue = $this->tick - $active['lastParticleTick'] >= 5;
            $swingDue = $this->tick - $active['lastSwingTick'] >= self::ARM_SWING_INTERVAL_TICKS;
            if (!$particleDue && !$swingDue) {
                continue;
            }
            $sessionId = substr($key, strlen('session:'));
            $player = $this->players->player($sessionId);
            if ($player === null) {
                unset($this->breakingBlocks[$key]);
                continue;
            }
            $position = $active['position'];
            if ($this->blockWorld->blockStateAt($position->x, $position->y, $position->z)->value !== $active['state']) {
                unset($this->breakingBlocks[$key]);
                continue;
            }
            if ($particleDue) {
                $this->breakingBlocks[$key]['lastParticleTick'] = $this->tick;
                $events[] = new BlockPunch(
                    $sessionId,
                    $position,
                    new InternalBlockStateId($active['state']),
                    $active['face'],
                    $this->players->recipients(),
                );
            }
            if ($swingDue) {
                $this->breakingBlocks[$key]['lastSwingTick'] = $this->tick;
                $swing = $this->armSwingEvent($player, ArmSwingSource::Mining);
                if ($swing !== null) {
                    $events[] = $swing;
                }
            }
        }

        return $events;
    }

    private function breakBlock(BreakBlock $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null || $this->blockWorld === null || $this->blockPalette === null) {
            return new CommandRejected($command->session, 'block_world_unavailable');
        }
        $key = self::sessionKey($command->session);
        $active = $this->breakingBlocks[$key] ?? null;
        if ($command->action === BlockBreakAction::Abort) {
            if ($active === null) {
                return new CommandRejected($command->session, 'block_break_not_active');
            }
            unset($this->breakingBlocks[$key]);

            return new BlockBreakStopped($command->session, $active['position'], $this->players->recipients());
        }
        $position = $command->position;
        if ($position === null || !$this->blockIsReachable($player->snapshot(), $position)) {
            return new CommandRejected($command->session, 'block_reach');
        }
        $state = $this->blockWorld->blockStateAt($position->x, $position->y, $position->z);
        $blockType = $this->blockCatalog !== null && $this->blockStateRegistry !== null
            ? $this->blockCatalog->findTypeForInternalId($state, $this->blockStateRegistry)
            : null;
        if ($this->blockCatalog !== null && $this->blockStateRegistry !== null && $blockType === null) {
            unset($this->breakingBlocks[$key]);

            return new BlockChanged($command->session, $position, $state, [$command->session], true);
        }
        if ($command->action === BlockBreakAction::Start) {
            if (!$this->playerCanBreakBlock($player, $state, $blockType)) {
                return new CommandRejected($command->session, 'block_not_breakable');
            }
            if ($active !== null && $command->sequence <= $active['sequence']) {
                return new CommandRejected($command->session, 'stale_block_sequence');
            }
            $sameTarget = $active !== null && $active['position']->equals($position) && $active['state'] === $state->value;
            $this->breakingBlocks[$key] = [
                'position' => $position,
                'state' => $state->value,
                'sequence' => $command->sequence,
                'face' => $command->face,
                'lastParticleTick' => $sameTarget ? $active['lastParticleTick'] : $this->tick,
                'lastSwingTick' => $sameTarget ? $active['lastSwingTick'] : $this->tick,
            ];
            if (!$sameTarget) {
                $this->deferredEvents[] = new BlockPunch(
                    $command->session,
                    $position,
                    $state,
                    $command->face,
                    $this->players->recipients(),
                );
                $swing = $this->armSwingEvent($player, ArmSwingSource::Mining);
                if ($swing !== null) {
                    $this->deferredEvents[] = $swing;
                }
            }

            $heldType = $this->heldItemType($player);
            $heldEnchantments = $this->heldEnchantments($player);
            $breakRate = $player->gameMode()->instantlyBreaksBlocks() || $blockType === null
                ? ($player->gameMode()->instantlyBreaksBlocks() ? 65_535 : self::EMPTY_HAND_GRASS_BREAK_RATE)
                : BlockBreakRules::networkBreakRate(
                    $blockType,
                    $heldType,
                    new BlockBreakContext(
                        airborne: $player->movement->verticalState === VerticalState::AIRBORNE,
                        underwater: $this->playerIsSubmerged($player),
                        aquaAffinity: $this->armorEnchantmentLevel(
                            $player,
                            ArmorSlot::Head,
                            VanillaEnchantments::AQUA_AFFINITY,
                        ) > 0,
                        efficiencyLevel: $heldEnchantments[VanillaEnchantments::EFFICIENCY] ?? 0,
                        hasteLevel: VanillaEffectBehavior::miningHasteLevel($player->effects->snapshot()),
                        miningFatigueLevel: VanillaEffectBehavior::miningFatigueLevel($player->effects->snapshot()),
                    ),
                );

            return new BlockBreakStarted(
                $command->session,
                $position,
                $breakRate,
                $this->players->recipients(),
                $active !== null && !$active['position']->equals($position) ? $active['position'] : null,
            );
        }
        $stopsActiveBreak = $active !== null && $active['position']->equals($position);
        if (!$this->playerCanBreakBlock($player, $state, $blockType)
            || ($stopsActiveBreak && $command->sequence <= $active['sequence'])) {
            unset($this->breakingBlocks[$key]);

            return new BlockChanged(
                $command->session,
                $position,
                $state,
                [$command->session],
                $stopsActiveBreak,
            );
        }
        unset($this->breakingBlocks[$key]);
        $identifier = $this->blockIdentifier($state->value);
        $heldType = $this->heldItemType($player);
        $survivalBreak = $player->gameMode() === GameMode::SURVIVAL
            && $blockType !== null && $this->itemCatalog !== null;
        $heldEnchantments = $this->heldEnchantments($player);
        $resolvedDrops = $survivalBreak
            ? BlockDropRules::drops(
                $blockType,
                $heldType,
                $this->dropRandom,
                ($heldEnchantments[VanillaEnchantments::SILK_TOUCH] ?? 0) > 0,
                $heldEnchantments[VanillaEnchantments::FORTUNE] ?? 0,
            )
            : [];
        $drops = array_map(
            static fn(\Bedriox\Server\Gameplay\Block\BlockDrop $drop): ApiItemStack => new ApiItemStack(
                $drop->identifier,
                $drop->count,
            ),
            $resolvedDrops,
        );
        if ($this->pluginEvents !== null) {
            $drops = $this->pluginEvents->blockBreak($player, $position, $identifier, $drops);
            if ($drops === null) {
                return new BlockChanged(
                    $command->session,
                    $position,
                    $state,
                    [$command->session],
                    $stopsActiveBreak,
                );
            }
        }
        foreach ($drops as $drop) {
            if ($this->itemCatalog === null || !$this->itemCatalog->has($drop->identifier)) {
                return new BlockChanged(
                    $command->session,
                    $position,
                    $state,
                    [$command->session],
                    $stopsActiveBreak,
                );
            }
        }
        $storageEntity = $this->blockWorld->blockEntityAt($position);
        $storageDrops = $storageEntity instanceof ContainerBlockEntity
            && $storageEntity->type !== BlockEntityType::ShulkerBox
            ? array_values($storageEntity->inventory->contents())
            : [];
        $shulkerNbt = null;
        if ($survivalBreak
            && $storageEntity instanceof ContainerBlockEntity
            && $storageEntity->type === BlockEntityType::ShulkerBox) {
            try {
                $shulkerNbt = $this->shulkerItems->encode($storageEntity);
            } catch (InvalidArgumentException) {
                return new BlockChanged(
                    $command->session,
                    $position,
                    $state,
                    [$command->session],
                    $stopsActiveBreak,
                );
            }
        }
        if (count($drops) + count($storageDrops) > $this->itemEntities->remainingCapacity()) {
            return new BlockChanged($command->session, $position, $state, [$command->session], $stopsActiveBreak);
        }
        $swing = $this->armSwingEvent($player, ArmSwingSource::Mining);
        if ($swing !== null) {
            $this->deferredEvents[] = $swing;
        }
        try {
            $this->setBlockStateAndSchedule($position, $this->blockPalette->air);
        } catch (OverflowException) {
            return new BlockChanged(
                $command->session,
                $position,
                $state,
                [$command->session],
                $stopsActiveBreak,
            );
        }
        $this->closeContainersAt($position, ApiInventoryCloseReason::BLOCK_REMOVED);
        $this->removeStorageBlockEntity($position, $storageEntity);
        $this->refreshPlayerGroundStates();
        if ($survivalBreak) {
            $itemCatalog = $this->itemCatalog;
            $blockStateRegistry = $this->blockStateRegistry;
            foreach ($drops as $drop) {
                $type = $itemCatalog->type($drop->identifier);
                $placed = $type->placedBlockState === null
                    ? null
                    : $blockStateRegistry->internalId($type->placedBlockState);
                $entity = $this->itemEntities->spawn(
                    new InventoryStack(
                        $drop->identifier,
                        $drop->count,
                        1,
                        $placed,
                        $drop->damage,
                        $shulkerNbt ?? $drop->nbt,
                        $drop->auxValue,
                    ),
                    new Position($position->x + 0.5, $position->y + 0.5, $position->z + 0.5),
                    new ItemEntityMotion(0.0, 0.1, 0.0),
                    10,
                );
                $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
            }
            $this->damageHeldTool($player, $heldType);
        }
        foreach ($storageDrops as $storageDrop) {
            $entity = $this->itemEntities->spawn(
                $this->inventoryStackFromContainerItem($storageDrop),
                new Position($position->x + 0.5, $position->y + 0.5, $position->z + 0.5),
                new ItemEntityMotion(0.0, 0.1, 0.0),
                10,
            );
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
        }
        $this->pluginEvents?->blockBroken($player, $position, $identifier, $drops);

        return new BlockChanged(
            $command->session,
            $position,
            $this->blockPalette->air,
            $this->players->recipients(),
            $stopsActiveBreak,
            $state,
        );
    }

    private function removeStorageBlockEntity(
        BlockPosition $position,
        ?\Bedriox\Server\World\BlockEntity\BlockEntity $entity,
    ): void {
        if ($this->blockWorld === null || $entity === null) {
            return;
        }
        $this->blockWorld->removeBlockEntity($position);
        $this->worldContainers?->forget($position);
        if (!$entity instanceof ContainerBlockEntity
            || $entity->type !== BlockEntityType::Chest
            || $entity->pairedPosition === null) {
            return;
        }
        $pair = $this->blockWorld->blockEntityAt($entity->pairedPosition);
        if ($pair instanceof ContainerBlockEntity
            && $pair->type === BlockEntityType::Chest
            && $pair->pairedPosition?->equals($position) === true) {
            $pair = $pair->withoutPair();
            $this->blockWorld->setBlockEntity($pair);
            $this->worldContainers?->forget($pair->position);
            $this->deferredEvents[] = new BlockEntityChanged($pair, $this->players->recipients());
        }
    }

    private function closeContainersAt(BlockPosition $position, ApiInventoryCloseReason $reason): void
    {
        foreach ($this->openContainers as $key => $session) {
            if ($session->position?->equals($position) !== true
                && $session->pairedPosition?->equals($position) !== true) {
                continue;
            }
            $player = $this->players->player(substr($key, strlen('session:')));
            if ($player === null) {
                continue;
            }
            $closed = $this->closeContainer($player, $reason, true);
            if ($closed !== null) {
                $this->deferredEvents[] = $closed;
            }
        }
    }

    private function inventoryStackFromContainerItem(ContainerItemStack $stack): InventoryStack
    {
        $placed = null;
        if ($this->itemCatalog?->has($stack->identifier) === true && $this->blockStateRegistry !== null) {
            $state = $this->itemCatalog->type($stack->identifier)->placedBlockState;
            $placed = $state === null ? null : $this->blockStateRegistry->internalId($state);
        }

        return new InventoryStack(
            $stack->identifier,
            $stack->count,
            1,
            $placed,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        );
    }

    private function playerCanBreakBlock(
        Player $player,
        InternalBlockStateId $state,
        ?BlockType $blockType,
    ): bool {
        if (!$player->gameMode()->canBuild()) {
            return false;
        }
        $identifier = $blockType?->identifier();
        if ($identifier === 'minecraft:air'
            || $identifier === 'minecraft:water'
            || $identifier === 'minecraft:lava') {
            return false;
        }
        if ($blockType !== null) {
            return $blockType->isBreakable()
                || ($identifier === 'minecraft:bedrock' && $player->gameMode()->instantlyBreaksBlocks());
        }
        if ($state->value === $this->blockPalette?->air->value
            || $state->value === $this->waterState?->value
            || $state->value === $this->lavaState?->value) {
            return false;
        }

        return $state->value !== $this->blockPalette?->bedrock->value
            || $player->gameMode()->instantlyBreaksBlocks();
    }

    private function armSwingEvent(Player $player, ArmSwingSource $source): ?ArmSwung
    {
        if ($player->lastArmSwingTick === $this->tick) {
            return null;
        }
        $player->lastArmSwingTick = $this->tick;

        return new ArmSwung(
            $player->sessionId,
            $player->runtimeActorId,
            $source,
            $this->players->recipients($player->sessionId),
        );
    }

    public function itemBehaviorRegistry(): ItemBehaviorRegistry
    {
        return $this->itemBehaviors;
    }

    private function admitProjectileLaunch(
        Player $player,
        Projectile $projectile,
        string $identifier,
    ): ?Projectile {
        $admitted = $this->pluginEvents?->projectileLaunch($player, $projectile, $identifier)
            ?? ($this->pluginEvents === null ? $projectile : null);
        if ($admitted === null) {
            $this->projectiles->remove($projectile->runtimeEntityId);

            return null;
        }
        $this->projectiles->replace($admitted);
        $this->pluginEvents?->projectileLaunched($player, $admitted, $identifier);

        return $admitted;
    }

    private function useItem(UseItem $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $key = self::sessionKey($player->sessionId);
        $held = $player->inventory->selectedStack();
        $active = $this->activeItemUses[$key] ?? null;
        if ($active === null && ($this->lastItemUseCompletionTicks[$key] ?? -1) === $this->tick) {
            return new CommandRejected($command->session, 'duplicate_item_use');
        }
        if ($command->hotbarSlot !== $player->inventory->selectedHotbarSlot()) {
            return $active === null
                ? new CommandRejected($command->session, 'selected_slot')
                : $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        if ($active !== null) {
            if (!$active->matches($command->hotbarSlot, $held)) {
                return $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
            }
            if (!$active->isCompleteAt($this->tick)) {
                return $this->cancelItemUse($player, ItemUseCancellationReason::TOO_EARLY);
            }

            if ($active->behavior->kind === ApiItemUseKind::CHARGE) {
                return $held?->identifier === 'minecraft:trident'
                    ? $this->releaseTrident($player, $active)
                    : $this->releaseBow($player, $active);
            }

            return $this->consumeHeldItem($player, $active);
        }
        if ($held === null) {
            return new CommandRejected($command->session, 'empty_hand');
        }
        if ($held->identifier === 'minecraft:crossbow'
            && \Bedriox\Server\Gameplay\Projectile\CrossbowItemData::chargedProjectile($held->nbt) !== null) {
            return $this->fireChargedCrossbow($player, $held);
        }
        $behavior = $this->itemBehaviors->behavior($held->identifier);
        if ($behavior === null || ($this->itemCatalog !== null && !$this->itemCatalog->has($held->identifier))) {
            return new CommandRejected($command->session, 'item_not_usable');
        }
        if ($player->gameMode() === GameMode::SPECTATOR) {
            return new CommandRejected($command->session, 'player_gamemode');
        }
        $this->pruneItemCooldowns($key);
        if (($this->itemCooldowns[$key][$held->identifier] ?? -1) > $this->tick) {
            return new CommandRejected($command->session, 'item_cooldown');
        }
        $consumable = $behavior->consumable;
        if ($consumable !== null
            && $player->gameMode() !== GameMode::CREATIVE
            && !$player->vitals->canConsume($consumable->requiresHunger)) {
            return new CommandRejected($command->session, 'food_full');
        }
        if ($this->pluginEvents !== null
            && !$this->pluginEvents->allowItemUse(
                $player,
                $held,
                $behavior->kind,
                $behavior->useDurationTicks,
            )) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }

        if ($behavior->kind === ApiItemUseKind::INSTANT) {
            if ($behavior->throwablePotion !== null) {
                $potion = (new PotionCatalog())->resolve($held->identifier, $held->auxValue);
                if ($potion === null || $potion->container !== $behavior->throwablePotion) {
                    return new CommandRejected($command->session, 'potion_variant');
                }
                try {
                    $projectile = $this->projectiles->spawn(
                        $player->identity->uuid,
                        $player->runtimeActorId,
                        $potion->type,
                        $behavior->throwablePotion === \Bedriox\Api\Potion\PotionContainer::LINGERING,
                        new Position(
                            $player->movement->position->x,
                            $player->movement->position->y + 1.62,
                            $player->movement->position->z,
                        ),
                        $player->movement->yaw,
                        $player->movement->pitch,
                    );
                } catch (InvalidArgumentException|OverflowException) {
                    return new CommandRejected($command->session, 'potion_projectile_limit');
                }
                $projectile = $this->admitProjectileLaunch($player, $projectile, $held->identifier);
                if ($projectile === null) {
                    return new CommandRejected($command->session, 'plugin_cancelled');
                }
                if ($player->gameMode()->consumesItems()) {
                    $player->inventory->decrementSelectedOne();
                    $player->markDirty();
                    $this->deferredEvents[] = new HeldItemChanged(
                        $player->sessionId,
                        $player->runtimeActorId,
                        $player->inventory->selectedHotbarSlot(),
                        $player->inventory->selectedStack(),
                        $this->players->recipients($player->sessionId),
                        ownerSlotCorrection: true,
                    );
                }
                $this->deferredEvents[] = new ProjectileSpawned(
                    $projectile,
                    $this->players->recipients(),
                );
                $this->projectileEntitiesDirty = true;
            }
            if ($held->identifier === 'minecraft:fishing_rod' && !$this->useFishingRod($player, $held)) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            if ($behavior->cooldownTicks > 0) {
                $this->setItemCooldown($key, $held->identifier, $behavior->cooldownTicks);
            }
            $this->lastItemUseCompletionTicks[$key] = $this->tick;
            $this->pluginEvents?->itemUsed($player, $held, ApiItemUseKind::INSTANT, 0);

            return new InstantItemUsed($player->snapshot(), $held, $this->players->recipients());
        }

        $active = new ItemUseSession($command->hotbarSlot, $held, $behavior, $this->tick);
        $this->activeItemUses[$key] = $active;

        return new ItemUseStarted(
            $player->sessionId,
            $player->runtimeActorId,
            $active->hotbarSlot,
            $active->stack,
            $active->startedAtTick,
            $active->behavior->useDurationTicks,
            $this->players->recipients(),
            $player->movement->sneaking,
            $player->movement->sprinting,
            $player->movement->sequence,
        );
    }

    private function releaseItem(ReleaseItem $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $active = $this->activeItemUses[self::sessionKey($player->sessionId)] ?? null;
        if ($active === null) {
            return new CommandRejected($command->session, 'item_not_in_use');
        }
        if ($active->behavior->kind === ApiItemUseKind::CHARGE
            && $command->hotbarSlot === $player->inventory->selectedHotbarSlot()) {
            return $player->inventory->selectedStack()?->identifier === 'minecraft:trident'
                ? $this->releaseTrident($player, $active)
                : $this->releaseBow($player, $active);
        }
        $reason = $command->hotbarSlot === $player->inventory->selectedHotbarSlot()
            ? ItemUseCancellationReason::RELEASED
            : ItemUseCancellationReason::HELD_ITEM_CHANGED;

        return $this->cancelItemUse($player, $reason);
    }

    private function releaseBow(Player $player, ItemUseSession $active): WorldEvent
    {
        $elapsed = max(0, $this->tick - $active->startedAtTick);
        $weapon = $player->inventory->selectedStack();
        $isCrossbow = $weapon?->identifier === 'minecraft:crossbow';
        $enchantments = WorkstationItemData::enchantments($weapon?->nbt);
        $quickCharge = $enchantments[VanillaEnchantments::QUICK_CHARGE] ?? 0;
        $requiredChargeTicks = $isCrossbow ? max(5, 25 - (5 * $quickCharge)) : 20;
        $charge = min(1.0, $elapsed / $requiredChargeTicks);
        $power = (($charge * $charge) + (2.0 * $charge)) / 3.0;
        if (($isCrossbow && $elapsed < $requiredChargeTicks) || (!$isCrossbow && $power < 0.1)) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::TOO_EARLY);
        }
        $slots = $player->inventory->slots();
        $arrowSlot = null;
        $arrow = null;
        foreach ($slots as $slot => $stack) {
            if ($stack?->identifier === 'minecraft:arrow') {
                $arrowSlot = $slot;
                $arrow = $stack;
                break;
            }
        }
        if ($arrowSlot === null || $arrow === null) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        $bow = $weapon;
        if ($bow === null) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        if ($isCrossbow) {
            $loadedArrow = $arrow->withCountAndNetworkId(1, $arrow->stackNetworkId);
            $chargedBow = new InventoryStack(
                $bow->identifier,
                1,
                $bow->stackNetworkId,
                $bow->placedBlockState,
                $bow->damage,
                \Bedriox\Server\Gameplay\Projectile\CrossbowItemData::withChargedProjectile(
                    $bow->nbt,
                    $loadedArrow,
                ),
                $bow->auxValue,
            );
            if ($player->gameMode()->consumesItems()) {
                $slots[$arrowSlot] = $arrow->decrement();
                $player->inventory->replaceMainContents($slots);
                $this->deferredEvents[] = new InventorySlotChanged(
                    $player->sessionId,
                    $arrowSlot,
                    $slots[$arrowSlot],
                );
            }
            $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $chargedBow);
            $player->markDirty();
            $this->deferredEvents[] = new HeldItemChanged(
                $player->sessionId,
                $player->runtimeActorId,
                $player->inventory->selectedHotbarSlot(),
                $player->inventory->selectedStack(),
                $this->players->recipients($player->sessionId),
                ownerSlotCorrection: true,
            );
            $this->lastItemUseCompletionTicks[self::sessionKey($player->sessionId)] = $this->tick;

            return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
        }
        $infinity = ($enchantments[VanillaEnchantments::INFINITY] ?? 0) > 0;
        $powerLevel = $enchantments[VanillaEnchantments::POWER] ?? 0;
        $punchLevel = $enchantments[VanillaEnchantments::PUNCH] ?? 0;
        $flameLevel = $enchantments[VanillaEnchantments::FLAME] ?? 0;
        $type = $arrow->auxValue === 0
            ? null
            : \Bedriox\Api\Potion\PotionType::tryFrom($arrow->auxValue);
        try {
            $projectile = $this->projectiles->spawnArrow(
                $player->identity->uuid,
                $player->runtimeActorId,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.62,
                    $player->movement->position->z,
                ),
                $player->movement->yaw,
                $player->movement->pitch,
                3.0 * $power,
                !$player->gameMode()->consumesItems()
                    ? ArrowPickupMode::NONE
                    : ($infinity ? ArrowPickupMode::CREATIVE_ONLY : ArrowPickupMode::ANY),
                $powerLevel > 0 ? (0.5 * $powerLevel) + 0.5 : 0.0,
                CombatRules::KNOCKBACK_FORCE
                    + ($punchLevel * EnchantmentEffects::PUNCH_HORIZONTAL_BONUS_PER_LEVEL),
                $flameLevel > 0 ? 100 : 0,
                0,
                $type,
            );
            $projectile = $projectile->withMotion(new EntityMotion(
                $projectile->motion->x + $player->movement->velocityX,
                $projectile->motion->y + ($player->movement->verticalState === VerticalState::AIRBORNE
                    ? $player->movement->verticalVelocity
                    : 0.0),
                $projectile->motion->z + $player->movement->velocityZ,
            ));
            $this->projectiles->replace($projectile);
            $projectile = $this->admitProjectileLaunch($player, $projectile, 'minecraft:arrow');
            if ($projectile === null) {
                return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
            }
        } catch (InvalidArgumentException|OverflowException) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::TIMED_OUT);
        }
        if ($player->gameMode()->consumesItems() && !$infinity) {
            $slots[$arrowSlot] = $arrow->decrement();
            $player->inventory->replaceMainContents($slots);
            $this->deferredEvents[] = new InventorySlotChanged(
                $player->sessionId,
                $arrowSlot,
                $slots[$arrowSlot],
            );
        }
        if ($player->gameMode()->consumesItems()) {
            $maximum = VanillaItemDurability::maximum($bow->identifier);
            if ($maximum !== null) {
                $unbreakingWear = EnchantmentEffects::durabilityDamage(
                    1,
                    EnchantmentEffects::level($bow->nbt, VanillaEnchantments::UNBREAKING),
                    false,
                    $this->dropRandom,
                );
                $wear = $unbreakingWear === 0 ? null : ($this->pluginEvents?->itemDamage(
                    $player,
                    $bow,
                    ApiItemDamageCause::ITEM_USE,
                    ApiEquipmentSlot::MAIN_HAND,
                    $unbreakingWear,
                ) ?? ($this->pluginEvents === null ? $unbreakingWear : null));
                if ($wear !== null && $wear > 0) {
                    $remaining = $bow->damage + $wear >= $maximum ? null : $bow->withDamage($bow->damage + $wear);
                    $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $remaining);
                    if ($remaining === null) {
                        $this->pluginEvents?->itemBroken(
                            $player,
                            $bow,
                            ApiItemDamageCause::ITEM_USE,
                            ApiEquipmentSlot::MAIN_HAND,
                        );
                    }
                    $this->deferredEvents[] = new HeldItemChanged(
                        $player->sessionId,
                        $player->runtimeActorId,
                        $player->inventory->selectedHotbarSlot(),
                        $remaining,
                        $this->players->recipients(),
                        ownerSlotCorrection: true,
                    );
                }
            }
            $player->markDirty();
        }
        $this->deferredEvents[] = new ProjectileSpawned($projectile, $this->players->recipients());
        $this->projectileEntitiesDirty = true;
        $this->lastItemUseCompletionTicks[self::sessionKey($player->sessionId)] = $this->tick;

        return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
    }

    private function fireChargedCrossbow(Player $player, InventoryStack $crossbow): WorldEvent
    {
        $ammunition = \Bedriox\Server\Gameplay\Projectile\CrossbowItemData::chargedProjectile($crossbow->nbt);
        if ($ammunition === null || $ammunition->identifier !== 'minecraft:arrow') {
            return new CommandRejected($player->sessionId, 'crossbow_charge');
        }
        $enchantments = WorkstationItemData::enchantments($crossbow->nbt);
        $multishot = ($enchantments[VanillaEnchantments::MULTISHOT] ?? 0) > 0;
        $piercingLevel = $enchantments[VanillaEnchantments::PIERCING] ?? 0;
        $potionType = $ammunition->auxValue === 0
            ? null
            : \Bedriox\Api\Potion\PotionType::tryFrom($ammunition->auxValue);
        $launched = [];
        try {
            foreach ($multishot ? [-10.0, 0.0, 10.0] : [0.0] as $yawOffset) {
                $projectile = $this->projectiles->spawnArrow(
                    $player->identity->uuid,
                    $player->runtimeActorId,
                    new Position(
                        $player->movement->position->x,
                        $player->movement->position->y + 1.62,
                        $player->movement->position->z,
                    ),
                    $player->movement->yaw + $yawOffset,
                    $player->movement->pitch,
                    3.15,
                    $player->gameMode()->consumesItems() && $yawOffset === 0.0
                        ? ArrowPickupMode::ANY
                        : ArrowPickupMode::NONE,
                    knockbackStrength: CombatRules::KNOCKBACK_FORCE,
                    piercingLevel: $piercingLevel,
                    potionType: $potionType,
                );
                $projectile = $projectile->withMotion(new EntityMotion(
                    $projectile->motion->x + $player->movement->velocityX,
                    $projectile->motion->y + ($player->movement->verticalState === VerticalState::AIRBORNE
                        ? $player->movement->verticalVelocity
                        : 0.0),
                    $projectile->motion->z + $player->movement->velocityZ,
                ));
                $this->projectiles->replace($projectile);
                $projectile = $this->admitProjectileLaunch($player, $projectile, $ammunition->identifier);
                if ($projectile !== null) {
                    $launched[] = $projectile;
                }
            }
        } catch (InvalidArgumentException|OverflowException) {
            foreach ($launched as $projectile) {
                $this->projectiles->remove($projectile->runtimeEntityId);
            }

            return new CommandRejected($player->sessionId, 'projectile_limit');
        }
        if ($launched === []) {
            return new CommandRejected($player->sessionId, 'plugin_cancelled');
        }

        $remainingNbt = \Bedriox\Server\Gameplay\Projectile\CrossbowItemData::withoutChargedProjectile($crossbow->nbt);
        $wear = 0;
        if ($player->gameMode()->consumesItems()) {
            $wear = EnchantmentEffects::durabilityDamage(
                $multishot ? 3 : 1,
                $enchantments[VanillaEnchantments::UNBREAKING] ?? 0,
                false,
                $this->dropRandom,
            );
            if ($wear > 0) {
                $wear = $this->pluginEvents?->itemDamage(
                    $player,
                    $crossbow,
                    ApiItemDamageCause::ITEM_USE,
                    ApiEquipmentSlot::MAIN_HAND,
                    $wear,
                ) ?? ($this->pluginEvents === null ? $wear : 0);
            }
        }
        $maximum = VanillaItemDurability::maximum($crossbow->identifier) ?? 464;
        $replacement = $wear > 0 && $crossbow->damage + $wear >= $maximum
            ? null
            : new InventoryStack(
                $crossbow->identifier,
                1,
                $crossbow->stackNetworkId,
                $crossbow->placedBlockState,
                $crossbow->damage + $wear,
                $remainingNbt,
                $crossbow->auxValue,
            );
        $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $replacement);
        $player->markDirty();
        if ($replacement === null) {
            $this->pluginEvents?->itemBroken(
                $player,
                $crossbow,
                ApiItemDamageCause::ITEM_USE,
                ApiEquipmentSlot::MAIN_HAND,
            );
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $player->inventory->selectedHotbarSlot(),
            $replacement,
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
        foreach ($launched as $projectile) {
            $this->deferredEvents[] = new ProjectileSpawned($projectile, $this->players->recipients());
        }
        $this->projectileEntitiesDirty = true;

        return new InstantItemUsed($player->snapshot(), $crossbow, $this->players->recipients());
    }

    private function releaseTrident(Player $player, ItemUseSession $active): WorldEvent
    {
        $elapsed = max(0, $this->tick - $active->startedAtTick);
        $trident = $player->inventory->selectedStack();
        if ($elapsed < 14 || $trident?->identifier !== 'minecraft:trident') {
            return $this->cancelItemUse($player, ItemUseCancellationReason::TOO_EARLY);
        }
        $maximumDurability = VanillaItemDurability::maximum('minecraft:trident');
        if ($maximumDurability === null || ($player->gameMode()->consumesItems()
            && $trident->damage >= $maximumDurability - 1)) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        $enchantments = WorkstationItemData::enchantments($trident->nbt);
        $riptideLevel = $enchantments[VanillaEnchantments::RIPTIDE] ?? 0;
        $wet = $this->playerIsSubmerged($player)
            || $this->blockWorld?->weather()->weather->isRaining() === true;
        if ($riptideLevel > 0 && !$wet) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        $wear = $player->gameMode()->consumesItems()
            ? EnchantmentEffects::durabilityDamage(
                1,
                $enchantments[VanillaEnchantments::UNBREAKING] ?? 0,
                false,
                $this->dropRandom,
            )
            : 0;
        $thrownItem = $wear > 0 ? $trident->withDamage($trident->damage + $wear) : $trident;
        if ($riptideLevel > 0) {
            $yaw = deg2rad($player->movement->yaw);
            $pitch = deg2rad($player->movement->pitch);
            $speed = 3.0 * (($riptideLevel + 1) / 4.0);
            $horizontal = cos($pitch);
            $riptideMotion = new ApiKnockbackVector(
                -sin($yaw) * $horizontal * $speed,
                -sin($pitch) * $speed,
                cos($yaw) * $horizontal * $speed,
            );
            if ($this->pluginEvents !== null) {
                $riptideMotion = $this->pluginEvents->knockback(
                    $this->pluginEvents->playerView($player),
                    $this->pluginEvents->playerView($player),
                    ApiKnockbackCause::RIPTIDE,
                    $riptideMotion,
                );
            }
            if ($riptideMotion === null) {
                return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
            }
            $player->movement->velocityX = $riptideMotion->x;
            $player->movement->verticalVelocity = $riptideMotion->y;
            $player->movement->velocityZ = $riptideMotion->z;
            $player->movement->verticalState = VerticalState::AIRBORNE;
            if ($wear > 0) {
                $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $thrownItem);
            }
            $player->markDirty();
            $this->pluginEvents?->knockedBack(
                $this->pluginEvents->playerView($player),
                $this->pluginEvents->playerView($player),
                ApiKnockbackCause::RIPTIDE,
                $riptideMotion,
            );
            $this->deferredEvents[] = new PlayerKnockedBack(
                $player->sessionId,
                $player->snapshot(),
                $player->movement->velocityX,
                $player->movement->verticalVelocity,
                $player->movement->velocityZ,
                $player->movement->clientTick,
                $this->players->recipients(),
            );
            if ($wear > 0) {
                $this->deferredEvents[] = new HeldItemChanged(
                    $player->sessionId,
                    $player->runtimeActorId,
                    $player->inventory->selectedHotbarSlot(),
                    $thrownItem,
                    $this->players->recipients($player->sessionId),
                    ownerSlotCorrection: true,
                );
            }

            return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
        }
        $charge = min(1.0, $elapsed / 20.0);
        $speed = min(1.0, (($charge * $charge) + (2.0 * $charge)) / 3.0) * 2.4;
        try {
            $projectile = $this->projectiles->spawnTrident(
                $player->identity->uuid,
                $player->runtimeActorId,
                \Bedriox\Api\Potion\PotionType::WATER,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.62,
                    $player->movement->position->z,
                ),
                $player->movement->yaw,
                $player->movement->pitch,
                $speed,
                2.5 * ($enchantments[VanillaEnchantments::IMPALING] ?? 0),
                $enchantments[VanillaEnchantments::LOYALTY] ?? 0,
                ($enchantments[VanillaEnchantments::CHANNELING] ?? 0) > 0,
                $player->gameMode()->consumesItems(),
                $thrownItem,
            );
            $projectile = $this->admitProjectileLaunch($player, $projectile, 'minecraft:trident');
            if ($projectile === null) {
                return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
            }
        } catch (InvalidArgumentException|OverflowException) {
            return $this->cancelItemUse($player, ItemUseCancellationReason::TIMED_OUT);
        }
        if ($player->gameMode()->consumesItems()) {
            $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), null);
            $player->markDirty();
            $this->deferredEvents[] = new HeldItemChanged(
                $player->sessionId,
                $player->runtimeActorId,
                $player->inventory->selectedHotbarSlot(),
                null,
                $this->players->recipients($player->sessionId),
                ownerSlotCorrection: true,
            );
        }
        $this->deferredEvents[] = new ProjectileSpawned($projectile, $this->players->recipients());
        $this->projectileEntitiesDirty = true;

        return $this->cancelItemUse($player, ItemUseCancellationReason::RELEASED);
    }

    private function useFishingRod(Player $player, InventoryStack $rod): bool
    {
        $hook = null;
        foreach ($this->projectiles->all() as $candidate) {
            if ($candidate->type === ProjectileType::FISHING_HOOK
                && $candidate->ownerType === ProjectileOwnerType::PLAYER
                && $candidate->ownerUuid === $player->identity->uuid
                && $candidate->ownerRuntimeEntityId === $player->runtimeActorId) {
                $hook = $candidate;
                break;
            }
        }
        if ($hook === null) {
            $enchantments = WorkstationItemData::enchantments($rod->nbt);
            try {
                $hook = $this->projectiles->spawnFishingHook(
                    $player->identity->uuid,
                    $player->runtimeActorId,
                    new Position(
                        $player->movement->position->x,
                        $player->movement->position->y + 1.62,
                        $player->movement->position->z,
                    ),
                    $player->movement->yaw,
                    $player->movement->pitch,
                    $enchantments[VanillaEnchantments::LUCK_OF_THE_SEA] ?? 0,
                    $enchantments[VanillaEnchantments::LURE] ?? 0,
                );
                $hook = $this->admitProjectileLaunch($player, $hook, 'minecraft:fishing_hook');
                if ($hook === null || ($this->pluginEvents !== null && $this->pluginEvents->fish(
                    $player,
                    $hook->runtimeEntityId,
                    \Bedriox\Api\Event\Player\PlayerFishState::CAST,
                ) === null)) {
                    if ($hook !== null) {
                        $this->projectiles->remove($hook->runtimeEntityId);
                    }
                    return false;
                }
            } catch (InvalidArgumentException|OverflowException) {
                return false;
            }
            $this->deferredEvents[] = new ProjectileSpawned($hook, $this->players->recipients());
            $this->pluginEvents?->fished(
                $player,
                $hook->runtimeEntityId,
                \Bedriox\Api\Event\Player\PlayerFishState::CAST,
            );
            $this->projectileEntitiesDirty = true;

            return true;
        }

        $caught = $hook->fishingBiteActive() ? $this->fishingLoot($hook) : null;
        $experience = $caught === null ? 0 : $this->dropRandom->integer(1, 6);
        $state = $caught === null
            ? \Bedriox\Api\Event\Player\PlayerFishState::FAILED_ATTEMPT
            : \Bedriox\Api\Event\Player\PlayerFishState::CAUGHT_ITEM;
        $fishEvent = $this->pluginEvents?->fish($player, $hook->runtimeEntityId, $state, $caught, $experience);
        if ($this->pluginEvents !== null && $fishEvent === null) {
            return false;
        }
        if ($fishEvent !== null) {
            $caught = $fishEvent->caughtItem() === null
                ? null
                : $this->inventoryStackFromApi($fishEvent->caughtItem());
            $experience = $fishEvent->experience();
        }
        $this->projectiles->remove($hook->runtimeEntityId);
        $this->deferredEvents[] = new ProjectileRemoved($hook->runtimeEntityId, $this->players->recipients());
        if ($caught !== null && $this->itemEntities->canSpawn()) {
            $dx = $player->movement->position->x - $hook->position->x;
            $dy = ($player->movement->position->y + 1.0) - $hook->position->y;
            $dz = $player->movement->position->z - $hook->position->z;
            $distance = max(0.001, hypot(hypot($dx, $dz), $dy));
            $entity = $this->itemEntities->spawn(
                $caught,
                $hook->position,
                new ItemEntityMotion(
                    ($dx / $distance) * 0.35,
                    0.2 + (($dy / $distance) * 0.2),
                    ($dz / $distance) * 0.35,
                ),
                10,
            );
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
            if ($experience > 0) {
                array_push($this->deferredEvents, ...$this->spawnExperienceOrbs($experience, $player->movement->position));
            }
        }
        $this->damageFishingRod($player, $rod);
        $this->pluginEvents?->fished($player, $hook->runtimeEntityId, $state, $caught, $experience);
        $this->projectileEntitiesDirty = true;

        return true;
    }

    private function fishingLoot(Projectile $hook): InventoryStack
    {
        $roll = $this->dropRandom->integer(1, 100);
        $treasureThreshold = 5 + (2 * $hook->fishingLuckLevel);
        $junkThreshold = 15 - (2 * $hook->fishingLuckLevel);
        if ($roll <= $treasureThreshold) {
            $pool = ['minecraft:bow', 'minecraft:enchanted_book', 'minecraft:fishing_rod', 'minecraft:name_tag', 'minecraft:nautilus_shell', 'minecraft:saddle'];
        } elseif ($roll <= $junkThreshold) {
            $pool = ['minecraft:leather', 'minecraft:bowl', 'minecraft:stick', 'minecraft:string', 'minecraft:bone', 'minecraft:tripwire_hook', 'minecraft:rotten_flesh', 'minecraft:lily_pad'];
        } else {
            $pool = ['minecraft:cod', 'minecraft:salmon', 'minecraft:pufferfish', 'minecraft:tropical_fish'];
        }
        $identifier = $pool[$this->dropRandom->integer(0, count($pool) - 1)];

        return new InventoryStack($identifier, 1, 1);
    }

    private function damageFishingRod(Player $player, InventoryStack $rod): void
    {
        if (!$player->gameMode()->consumesItems()) {
            return;
        }
        $wear = EnchantmentEffects::durabilityDamage(
            1,
            EnchantmentEffects::level($rod->nbt, VanillaEnchantments::UNBREAKING),
            false,
            $this->dropRandom,
        );
        if ($wear === 0) {
            return;
        }
        $wear = $this->pluginEvents?->itemDamage(
            $player,
            $rod,
            ApiItemDamageCause::ITEM_USE,
            ApiEquipmentSlot::MAIN_HAND,
            $wear,
        ) ?? ($this->pluginEvents === null ? $wear : null);
        if ($wear === null || $wear < 1) {
            return;
        }
        $maximum = VanillaItemDurability::maximum($rod->identifier) ?? 384;
        $remaining = $rod->damage + $wear >= $maximum ? null : $rod->withDamage($rod->damage + $wear);
        $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $remaining);
        $player->markDirty();
        if ($remaining === null) {
            $this->pluginEvents?->itemBroken(
                $player,
                $rod,
                ApiItemDamageCause::ITEM_USE,
                ApiEquipmentSlot::MAIN_HAND,
            );
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
    }

    private function consumeHeldItem(Player $player, ItemUseSession $active): WorldEvent
    {
        $key = self::sessionKey($player->sessionId);
        unset($this->activeItemUses[$key]);
        $held = $player->inventory->selectedStack();
        if (!$active->matches($player->inventory->selectedHotbarSlot(), $held) || $held === null) {
            return $this->itemUseCancelledEvent($player, $active, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
        $consumable = $active->behavior->consumable;
        if ($consumable === null || ($player->gameMode() !== GameMode::CREATIVE
            && !$player->vitals->canConsume($consumable->requiresHunger))) {
            return $this->itemUseCancelledEvent($player, $active, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }

        $consumed = $held->withCountAndNetworkId(1, $held->stackNetworkId);
        $previousNutrition = PluginGameplayEventBridge::nutrition($player);
        $result = new ApiConsumptionResult(
            (int) $consumable->foodRestore,
            $consumable->saturationRestore,
            $consumable->residue,
        );
        if ($this->pluginEvents !== null) {
            $result = $this->pluginEvents->consume($player, $consumed, $previousNutrition, $result);
            if ($result === null) {
                return $this->itemUseCancelledEvent($player, $active, ItemUseCancellationReason::PLUGIN);
            }
        }

        $inventoryBefore = clone $player->inventory;
        $residue = [];
        try {
            foreach ($result->residue as $stack) {
                $residue[] = $this->inventoryStackFromApi($stack);
            }
            $stagedInventory = clone $player->inventory;
            if ($player->gameMode()->consumesItems()) {
                $this->applyConsumptionInventory($stagedInventory, $active->hotbarSlot, $residue);
            }
        } catch (InvalidArgumentException|OverflowException) {
            return $this->itemUseCancelledEvent($player, $active, ItemUseCancellationReason::PLUGIN);
        }

        $proposedNutrition = new ApiNutrition(
            min(ApiNutrition::MAX_FOOD_LEVEL, $previousNutrition->foodLevel + $result->foodRestore),
            min(ApiNutrition::MAX_SATURATION_LEVEL, $previousNutrition->saturationLevel + $result->saturationRestore),
            $previousNutrition->exhaustionLevel,
        );
        $nutritionChanges = $proposedNutrition != $previousNutrition;
        if ($nutritionChanges && $this->pluginEvents !== null) {
            $proposedNutrition = $this->pluginEvents->nutritionChange(
                $player,
                $previousNutrition,
                $proposedNutrition,
                ApiFoodLevelChangeCause::CONSUMPTION,
            );
            if ($proposedNutrition === null) {
                return $this->itemUseCancelledEvent($player, $active, ItemUseCancellationReason::PLUGIN);
            }
        }

        $stagedEffects = $this->stageConsumedItemEffects($player, $held, $active->behavior->effects);

        $droppedResidue = null;
        if ($player->gameMode()->consumesItems()) {
            foreach ($this->applyConsumptionInventory($player->inventory, $active->hotbarSlot, $residue) as $drop) {
                $droppedResidue ??= $drop;
                $entity = $this->itemEntities->spawn(
                    $drop,
                    new Position(
                        $player->movement->position->x,
                        $player->movement->position->y + 1.3,
                        $player->movement->position->z,
                    ),
                    new ItemEntityMotion(0.0, 0.1, 0.0),
                    40,
                );
                $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
            }
        }
        if ($nutritionChanges) {
            $player->vitals->setNutrition(
                $proposedNutrition->foodLevel,
                $proposedNutrition->saturationLevel,
                $proposedNutrition->exhaustionLevel,
            );
        }
        if ($active->behavior->cooldownTicks > 0) {
            $this->setItemCooldown($key, $held->identifier, $active->behavior->cooldownTicks);
        }
        $this->commitConsumedItemEffects($player, $active->behavior->effects, $stagedEffects);
        $player->markDirty();
        $this->lastItemUseCompletionTicks[$key] = $this->tick;
        $currentNutrition = PluginGameplayEventBridge::nutrition($player);
        $this->pluginEvents?->consumed($player, $consumed, $previousNutrition, $currentNutrition, $result);
        if ($nutritionChanges) {
            $this->pluginEvents?->nutritionChanged(
                $player,
                $previousNutrition,
                $currentNutrition,
                ApiFoodLevelChangeCause::CONSUMPTION,
            );
            $this->deferredEvents[] = new NutritionChanged(
                $player->snapshot(),
                $previousNutrition->foodLevel,
                $previousNutrition->saturationLevel,
                $previousNutrition->exhaustionLevel,
                NutritionChangeReason::CONSUMPTION,
                [$player->sessionId],
            );
        }
        $this->pluginEvents?->itemUsed(
            $player,
            $consumed,
            ApiItemUseKind::CONSUME,
            $this->tick - $active->startedAtTick,
        );

        return new ItemConsumed(
            $player->snapshot(),
            $consumed,
            $active->hotbarSlot,
            $player->inventory->selectedStack(),
            self::changedMainInventorySlots($inventoryBefore, $player->inventory),
            $player->inventory->slots(),
            $droppedResidue,
            $this->players->recipients(),
        );
    }

    /**
     * Dispatches every cancellable effect boundary before inventory or nutrition is committed.
     *
     * @return array{remove: list<EffectType>, add: list<EffectInstance>}
     */
    private function stageConsumedItemEffects(
        Player $player,
        InventoryStack $consumed,
        ?ConsumableEffectDefinition $definition,
    ): array {
        if ($definition === null) {
            return ['remove' => [], 'add' => []];
        }
        $removals = [];
        if ($definition->clearExisting) {
            foreach ($player->effects->snapshot() as $effect) {
                if ($this->pluginEvents === null
                    || $this->pluginEvents->allowEffectRemoval($player, $effect, $definition->cause)) {
                    $removals[] = $effect->type;
                }
            }
        }
        $effects = $definition->effects;
        if ($definition->resolvePotionAuxiliaryValue) {
            $potion = (new PotionCatalog())->resolve($consumed->identifier, $consumed->auxValue);
            $effects = $potion === null
                ? []
                : array_map(
                    static fn(\Bedriox\Server\Gameplay\Potion\PotionEffectDose $dose): EffectInstance => $dose->effect,
                    (new PotionEffectProjector())->drink($potion->type),
                );
        }
        $approved = [];
        foreach ($effects as $effect) {
            $effect = $this->pluginEvents?->addEffect($player, $effect, $definition->cause)
                ?? ($this->pluginEvents === null ? $effect : null);
            if ($effect !== null) {
                $approved[] = $effect;
            }
        }

        return ['remove' => $removals, 'add' => $approved];
    }

    /** @param array{remove: list<EffectType>, add: list<EffectInstance>} $staged */
    private function commitConsumedItemEffects(
        Player $player,
        ?ConsumableEffectDefinition $definition,
        array $staged,
    ): void {
        if ($definition === null) {
            return;
        }
        foreach ($staged['remove'] as $type) {
            $event = $this->removePlayerEffect(
                new RemovePlayerEffect($player->sessionId, $type, $definition->cause),
                false,
            );
            if ($event !== null) {
                $this->deferredEvents[] = $event;
            }
        }
        foreach ($staged['add'] as $effect) {
            $event = $this->addPlayerEffect(
                new AddPlayerEffect($player->sessionId, $effect, $definition->cause),
                false,
            );
            if ($event !== null) {
                $this->deferredEvents[] = $event;
            }
        }
    }

    /** @return list<WorldEvent> */
    private function advanceBrewingStands(): array
    {
        if ($this->blockWorld === null || $this->worldContainers === null || $this->brewingStands === null) {
            return [];
        }
        // Re-admit only persisted in-progress stands when their chunk becomes available. Idle
        // stands are activated by inventory mutation/opening and never recipe-scanned per tick.
        if ($this->tick % 20 === 0) {
            foreach ($this->blockWorld->loadedBlockEntities() as $loaded) {
                if ($loaded instanceof BrewingStandBlockEntity && $loaded->brewTime > 0) {
                    $this->scheduleBrewingStand($loaded->position);
                }
            }
        }
        $events = [];
        foreach ($this->activeBrewingStands as $key => $position) {
            $entity = $this->blockWorld->blockEntityAt($position);
            if (!$entity instanceof BrewingStandBlockEntity) {
                unset($this->activeBrewingStands[$key]);
                continue;
            }
            $bottleState = $this->synchronizeBrewingStandBottleState($entity);
            if ($bottleState !== null) {
                $events[] = $bottleState;
            }
            $result = $this->brewingStands->tick($entity);
            if ($result->state === $entity) {
                unset($this->activeBrewingStands[$key]);
                continue;
            }
            $fuelLoaded = $result->state->fuelTotal > $entity->fuelTotal;
            if ($fuelLoaded && $this->pluginEvents !== null) {
                $fuelUses = $this->pluginEvents->brewingFuel(
                    $entity,
                    $result->state->fuelTotal - $entity->fuelTotal,
                );
                if ($fuelUses === null) {
                    continue;
                }
                $result = new \Bedriox\Server\Gameplay\Potion\BrewingStandTickResult(
                    $result->state->withState(
                        $result->state->inventory,
                        $result->state->brewTime,
                        max(0, $fuelUses - 1),
                        $fuelUses,
                    ),
                    $result->started,
                    $result->completed,
                    $result->changedSlots,
                );
            }
            if ($result->completed && $this->pluginEvents !== null) {
                $brewed = $this->pluginEvents->brew($result->state);
                if ($brewed === null) {
                    continue;
                }
                $inventory = $result->state->inventory;
                foreach ($brewed as $offset => $stack) {
                    $inventory = $inventory->withStack(
                        BrewingStandBlockEntity::SLOT_BOTTLE_LEFT + $offset,
                        $stack === null ? null : new ContainerItemStack(
                            $stack->identifier,
                            $stack->count,
                            $stack->damage,
                            $stack->nbt,
                            $stack->auxValue,
                        ),
                    );
                }
                $result = new \Bedriox\Server\Gameplay\Potion\BrewingStandTickResult(
                    $result->state->withState(
                        $inventory,
                        $result->state->brewTime,
                        $result->state->fuelAmount,
                        $result->state->fuelTotal,
                    ),
                    $result->started,
                    $result->completed,
                    [
                        BrewingStandBlockEntity::SLOT_INGREDIENT,
                        BrewingStandBlockEntity::SLOT_BOTTLE_LEFT,
                        BrewingStandBlockEntity::SLOT_BOTTLE_MIDDLE,
                        BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT,
                    ],
                );
            }
            $this->blockWorld->setBlockEntity($result->state);
            if ($fuelLoaded) {
                $this->pluginEvents?->brewingFuelConsumed(
                    $entity,
                    $result->state->fuelTotal - $entity->fuelTotal,
                );
            }
            if ($result->completed) {
                $this->pluginEvents?->brewed($result->state);
                $events[] = new BrewingCompleted($result->state->position, $this->players->recipients());
            }
            $inventory = $this->worldContainers->synchronizeBrewingStand($result->state);
            if ($inventory === null) {
                continue;
            }
            $viewers = [];
            foreach ($this->openContainers as $key => $session) {
                if ($session->type !== ApiContainerType::BREWING_STAND
                    || $session->position?->equals($entity->position) !== true) {
                    continue;
                }
                $player = $this->players->player(substr($key, strlen('session:')));
                if ($player === null) {
                    continue;
                }
                $this->refreshContainerProjection($player, $session);
                $viewers[] = new ContainerViewerProjection(
                    $player->sessionId,
                    $session->windowId,
                    $session->projection->slots(),
                );
            }
            if ($viewers !== []) {
                $events[] = new BrewingStandUpdated(
                    $viewers,
                    $result->changedSlots,
                    $result->state->brewTime,
                    $result->state->fuelAmount,
                    $result->state->fuelTotal,
                );
            }
        }

        return $events;
    }

    private function scheduleBrewingStand(BlockPosition $position): void
    {
        $this->activeBrewingStands[$position->x . ':' . $position->y . ':' . $position->z] = $position;
    }

    /** @return list<WorldEvent> */
    private function advanceFurnaces(): array
    {
        if ($this->blockWorld === null || $this->worldContainers === null || $this->furnaces === null) {
            return [];
        }
        if ($this->tick % 20 === 0) {
            foreach ($this->blockWorld->loadedBlockEntities() as $loaded) {
                if ($loaded instanceof FurnaceBlockEntity && $loaded->active()) {
                    $this->scheduleFurnace($loaded->position);
                }
            }
        }
        $events = [];
        foreach ($this->processingStations->drainDue($this->tick, 1_024) as $scheduled) {
            $position = $this->furnacePositions[$scheduled->key] ?? null;
            if (!$position instanceof BlockPosition) {
                continue;
            }
            $entity = $this->blockWorld->blockEntityAt($position);
            if (!$entity instanceof FurnaceBlockEntity) {
                unset($this->furnacePositions[$scheduled->key]);
                continue;
            }
            $result = $this->furnaces->tick($entity);
            $state = $result->state;
            $changedSlots = $result->changedSlots;
            $fuelConsumed = $result->fuelConsumed;
            $started = $result->started;
            $completed = $result->completed;
            $input = $entity->inventory->stackAt(FurnaceBlockEntity::SLOT_INPUT);
            $recipe = $input === null ? null : $this->processingRecipes?->match($entity->furnaceType, $input);
            $apiPosition = new \Bedriox\Api\World\BlockPosition($position->x, $position->y, $position->z);
            $apiFurnaceType = match ($entity->furnaceType) {
                FurnaceType::Furnace => \Bedriox\Api\Processing\FurnaceType::FURNACE,
                FurnaceType::BlastFurnace => \Bedriox\Api\Processing\FurnaceType::BLAST_FURNACE,
                FurnaceType::Smoker => \Bedriox\Api\Processing\FurnaceType::SMOKER,
            };
            $fuelEvent = null;
            $startEvent = null;
            $smeltEvent = null;

            if ($fuelConsumed) {
                $fuel = $entity->inventory->stackAt(FurnaceBlockEntity::SLOT_FUEL);
                if ($fuel !== null) {
                    $fuelEvent = $this->pluginEvents?->furnaceFuel(
                        $apiPosition,
                        $apiFurnaceType,
                        new ApiItemStack($fuel->identifier, 1, $fuel->damage, $fuel->nbt, $fuel->auxValue),
                        \Bedriox\Api\Processing\FurnaceFuelCause::PROCESSING,
                        $state->burnDuration,
                    );
                    if ($this->pluginEvents !== null && $fuelEvent === null) {
                        $state = $entity;
                        $changedSlots = [];
                        $fuelConsumed = $started = $completed = false;
                    } elseif ($fuelEvent !== null && $fuelEvent->burnTicks() !== $state->burnDuration) {
                        $state = new FurnaceBlockEntity(
                            $state->furnaceType,
                            $state->position,
                            $state->inventory,
                            max(0, $fuelEvent->burnTicks() - 1),
                            $fuelEvent->burnTicks(),
                            $state->cookTime,
                            $state->cookDuration,
                            $state->storedExperienceMilli,
                            $state->customName,
                            $state->revision,
                        );
                    }
                }
            }

            if ($started && $input !== null && $recipe !== null) {
                $startEvent = $this->pluginEvents?->furnaceStartSmelt(
                    $apiPosition,
                    $apiFurnaceType,
                    new ApiItemStack($input->identifier, $input->count, $input->damage, $input->nbt, $input->auxValue),
                    new ApiItemStack(
                        $recipe->output->identifier,
                        $recipe->output->count,
                        $recipe->output->damage,
                        $recipe->output->nbt,
                        $recipe->output->auxValue,
                    ),
                    $state->cookDuration,
                );
                if ($this->pluginEvents !== null && $startEvent === null) {
                    $state = new FurnaceBlockEntity(
                        $state->furnaceType,
                        $state->position,
                        $state->inventory,
                        $state->burnTime,
                        $state->burnDuration,
                        0,
                        $state->cookDuration,
                        $state->storedExperienceMilli,
                        $state->customName,
                        $state->revision,
                    );
                    $started = $completed = false;
                } elseif ($startEvent !== null && $startEvent->cookTicks() !== $state->cookDuration) {
                    $state = new FurnaceBlockEntity(
                        $state->furnaceType,
                        $state->position,
                        $state->inventory,
                        $state->burnTime,
                        $state->burnDuration,
                        min($state->cookTime, $startEvent->cookTicks()),
                        $startEvent->cookTicks(),
                        $state->storedExperienceMilli,
                        $state->customName,
                        $state->revision,
                    );
                }
            }

            if ($completed && $input !== null && $recipe !== null) {
                $smeltEvent = $this->pluginEvents?->furnaceSmelt(
                    $apiPosition,
                    $apiFurnaceType,
                    new ApiItemStack($input->identifier, $input->count, $input->damage, $input->nbt, $input->auxValue),
                    new ApiItemStack(
                        $recipe->output->identifier,
                        $recipe->output->count,
                        $recipe->output->damage,
                        $recipe->output->nbt,
                        $recipe->output->auxValue,
                    ),
                );
                if ($this->pluginEvents !== null && $smeltEvent === null) {
                    $inventory = $state->inventory
                        ->withStack(FurnaceBlockEntity::SLOT_INPUT, $input)
                        ->withStack(FurnaceBlockEntity::SLOT_RESULT, $entity->inventory->stackAt(FurnaceBlockEntity::SLOT_RESULT));
                    $state = $state->withState(
                        $inventory,
                        $state->burnTime,
                        $state->burnDuration,
                        $entity->cookTime,
                        $entity->storedExperienceMilli,
                    );
                    $changedSlots = array_values(array_diff(
                        $changedSlots,
                        [FurnaceBlockEntity::SLOT_INPUT, FurnaceBlockEntity::SLOT_RESULT],
                    ));
                    $completed = false;
                } elseif ($smeltEvent !== null && $smeltEvent->result() != new ApiItemStack(
                    $recipe->output->identifier,
                    $recipe->output->count,
                    $recipe->output->damage,
                    $recipe->output->nbt,
                    $recipe->output->auxValue,
                )) {
                    $replacement = $smeltEvent->result();
                    $existing = $entity->inventory->stackAt(FurnaceBlockEntity::SLOT_RESULT);
                    $canMerge = $existing === null || ($existing->identifier === $replacement->identifier
                        && $existing->damage === $replacement->damage
                        && $existing->auxValue === $replacement->auxValue
                        && $existing->nbt == $replacement->nbt
                        && $existing->count + $replacement->count <= 64);
                    if ($canMerge) {
                        $output = $existing === null
                            ? new ContainerItemStack($replacement->identifier, $replacement->count, $replacement->damage, $replacement->nbt, $replacement->auxValue)
                            : new ContainerItemStack($existing->identifier, $existing->count + $replacement->count, $existing->damage, $existing->nbt, $existing->auxValue);
                        $state = $state->withState(
                            $state->inventory->withStack(FurnaceBlockEntity::SLOT_RESULT, $output),
                            $state->burnTime,
                            $state->burnDuration,
                            $state->cookTime,
                            $state->storedExperienceMilli,
                        );
                    } else {
                        $inventory = $state->inventory
                            ->withStack(FurnaceBlockEntity::SLOT_INPUT, $input)
                            ->withStack(FurnaceBlockEntity::SLOT_RESULT, $existing);
                        $state = $state->withState(
                            $inventory,
                            $state->burnTime,
                            $state->burnDuration,
                            $entity->cookTime,
                            $entity->storedExperienceMilli,
                        );
                        $changedSlots = array_values(array_diff(
                            $changedSlots,
                            [FurnaceBlockEntity::SLOT_INPUT, FurnaceBlockEntity::SLOT_RESULT],
                        ));
                        $completed = false;
                    }
                }
            }

            if ($state === $entity) {
                unset($this->furnacePositions[$scheduled->key]);
                continue;
            }
            $this->blockWorld->setBlockEntity($state);
            $litChanged = $this->synchronizeFurnaceLitState($position, $state->active());
            if ($litChanged !== null) {
                $events[] = $litChanged;
            }
            if ($fuelConsumed && $fuelEvent !== null) {
                $this->pluginEvents->furnaceFuelConsumed(
                    $apiPosition,
                    $apiFurnaceType,
                    $fuelEvent->fuel,
                    $fuelEvent->cause,
                    $fuelEvent->burnTicks(),
                );
            }
            if ($started && $startEvent !== null) {
                $this->pluginEvents->furnaceStartedSmelting(
                    $apiPosition,
                    $apiFurnaceType,
                    $startEvent->input,
                    $startEvent->result,
                    $startEvent->cookTicks(),
                );
            }
            if ($completed && $smeltEvent !== null) {
                $this->pluginEvents->furnaceSmelted(
                    $apiPosition,
                    $apiFurnaceType,
                    $smeltEvent->input,
                    $smeltEvent->result(),
                );
            }
            if ($state->active()) {
                $this->processingStations->schedule($scheduled->key, $this->tick + 1);
            } else {
                unset($this->furnacePositions[$scheduled->key]);
            }
            $inventory = $this->worldContainers->synchronizeFurnace($state);
            if ($inventory === null) {
                continue;
            }
            $viewers = [];
            foreach ($this->openContainers as $key => $session) {
                if (!in_array($session->type, [
                    ApiContainerType::FURNACE,
                    ApiContainerType::BLAST_FURNACE,
                    ApiContainerType::SMOKER,
                ], true) || $session->position?->equals($position) !== true) {
                    continue;
                }
                $player = $this->players->player(substr($key, strlen('session:')));
                if ($player === null) {
                    continue;
                }
                $this->refreshContainerProjection($player, $session);
                $viewers[] = new ContainerViewerProjection(
                    $player->sessionId,
                    $session->windowId,
                    $session->projection->slots(),
                );
            }
            if ($viewers !== []) {
                $events[] = new FurnaceUpdated(
                    $viewers,
                    $changedSlots,
                    $state->cookTime,
                    $state->burnTime,
                    $state->burnDuration,
                    $state->storedExperienceMilli,
                );
            }
        }

        return $events;
    }

    private function scheduleFurnace(BlockPosition $position): void
    {
        $key = 'furnace/' . $position->x . ':' . $position->y . ':' . $position->z;
        $this->furnacePositions[$key] = $position;
        $this->processingStations->schedule($key, $this->tick + 1);
    }

    private function synchronizeFurnaceLitState(BlockPosition $position, bool $lit): ?BlockChanged
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return null;
        }
        $current = $this->blockWorld->blockStateAt($position->x, $position->y, $position->z);
        $canonical = $this->blockStateRegistry->state($current);
        $baseIdentifier = match ($canonical->identifier()) {
            'minecraft:furnace', 'minecraft:lit_furnace' => 'minecraft:furnace',
            'minecraft:blast_furnace', 'minecraft:lit_blast_furnace' => 'minecraft:blast_furnace',
            'minecraft:smoker', 'minecraft:lit_smoker' => 'minecraft:smoker',
            default => null,
        };
        if ($baseIdentifier === null) {
            return null;
        }
        $targetIdentifier = $lit ? str_replace('minecraft:', 'minecraft:lit_', $baseIdentifier) : $baseIdentifier;
        if ($canonical->identifier() === $targetIdentifier) {
            return null;
        }
        $updated = $this->blockStateRegistry->internalId(CanonicalBlockState::from(
            $targetIdentifier,
            $canonical->properties(),
        ));
        $this->setBlockStateAndSchedule($position, $updated);

        return new BlockChanged('server', $position, $updated, $this->players->recipients());
    }

    /** @return list<WorldEvent> */
    private function advanceCampfires(): array
    {
        if ($this->blockWorld === null || $this->campfires === null) {
            return [];
        }
        if ($this->tick % 20 === 0) {
            foreach ($this->blockWorld->loadedBlockEntities() as $loaded) {
                if ($loaded instanceof CampfireBlockEntity && $loaded->active()) {
                    $this->scheduleCampfire($loaded->position);
                }
            }
        }
        $events = [];
        foreach ($this->campfireStations->drainDue($this->tick, 1_024) as $scheduled) {
            $position = $this->campfirePositions[$scheduled->key] ?? null;
            if (!$position instanceof BlockPosition) {
                continue;
            }
            $entity = $this->blockWorld->blockEntityAt($position);
            if (!$entity instanceof CampfireBlockEntity) {
                unset($this->campfirePositions[$scheduled->key]);
                continue;
            }
            $state = $this->blockWorld->blockStateAt($position->x, $position->y, $position->z);
            $canonical = $this->blockStateRegistry?->state($state);
            $lit = ($canonical?->properties()['extinguished'] ?? 0) === 0;
            $result = $this->campfires->tick($entity, $lit);
            if ($result->state === $entity) {
                unset($this->campfirePositions[$scheduled->key]);
                continue;
            }
            $state = $result->state;
            $completed = [];
            foreach ($result->completed as $slot => $output) {
                $input = $entity->inventory->stackAt($slot);
                if ($input === null) {
                    continue;
                }
                $cookEvent = $this->pluginEvents?->campfireCook(
                    new \Bedriox\Api\World\BlockPosition($position->x, $position->y, $position->z),
                    $slot,
                    new ApiItemStack($input->identifier, $input->count, $input->damage, $input->nbt, $input->auxValue),
                    new ApiItemStack($output->identifier, $output->count, $output->damage, $output->nbt, $output->auxValue),
                    $entity->campfireType === CampfireType::SoulCampfire,
                );
                if ($this->pluginEvents !== null && $cookEvent === null) {
                    $progress = $state->progressBySlot;
                    $duration = $state->durationBySlot;
                    $cookDuration = $entity->durationBySlot[$slot] ?? CampfireBlockEntity::DEFAULT_COOK_TIME_TICKS;
                    $progress[$slot] = max(0, $cookDuration - 1);
                    $duration[$slot] = $cookDuration;
                    $state = $state->withState(
                        $state->inventory->withStack($slot, $input),
                        $progress,
                        $duration,
                    );
                    continue;
                }
                $committedOutput = $cookEvent?->result() ?? new ApiItemStack(
                    $output->identifier,
                    $output->count,
                    $output->damage,
                    $output->nbt,
                    $output->auxValue,
                );
                $completed[] = [$committedOutput, $cookEvent];
            }
            $this->blockWorld->setBlockEntity($state);
            $events[] = new BlockEntityChanged($state, $this->players->recipients());
            foreach ($completed as [$output, $cookEvent]) {
                if ($cookEvent !== null && $this->pluginEvents !== null) {
                    $this->pluginEvents->campfireCooked(
                        $cookEvent->position,
                        $cookEvent->slot,
                        $cookEvent->input,
                        $cookEvent->result(),
                        $cookEvent->soulCampfire,
                    );
                }
                if (!$this->itemEntities->canSpawn()) {
                    continue;
                }
                $stack = $this->inventoryStackFromApi($output);
                $item = $this->itemEntities->spawn(
                    $stack,
                    new Position($position->x + 0.5, $position->y + 1.0, $position->z + 0.5),
                    new ItemEntityMotion(0.0, 0.1, 0.0),
                    10,
                );
                $events[] = new ItemEntitySpawned($item, $this->players->recipients());
            }
            if ($state->active()) {
                $this->campfireStations->schedule($scheduled->key, $this->tick + 1);
            } else {
                unset($this->campfirePositions[$scheduled->key]);
            }
        }

        return $events;
    }

    private function scheduleCampfire(BlockPosition $position): void
    {
        $key = 'campfire/' . $position->x . ':' . $position->y . ':' . $position->z;
        $this->campfirePositions[$key] = $position;
        $this->campfireStations->schedule($key, $this->tick + 1);
    }

    /** @return list<WorldEvent> */
    private function advanceComposters(): array
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return [];
        }
        $this->discoverPendingComposters();
        $events = [];
        foreach ($this->composterStations->drainDue($this->tick, 1_024) as $scheduled) {
            $position = $this->composterPositions[$scheduled->key] ?? null;
            unset($this->composterPositions[$scheduled->key]);
            if (!$position instanceof BlockPosition) {
                continue;
            }
            $current = $this->blockWorld->loadedBlockStateAt($position->x, $position->y, $position->z);
            if ($current === null) {
                continue;
            }
            $canonical = $this->blockStateRegistry->state($current);
            $level = $canonical->properties()['composter_fill_level'] ?? null;
            if ($canonical->identifier() !== 'minecraft:composter' || $level !== 7) {
                continue;
            }
            $matured = $this->composters->mature(new ComposterState($level));
            $apiPosition = new ApiBlockPosition($position->x, $position->y, $position->z);
            $change = $this->pluginEvents?->composterChange(
                null,
                $apiPosition,
                $level,
                $matured->level,
                \Bedriox\Api\Processing\ComposterChangeCause::MATURE,
            );
            if ($this->pluginEvents !== null && $change === null) {
                $this->scheduleComposter($position);
                continue;
            }
            $newLevel = $change?->newLevel() ?? $matured->level;
            $properties = $canonical->properties();
            $properties['composter_fill_level'] = $newLevel;
            $updated = $this->blockStateRegistry->internalId(CanonicalBlockState::from(
                'minecraft:composter',
                $properties,
            ));
            $this->setBlockStateAndSchedule($position, $updated);
            if ($newLevel === 7) {
                $this->scheduleComposter($position);
            }
            $this->pluginEvents?->composterChanged(
                null,
                $apiPosition,
                $level,
                $newLevel,
                \Bedriox\Api\Processing\ComposterChangeCause::MATURE,
            );
            $events[] = new BlockChanged('server', $position, $updated, $this->players->recipients());
        }

        return $events;
    }

    private function scheduleComposter(BlockPosition $position): void
    {
        $key = 'composter/' . $position->x . ':' . $position->y . ':' . $position->z;
        $this->composterPositions[$key] = $position;
        $this->composterStations->schedule($key, $this->tick + 20);
    }

    private function discoverPendingComposters(): void
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return;
        }
        $loaded = [];
        foreach ($this->blockWorld->loadedChunks() as $chunk) {
            $chunkKey = $chunk->position->key();
            $loaded[$chunkKey] = true;
            if (isset($this->knownComposterChunks[$chunkKey])) {
                continue;
            }
            $this->knownComposterChunks[$chunkKey] = true;
            foreach ($chunk->populatedSections() as $section) {
                $storage = $section->blockStorageLayer(0);
                $pendingPaletteIndexes = [];
                foreach ($storage->palette() as $paletteIndex => $state) {
                    $canonical = $this->blockStateRegistry->state($state);
                    if ($canonical->identifier() === 'minecraft:composter'
                        && ($canonical->properties()['composter_fill_level'] ?? null) === 7) {
                        $pendingPaletteIndexes[$paletteIndex] = true;
                    }
                }
                if ($pendingPaletteIndexes === []) {
                    continue;
                }
                $indices = $storage->paletteIndices();
                for ($offset = 0; $offset < \Bedriox\Server\World\SubChunkBlockStorage::BLOCK_COUNT; ++$offset) {
                    if (!isset($pendingPaletteIndexes[ord($indices[$offset])])) {
                        continue;
                    }
                    $this->scheduleComposter(new BlockPosition(
                        $chunk->position->x * 16 + ($offset & 0x0f),
                        $section->sectionY * 16 + (($offset >> 8) & 0x0f),
                        $chunk->position->z * 16 + (($offset >> 4) & 0x0f),
                    ));
                }
            }
        }
        $this->knownComposterChunks = array_intersect_key($this->knownComposterChunks, $loaded);
    }

    private function synchronizeBrewingStandBottleState(BrewingStandBlockEntity $entity): ?BlockChanged
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return null;
        }
        $current = $this->blockWorld->blockStateAt($entity->position->x, $entity->position->y, $entity->position->z);
        $canonical = $this->blockStateRegistry->state($current);
        if ($canonical->identifier() !== 'minecraft:brewing_stand') {
            return null;
        }
        $properties = $canonical->properties();
        foreach ([
            'brewing_stand_slot_a_bit' => BrewingStandBlockEntity::SLOT_BOTTLE_LEFT,
            'brewing_stand_slot_b_bit' => BrewingStandBlockEntity::SLOT_BOTTLE_MIDDLE,
            'brewing_stand_slot_c_bit' => BrewingStandBlockEntity::SLOT_BOTTLE_RIGHT,
        ] as $property => $slot) {
            $properties[$property] = $entity->inventory->stackAt($slot) === null ? 0 : 1;
        }
        $updated = $this->blockStateRegistry->internalId(CanonicalBlockState::from('minecraft:brewing_stand', $properties));
        if ($updated->value === $current->value) {
            return null;
        }
        $this->setBlockStateAndSchedule($entity->position, $updated);

        return new BlockChanged('server', $entity->position, $updated, $this->players->recipients());
    }

    /** @return list<WorldEvent> */
    private function advanceProjectiles(): array
    {
        if ($this->projectiles->all() !== [] || $this->areaEffectClouds->all() !== []) {
            $this->projectileEntitiesDirty = true;
        }
        $events = [];
        $projector = new PotionEffectProjector();
        $projectileTick = $this->projectiles->tick();
        foreach ($projectileTick->expired as $projectile) {
            unset($this->projectilePublishedMotions[$projectile->runtimeEntityId]);
            $events[] = new ProjectileRemoved($projectile->runtimeEntityId, $this->players->recipients());
        }
        foreach ($projectileTick->updated as $projectile) {
            $shooter = $this->projectileShooter($projectile);
            if ($projectile->state === ProjectileState::RETURNING) {
                $owner = $shooter instanceof Player ? $shooter : null;
                if ($owner === null || !$owner->vitals->isAlive()) {
                    $projectile = $projectile->withMotion(new EntityMotion(0.0, 0.0, 0.0));
                    $this->projectiles->replace($projectile);
                    $events[] = new ProjectileMoved(
                        $projectile,
                        $this->players->recipients(),
                        $this->projectileMotionChanged($projectile),
                    );
                    continue;
                }
                $target = new Position(
                    $owner->movement->position->x,
                    $owner->movement->position->y + 1.0,
                    $owner->movement->position->z,
                );
                if ($projectile->position->distanceTo($target) <= 1.5 && $projectile->carriedItem !== null) {
                    $remainder = $owner->inventory->add($projectile->carriedItem);
                    $returned = $remainder === null;
                    if ($remainder !== null && $this->itemEntities->canSpawn()) {
                        $dropped = $this->itemEntities->spawn(
                            $remainder,
                            $owner->movement->position,
                            new ItemEntityMotion(0.0, 0.1, 0.0),
                        );
                        $events[] = new ItemEntitySpawned($dropped, $this->players->recipients());
                        $returned = true;
                    }
                    if ($returned) {
                        $this->projectiles->remove($projectile->runtimeEntityId);
                        unset($this->projectilePublishedMotions[$projectile->runtimeEntityId]);
                        $owner->markDirty();
                        $events[] = new ItemEntityPickedUp(
                            $projectile->runtimeEntityId,
                            $owner->runtimeActorId,
                            $projectile->carriedItem,
                            $owner->sessionId,
                            true,
                            $owner->inventory->slots(),
                            $this->players->recipients(),
                        );
                        $events[] = new ProjectileRemoved(
                            $projectile->runtimeEntityId,
                            $this->players->recipients(),
                        );
                    }
                    continue;
                }
                $projectile = $projectile->returnToward($target);
                $this->projectiles->replace($projectile);
                $events[] = new ProjectileMoved(
                    $projectile,
                    $this->players->recipients(),
                    $this->projectileMotionChanged($projectile),
                );
                continue;
            }
            if ($projectile->state === ProjectileState::EMBEDDED) {
                $support = $projectile->embeddedBlock === null || $this->blockWorld === null
                    ? null
                    : $this->blockWorld->loadedBlockStateAt(
                        $projectile->embeddedBlock->x,
                        $projectile->embeddedBlock->y,
                        $projectile->embeddedBlock->z,
                    );
                if ($support !== null && $this->blockPalette !== null
                    && $support->value === $this->blockPalette->air->value) {
                    $projectile = $projectile->dislodge();
                    $this->projectiles->replace($projectile);
                    $this->projectilePublishedMotions[$projectile->runtimeEntityId] = $projectile->motion;
                    $events[] = new ProjectileMoved(
                        $projectile,
                        $this->players->recipients(),
                        motionChanged: true,
                    );
                    continue;
                }
                if ($projectile->embeddedTicks < 10
                    || ($projectile->type === ProjectileType::ARROW
                        && $projectile->pickupMode === ArrowPickupMode::NONE)
                    || ($projectile->type === ProjectileType::TRIDENT
                        && (!$projectile->pickupAllowed || $projectile->carriedItem === null))) {
                    continue;
                }
                foreach ($this->players->players() as $candidate) {
                    if (!$candidate->vitals->isAlive() || $candidate->gameMode() === GameMode::SPECTATOR
                        || $candidate->movement->position->distanceTo($projectile->position) > 1.5
                        || ($projectile->type === ProjectileType::ARROW
                            && $projectile->pickupMode === ArrowPickupMode::CREATIVE_ONLY
                            && $candidate->gameMode() !== GameMode::CREATIVE)) {
                        continue;
                    }
                    $stack = $projectile->type === ProjectileType::TRIDENT
                        ? $projectile->carriedItem
                        : new InventoryStack(
                            'minecraft:arrow',
                            1,
                            1,
                            auxValue: $projectile->tippedArrow ? $projectile->potionType->value : 0,
                        );
                    $allowedCount = 1;
                    if ($this->pluginEvents !== null) {
                        $acceptedCount = $this->pluginEvents->pickupItem($candidate, $stack);
                        if ($acceptedCount === null) {
                            continue;
                        }
                        $allowedCount = $acceptedCount;
                    }
                    if ($allowedCount < 1 || $candidate->inventory->add($stack) !== null) {
                        continue;
                    }
                    $this->projectiles->remove($projectile->runtimeEntityId);
                    unset($this->projectilePublishedMotions[$projectile->runtimeEntityId]);
                    $candidate->markDirty();
                    $this->pluginEvents?->pickedUpItem($candidate, $stack);
                    $events[] = new ItemEntityPickedUp(
                        $projectile->runtimeEntityId,
                        $candidate->runtimeActorId,
                        $stack,
                        $candidate->sessionId,
                        true,
                        $candidate->inventory->slots(),
                        $this->players->recipients(),
                    );
                    $events[] = new ProjectileRemoved(
                        $projectile->runtimeEntityId,
                        $this->players->recipients(),
                    );
                    break;
                }
                continue;
            }
            if ($projectile->type === ProjectileType::FISHING_HOOK) {
                $owner = $shooter instanceof Player ? $shooter : null;
                if ($owner === null || !$owner->vitals->isAlive()
                    || $owner->movement->position->distanceTo($projectile->position) > 32.0
                    || $owner->inventory->selectedStack()?->identifier !== 'minecraft:fishing_rod') {
                    $this->projectiles->remove($projectile->runtimeEntityId);
                    $events[] = new ProjectileRemoved($projectile->runtimeEntityId, $this->players->recipients());
                    continue;
                }
                if ($this->positionContainsWater($projectile->position)) {
                    $surface = floor($projectile->position->y) + 0.9;
                    $projectile = $projectile->fishingBobbing
                        ? $projectile->bobAt($surface)
                        : $projectile->beginBobbing(new Position(
                            $projectile->position->x,
                            $surface,
                            $projectile->position->z,
                        ));
                    $this->projectiles->replace($projectile);
                    $events[] = new ProjectileMoved(
                        $projectile,
                        $this->players->recipients(),
                        $this->projectileMotionChanged($projectile),
                    );
                    continue;
                }
            }
            $previousPosition = new Position(
                $projectile->position->x - $projectile->motion->x,
                $projectile->position->y - $projectile->motion->y,
                $projectile->position->z - $projectile->motion->z,
            );
            $hitFraction = INF;
            $direct = null;
            foreach ($this->players->players() as $candidate) {
                if (!$candidate->vitals->isAlive()
                    || in_array('player:' . $candidate->identity->uuid, $projectile->hitActorKeys, true)
                    || ($projectile->ageTicks < 5 && $projectile->ownedByPlayer(
                        $candidate->identity->uuid,
                        $candidate->runtimeActorId,
                    ))) {
                    continue;
                }
                $candidatePosition = $candidate->movement->position;
                $fraction = ProjectileCollisionMath::segmentAabbEntryFraction($previousPosition, $projectile->position, new AxisAlignedBox(
                    $candidatePosition->x - 0.425,
                    $candidatePosition->y - 0.125,
                    $candidatePosition->z - 0.425,
                    $candidatePosition->x + 0.425,
                    $candidatePosition->y + 1.925,
                    $candidatePosition->z + 0.425,
                ));
                if ($fraction !== null && $fraction < $hitFraction) {
                    $direct = $candidate;
                    $hitFraction = $fraction;
                }
            }
            $directEntity = null;
            $midpoint = new Position(
                ($previousPosition->x + $projectile->position->x) / 2.0,
                ($previousPosition->y + $projectile->position->y) / 2.0,
                ($previousPosition->z + $projectile->position->z) / 2.0,
            );
            $segmentLength = $previousPosition->distanceTo($projectile->position);
            foreach ($this->entityRuntime->registry()->nearby(
                $this->worldId,
                $midpoint,
                ($segmentLength / 2.0) + 2.0,
                32,
            ) as $candidate) {
                if (!$candidate instanceof AbstractLivingEntity || !$candidate->isAlive()
                    || in_array('entity:' . $candidate->getRuntimeId(), $projectile->hitActorKeys, true)
                    || ($projectile->ageTicks < 5 && $projectile->ownedByEntity(
                        $candidate->getUniqueId(),
                        $candidate->getRuntimeId(),
                    ))) {
                    continue;
                }
                $position = $candidate->getPosition();
                $halfWidth = $candidate->collisionWidth() / 2.0;
                $height = $candidate->collisionHeight();
                $fraction = ProjectileCollisionMath::segmentAabbEntryFraction($previousPosition, $projectile->position, new AxisAlignedBox(
                    $position->x - $halfWidth - 0.125,
                    $position->y - 0.125,
                    $position->z - $halfWidth - 0.125,
                    $position->x + $halfWidth + 0.125,
                    $position->y + $height + 0.125,
                    $position->z + $halfWidth + 0.125,
                ));
                if ($fraction !== null && $fraction < $hitFraction) {
                    $direct = null;
                    $directEntity = $candidate;
                    $hitFraction = $fraction;
                }
            }
            $radius = 0.125;
            $blockHit = false;
            $impactCollisionBox = null;
            if ($this->blockCollisions !== null) {
                $swept = new AxisAlignedBox(
                    $previousPosition->x - $radius,
                    $previousPosition->y - $radius,
                    $previousPosition->z - $radius,
                    $previousPosition->x + $radius,
                    $previousPosition->y + $radius,
                    $previousPosition->z + $radius,
                )->swept(
                    $projectile->position->x - $previousPosition->x,
                    $projectile->position->y - $previousPosition->y,
                    $projectile->position->z - $previousPosition->z,
                );
                foreach ($this->blockCollisions->boxesIntersecting($swept) as $box) {
                    $fraction = ProjectileCollisionMath::segmentAabbEntryFraction(
                        $previousPosition,
                        $projectile->position,
                        $box->expanded($radius, $radius, $radius),
                    );
                    if ($fraction !== null && $fraction < $hitFraction) {
                        $direct = null;
                        $directEntity = null;
                        $blockHit = true;
                        $hitFraction = $fraction;
                        $impactCollisionBox = $box;
                    }
                }
            }
            if ($direct === null && $directEntity === null && !$blockHit) {
                $events[] = new ProjectileMoved(
                    $projectile,
                    $this->players->recipients(),
                    $this->projectileMotionChanged($projectile),
                );
                continue;
            }
            $projectile = $projectile->atPosition(new Position(
                $previousPosition->x + (($projectile->position->x - $previousPosition->x) * $hitFraction),
                $previousPosition->y + (($projectile->position->y - $previousPosition->y) * $hitFraction),
                $previousPosition->z + (($projectile->position->z - $previousPosition->z) * $hitFraction),
            ));
            $impactBlock = null;
            $impactFace = null;
            if ($blockHit) {
                if (!$impactCollisionBox instanceof AxisAlignedBox) {
                    throw new \LogicException('Projectile block collision lost its authoritative collision box.');
                }
                $length = max(0.000_001, hypot(
                    hypot($projectile->motion->x, $projectile->motion->z),
                    $projectile->motion->y,
                ));
                $impactBlock = new BlockPosition(
                    (int) floor(($impactCollisionBox->minX + $impactCollisionBox->maxX) / 2.0),
                    (int) floor(($impactCollisionBox->minY + $impactCollisionBox->maxY) / 2.0),
                    (int) floor(($impactCollisionBox->minZ + $impactCollisionBox->maxZ) / 2.0),
                );
                $impactFace = self::projectileImpactFaceAt(
                    $projectile->position,
                    $impactCollisionBox->expanded($radius, $radius, $radius),
                    $projectile->motion,
                );
            }
            if ($this->pluginEvents !== null && !$this->pluginEvents->projectileImpact(
                $projectile,
                $shooter,
                $direct,
                $directEntity,
                $impactBlock,
            )) {
                $this->projectiles->remove($projectile->runtimeEntityId);
                $events[] = new ProjectileRemoved($projectile->runtimeEntityId, $this->players->recipients());
                continue;
            }
            $apiShooter = $shooter instanceof Player
                ? $this->pluginEvents?->playerView($shooter)
                : $shooter;
            if ($projectile->type === ProjectileType::TRIDENT) {
                $baseDamage = 8.0;
                if ($direct !== null) {
                    $wetTarget = $this->playerIsSubmerged($direct)
                        || $this->blockWorld?->weather()->weather->isRaining() === true;
                    $damage = !$this->pvp && $shooter instanceof Player
                        ? new CommandRejected($direct->sessionId, 'pvp_disabled')
                        : $this->damage(new DamagePlayer(
                            $direct->sessionId,
                            $baseDamage + ($wetTarget ? $projectile->damageBonus : 0.0),
                            DamageCause::Projectile,
                            $shooter instanceof Player ? $shooter->sessionId : null,
                        ), $shooter instanceof AbstractLivingEntity ? $shooter : null);
                    if ($damage instanceof PlayerDamaged) {
                        $events[] = $damage;
                        $horizontal = hypot($projectile->motion->x, $projectile->motion->z);
                        if ($horizontal > 0.000_001 && $direct->vitals->isAlive()) {
                            [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
                                $direct->movement->velocityX,
                                $direct->movement->verticalVelocity,
                                $direct->movement->velocityZ,
                                $projectile->motion->x / $horizontal,
                                $projectile->motion->z / $horizontal,
                                $projectile->knockbackStrength,
                                CombatRules::KNOCKBACK_FORCE,
                                $direct->movement->verticalState === VerticalState::GROUNDED,
                                $direct->inventory->knockbackResistance(),
                            );
                            $motion = new ApiKnockbackVector($motionX, $motionY, $motionZ);
                            if ($this->pluginEvents !== null) {
                                $motion = $this->pluginEvents->knockback(
                                    $this->pluginEvents->playerView($direct),
                                    $apiShooter,
                                    ApiKnockbackCause::PROJECTILE,
                                    $motion,
                                );
                            }
                            if ($motion !== null) {
                                $direct->movement->velocityX = $motion->x;
                                $direct->movement->verticalVelocity = $motion->y;
                                $direct->movement->velocityZ = $motion->z;
                                $direct->movement->verticalState = VerticalState::AIRBORNE;
                                $direct->markDirty();
                                $this->pluginEvents?->knockedBack(
                                    $this->pluginEvents->playerView($direct),
                                    $apiShooter,
                                    ApiKnockbackCause::PROJECTILE,
                                    $motion,
                                );
                                $events[] = new PlayerKnockedBack(
                                    $direct->sessionId,
                                    $direct->snapshot(),
                                    $motion->x,
                                    $motion->y,
                                    $motion->z,
                                    $direct->movement->clientTick,
                                    $this->players->recipients(),
                                );
                            }
                        }
                    }
                } elseif ($directEntity !== null) {
                    $entityPosition = $directEntity->getPosition();
                    $wetTarget = $this->positionContainsWater(new Position(
                        $entityPosition->x,
                        $entityPosition->y,
                        $entityPosition->z,
                    ))
                        || $this->blockWorld?->weather()->weather->isRaining() === true;
                    $proposedDamage = $baseDamage + ($wetTarget ? $projectile->damageBonus : 0.0);
                    $damageEvent = $this->pluginEvents?->entityDamage(
                        $directEntity,
                        ApiEntityDamageCause::PROJECTILE,
                        $proposedDamage,
                    );
                    $actualDamage = $damageEvent?->damage() ?? ($this->pluginEvents === null ? $proposedDamage : 0.0);
                    if ($actualDamage > 0.0) {
                        $this->entityRuntime->damage($directEntity->getRuntimeId(), $actualDamage);
                        $horizontal = hypot($projectile->motion->x, $projectile->motion->z);
                        if ($horizontal > 0.000_001 && $directEntity->isAlive()) {
                            $entityMotion = $directEntity->getMotion();
                            [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
                                $entityMotion->x,
                                $entityMotion->y,
                                $entityMotion->z,
                                $projectile->motion->x / $horizontal,
                                $projectile->motion->z / $horizontal,
                                $projectile->knockbackStrength,
                                CombatRules::KNOCKBACK_FORCE,
                                $directEntity->isOnGround(),
                            );
                            $motion = new ApiKnockbackVector($motionX, $motionY, $motionZ);
                            if ($this->pluginEvents !== null) {
                                $motion = $this->pluginEvents->knockback(
                                    $directEntity,
                                    $apiShooter,
                                    ApiKnockbackCause::PROJECTILE,
                                    $motion,
                                );
                            }
                            if ($motion !== null) {
                                $directEntity->setMotion(new EntityMotion($motion->x, $motion->y, $motion->z));
                                $this->pluginEvents?->knockedBack(
                                    $directEntity,
                                    $apiShooter,
                                    ApiKnockbackCause::PROJECTILE,
                                    $motion,
                                );
                            }
                        }
                        $events[] = new EntityActorDamaged(
                            $directEntity,
                            $this->tick,
                            $this->players->recipients(),
                        );
                    }
                }
                if ($projectile->carriedItem !== null && $projectile->pickupAllowed) {
                    if ($projectile->loyaltyLevel > 0) {
                        $projectile = $projectile->beginReturning();
                    } elseif ($blockHit) {
                        $projectile = $projectile->embeddedAt(
                            $projectile->position,
                            $impactBlock,
                            $impactFace,
                        );
                    } else {
                        array_push($events, ...$this->settleThrownTrident($projectile));
                    }
                }
            } elseif ($projectile->type === ProjectileType::ARROW) {
                $arrowDamage = max(1.0, round(hypot(
                    hypot($projectile->motion->x, $projectile->motion->z),
                    $projectile->motion->y,
                ) * 2.0) + $projectile->damageBonus);
                if ($direct !== null) {
                    $damage = !$this->pvp && $shooter instanceof Player
                        ? new CommandRejected($direct->sessionId, 'pvp_disabled')
                        : $this->damage(new DamagePlayer(
                            $direct->sessionId,
                            $arrowDamage,
                            DamageCause::Projectile,
                            $shooter instanceof Player ? $shooter->sessionId : null,
                        ), $shooter instanceof AbstractLivingEntity ? $shooter : null);
                    if ($damage instanceof PlayerDamaged) {
                        $events[] = $damage;
                    }
                    $horizontal = hypot($projectile->motion->x, $projectile->motion->z);
                    if ($damage instanceof PlayerDamaged
                        && $horizontal > 0.000_001 && $direct->vitals->isAlive()) {
                        [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
                            $direct->movement->velocityX,
                            $direct->movement->verticalVelocity,
                            $direct->movement->velocityZ,
                            $projectile->motion->x / $horizontal,
                            $projectile->motion->z / $horizontal,
                            $projectile->knockbackStrength,
                            CombatRules::KNOCKBACK_FORCE,
                            $direct->movement->verticalState === VerticalState::GROUNDED,
                            $direct->inventory->knockbackResistance(),
                        );
                        $motion = new ApiKnockbackVector($motionX, $motionY, $motionZ);
                        if ($this->pluginEvents !== null) {
                            $motion = $this->pluginEvents->knockback(
                                $this->pluginEvents->playerView($direct),
                                $apiShooter,
                                ApiKnockbackCause::PROJECTILE,
                                $motion,
                            );
                        }
                        if ($motion !== null) {
                            $direct->movement->velocityX = $motion->x;
                            $direct->movement->verticalVelocity = $motion->y;
                            $direct->movement->velocityZ = $motion->z;
                            $direct->movement->verticalState = VerticalState::AIRBORNE;
                            $direct->markDirty();
                            $this->pluginEvents?->knockedBack(
                                $this->pluginEvents->playerView($direct),
                                $apiShooter,
                                ApiKnockbackCause::PROJECTILE,
                                $motion,
                            );
                            $events[] = new PlayerKnockedBack(
                                $direct->sessionId,
                                $direct->snapshot(),
                                $motion->x,
                                $motion->y,
                                $motion->z,
                                $direct->movement->clientTick,
                                $this->players->recipients(),
                            );
                        }
                    }
                    if ($damage instanceof PlayerDamaged
                        && $projectile->fireTicks > 0 && $direct->vitals->isAlive()) {
                        $direct->vitals->fireTicks = max(
                            $direct->vitals->fireTicks,
                            $this->fireProtectionAdjustedTicks($direct, $projectile->fireTicks),
                        );
                        $direct->markDirty();
                        $events[] = new PlayerEnvironmentChanged(
                            $direct->snapshot(),
                            $this->players->recipients(),
                            $this->tick,
                            false,
                            true,
                        );
                    }
                    foreach ($damage instanceof PlayerDamaged && $projectile->tippedArrow
                        ? $projector->tippedArrow($projectile->potionType)
                        : [] as $dose) {
                        $effect = $this->applyPotionDoseToPlayer(
                            $direct,
                            $dose,
                            EffectCause::TIPPED_ARROW,
                        );
                        if ($effect !== null) {
                            $events[] = $effect;
                        }
                    }
                } elseif ($directEntity !== null) {
                    $damageEvent = $this->pluginEvents?->entityDamage(
                        $directEntity,
                        ApiEntityDamageCause::PROJECTILE,
                        $arrowDamage,
                        $apiShooter,
                    );
                    $actualDamage = $damageEvent?->damage() ?? ($this->pluginEvents === null ? $arrowDamage : 0.0);
                    if ($actualDamage > 0.0) {
                        $this->entityRuntime->damage($directEntity->getRuntimeId(), $actualDamage);
                    }
                    $horizontal = hypot($projectile->motion->x, $projectile->motion->z);
                    if ($actualDamage > 0.0 && $horizontal > 0.000_001) {
                        $motion = $directEntity->getMotion();
                        [$motionX, $motionY, $motionZ] = $this->resolveKnockback(
                            $motion->x,
                            $motion->y,
                            $motion->z,
                            $projectile->motion->x / $horizontal,
                            $projectile->motion->z / $horizontal,
                            $projectile->knockbackStrength,
                            CombatRules::KNOCKBACK_FORCE,
                            $directEntity->isOnGround(),
                        );
                        $knockback = new ApiKnockbackVector($motionX, $motionY, $motionZ);
                        if ($this->pluginEvents !== null) {
                            $knockback = $this->pluginEvents->knockback(
                                $directEntity,
                                $apiShooter,
                                ApiKnockbackCause::PROJECTILE,
                                $knockback,
                            );
                        }
                        if ($knockback !== null) {
                            $directEntity->setMotion(new EntityMotion($knockback->x, $knockback->y, $knockback->z));
                            $this->pluginEvents?->knockedBack(
                                $directEntity,
                                $apiShooter,
                                ApiKnockbackCause::PROJECTILE,
                                $knockback,
                            );
                        }
                    }
                    if ($actualDamage > 0.0 && $projectile->fireTicks > 0 && $directEntity->isAlive()) {
                        $combust = $this->pluginEvents?->combust(
                            $directEntity,
                            EntityCombustionCause::ENCHANTMENT,
                            $projectile->fireTicks,
                        );
                        if ($this->pluginEvents === null || $combust !== null) {
                            $directEntity->setOnFire($combust?->durationTicks() ?? $projectile->fireTicks);
                        }
                    }
                    if ($actualDamage > 0.0) {
                        $events[] = new EntityActorDamaged(
                            $directEntity,
                            $this->tick,
                            $this->players->recipients(),
                        );
                        foreach ($projectile->tippedArrow
                            ? $projector->tippedArrow($projectile->potionType)
                            : [] as $dose) {
                            $this->applyPotionDoseToEntity($directEntity, $dose, EffectCause::TIPPED_ARROW);
                        }
                    }
                } elseif ($blockHit) {
                    $projectile = $projectile->embeddedAt(
                        self::projectileEmbeddedPosition(
                            $projectile->position,
                            $impactCollisionBox,
                            $impactFace,
                        ),
                        $impactBlock,
                        $impactFace,
                    );
                }
            } elseif ($projectile->type === ProjectileType::SPLASH_POTION) {
                foreach ($this->players->players() as $candidate) {
                    if (!$candidate->vitals->isAlive()) {
                        continue;
                    }
                    $eyePosition = new Position(
                        $candidate->movement->position->x,
                        $candidate->movement->position->y + 1.62,
                        $candidate->movement->position->z,
                    );
                    $distance = $eyePosition->distanceTo($projectile->position);
                    foreach ($projector->splash($projectile->potionType, $distance, $candidate === $direct) as $dose) {
                        $effect = $this->applyPotionDoseToPlayer(
                            $candidate,
                            $dose,
                            EffectCause::SPLASH_POTION,
                        );
                        if ($effect !== null) {
                            $events[] = $effect;
                        }
                    }
                }
                foreach ($this->entityRuntime->registry()->nearby(
                    $this->worldId,
                    $projectile->position,
                    4.125,
                    64,
                ) as $candidate) {
                    if (!$candidate instanceof AbstractLivingEntity || !$candidate->isAlive()) {
                        continue;
                    }
                    $position = $candidate->getPosition();
                    $distance = hypot(
                        hypot($position->x - $projectile->position->x, $position->z - $projectile->position->z),
                        ($position->y + ($candidate->collisionHeight() * 0.85))
                            - $projectile->position->y,
                    );
                    foreach ($projector->splash(
                        $projectile->potionType,
                        $distance,
                        $candidate === $directEntity,
                    ) as $dose) {
                        $this->applyPotionDoseToEntity($candidate, $dose, EffectCause::SPLASH_POTION);
                    }
                }
            }
            if ($projectile->type === ProjectileType::LINGERING_POTION) {
                $cloud = $this->areaEffectClouds->spawn(
                    $projectile->ownerUuid,
                    $projectile->potionType,
                    $projectile->position,
                );
                $events[] = new AreaEffectCloudSpawned($cloud, $this->players->recipients());
            }
            if ($projectile->type->isPotion()) {
                $events[] = new PotionSplashImpacted(
                    $projectile->position,
                    $projectile->potionType,
                    $this->players->recipients(),
                );
            }
            $this->pluginEvents?->projectileImpacted(
                $projectile,
                $shooter,
                $direct,
                $directEntity,
                $impactBlock,
            );
            if ($projectile->state === ProjectileState::RETURNING) {
                $this->projectiles->replace($projectile);
                $this->projectilePublishedMotions[$projectile->runtimeEntityId] = $projectile->motion;
                $events[] = new ProjectileMoved(
                    $projectile,
                    $this->players->recipients(),
                    motionChanged: true,
                );
                continue;
            }
            if ($projectile->state === ProjectileState::EMBEDDED) {
                $this->projectiles->replace($projectile);
                $this->projectilePublishedMotions[$projectile->runtimeEntityId] = $projectile->motion;
                $events[] = new ProjectileMoved(
                    $projectile,
                    $this->players->recipients(),
                    motionChanged: true,
                    embedded: true,
                );
                continue;
            }
            $piercedActorKey = $direct !== null
                ? 'player:' . $direct->identity->uuid
                : ($directEntity !== null ? 'entity:' . $directEntity->getRuntimeId() : null);
            if ($projectile->type === ProjectileType::ARROW
                && $piercedActorKey !== null && $projectile->piercingRemaining > 0) {
                $projectile = $projectile->afterPiercing($piercedActorKey)->atPosition(new Position(
                    $projectile->position->x + ($projectile->motion->x * 0.01),
                    $projectile->position->y + ($projectile->motion->y * 0.01),
                    $projectile->position->z + ($projectile->motion->z * 0.01),
                ));
                $this->projectiles->replace($projectile);
                $events[] = new ProjectileMoved(
                    $projectile,
                    $this->players->recipients(),
                    $this->projectileMotionChanged($projectile),
                );
                continue;
            }
            $this->projectiles->remove($projectile->runtimeEntityId);
            unset($this->projectilePublishedMotions[$projectile->runtimeEntityId]);
            $events[] = new ProjectileRemoved($projectile->runtimeEntityId, $this->players->recipients());
        }
        $cloudsBefore = $this->areaEffectClouds->all();
        $updatedClouds = $this->areaEffectClouds->tick();
        $activeCloudIds = [];
        foreach ($updatedClouds as $cloud) {
            $activeCloudIds[$cloud->runtimeEntityId] = true;
            $events[] = new AreaEffectCloudUpdated($cloud, $this->players->recipients());
            if (!$cloud->shouldApply()) {
                continue;
            }
            $cloudRemoved = false;
            foreach ($this->players->players() as $candidate) {
                if (!$candidate->vitals->isAlive() || !$cloud->canAffect($candidate->identity->uuid)
                    || hypot(
                        $candidate->movement->position->x - $cloud->position->x,
                        $candidate->movement->position->z - $cloud->position->z,
                    ) > $cloud->radius
                    || abs($candidate->movement->position->y - $cloud->position->y) > 2.0) {
                    continue;
                }
                foreach ($projector->lingering($cloud->potionType) as $dose) {
                    $effect = $this->applyPotionDoseToPlayer(
                        $candidate,
                        $dose,
                        EffectCause::LINGERING_POTION,
                    );
                    if ($effect !== null) {
                        $events[] = $effect;
                    }
                }
                if ($this->areaEffectClouds->affected($cloud->runtimeEntityId, $candidate->identity->uuid) === null) {
                    $events[] = new AreaEffectCloudRemoved(
                        $cloud->runtimeEntityId,
                        $this->players->recipients(),
                    );
                    $cloudRemoved = true;
                    break;
                }
            }
            if ($cloudRemoved) {
                continue;
            }
            foreach ($this->entityRuntime->registry()->nearby(
                $this->worldId,
                $cloud->position,
                min(4.0, $cloud->radius + 0.25),
                64,
            ) as $candidate) {
                if (!$candidate instanceof AbstractLivingEntity || !$candidate->isAlive()) {
                    continue;
                }
                $victimId = 'entity:' . $candidate->getUniqueId();
                if (!$cloud->canAffect($victimId)) {
                    continue;
                }
                $position = $candidate->getPosition();
                if (hypot($position->x - $cloud->position->x, $position->z - $cloud->position->z) > $cloud->radius
                    || abs($position->y - $cloud->position->y) > 2.0) {
                    continue;
                }
                foreach ($projector->lingering($cloud->potionType) as $dose) {
                    $this->applyPotionDoseToEntity($candidate, $dose, EffectCause::LINGERING_POTION);
                }
                if ($this->areaEffectClouds->affected($cloud->runtimeEntityId, $victimId) === null) {
                    $events[] = new AreaEffectCloudRemoved(
                        $cloud->runtimeEntityId,
                        $this->players->recipients(),
                    );
                    break;
                }
            }
        }
        foreach ($cloudsBefore as $cloud) {
            if (!isset($activeCloudIds[$cloud->runtimeEntityId])) {
                $events[] = new AreaEffectCloudRemoved($cloud->runtimeEntityId, $this->players->recipients());
            }
        }

        if ($this->tick % 20 === 0) {
            $this->persistProjectileEntities();
        }

        return $events;
    }

    private function projectileShooter(
        Projectile $projectile,
    ): Player|AbstractLivingEntity|null {
        if ($projectile->ownerType === ProjectileOwnerType::PLAYER) {
            $player = $this->players->playerByIdentity($projectile->ownerUuid);

            return $player !== null && $projectile->ownedByPlayer(
                $player->identity->uuid,
                $player->runtimeActorId,
            ) ? $player : null;
        }
        $entity = $this->entityRuntime->registry()->getByUniqueId($projectile->ownerUuid);

        return $entity instanceof AbstractLivingEntity
            && $projectile->ownedByEntity($entity->getUniqueId(), $entity->getRuntimeId())
            ? $entity
            : null;
    }

    /** @return list<WorldEvent> */
    private function settleThrownTrident(Projectile $projectile): array
    {
        $item = $projectile->carriedItem;
        if ($item === null) {
            return [];
        }
        if ($projectile->loyaltyLevel > 0) {
            $shooter = $this->projectileShooter($projectile);
            $owner = $shooter instanceof Player ? $shooter : null;
            if ($owner !== null && $owner->vitals->isAlive()) {
                $before = clone $owner->inventory;
                $overflow = $owner->inventory->add($item);
                $owner->markDirty();
                $events = [];
                foreach (self::changedMainInventorySlots($before, $owner->inventory) as $reference) {
                    $events[] = new InventorySlotChanged(
                        $owner->sessionId,
                        $reference->slot,
                        $owner->inventory->stackAt($reference->slot),
                    );
                }
                if ($overflow === null) {
                    return $events;
                }
                $item = $overflow;
            }
        }
        if (!$this->itemEntities->canSpawn()) {
            return [];
        }
        $entity = $this->itemEntities->spawn(
            $item,
            $projectile->position,
            new ItemEntityMotion(0.0, 0.1, 0.0),
            10,
        );

        return [new ItemEntitySpawned($entity, $this->players->recipients())];
    }

    private function positionContainsWater(Position $position): bool
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return false;
        }
        $state = $this->blockWorld->blockStateAt(
            (int) floor($position->x),
            (int) floor($position->y),
            (int) floor($position->z),
        );

        return in_array($this->blockStateRegistry->state($state)->identifier(), [
            'minecraft:water',
            'minecraft:flowing_water',
        ], true);
    }

    private function persistProjectileEntities(): void
    {
        if (!$this->projectileEntitiesDirty || $this->projectileEntityStore === null) {
            return;
        }
        $projectiles = $this->projectiles->all();
        $clouds = $this->areaEffectClouds->all();
        $payload = $projectiles === [] && $clouds === []
            ? null
            : $this->projectileEntityCodec->encode($this->worldId, $projectiles, $clouds);
        $this->projectileEntityStore->saveTransientEntities('projectiles', $payload);
        $this->projectileEntitiesDirty = false;
    }

    private function applyPotionDoseToEntity(
        AbstractLivingEntity $entity,
        PotionEffectDose $dose,
        EffectCause $cause,
    ): void {
        $this->applyEffectToEntity($entity, $dose->effect, $cause, $dose->intensity, false);
    }

    private function applyPotionDoseToPlayer(
        Player $player,
        PotionEffectDose $dose,
        EffectCause $cause,
    ): ?WorldEvent {
        $effect = $dose->effect;
        if ($effect->type === EffectType::INSTANT_DAMAGE) {
            return $this->damage(new DamagePlayer(
                $player->sessionId,
                (float) (6 << min(20, $effect->amplifier)) * $dose->intensity,
                DamageCause::Magic,
            ));
        }
        if ($effect->type === EffectType::INSTANT_HEALTH) {
            $maximumHealth = VanillaEffectBehavior::maximumHealth($player->effects->snapshot());
            $amount = min(
                (float) (4 << min(20, $effect->amplifier)) * $dose->intensity,
                $maximumHealth - $player->vitals->health,
            );
            $amount = $this->pluginEvents?->regainHealth($player, ApiHealthRegainCause::EFFECT, $amount)
                ?? ($this->pluginEvents === null ? $amount : 0.0);
            if ($amount <= 0.0) {
                return null;
            }
            $amount = min($amount, $maximumHealth - $player->vitals->health);
            $player->vitals->health += $amount;
            $player->markDirty();
            $this->pluginEvents?->regainedHealth($player, ApiHealthRegainCause::EFFECT, $amount);

            return new PlayerHealed(
                $player->snapshot(),
                $amount,
                HealthRegainCause::EFFECT,
                $this->players->recipients(),
            );
        }
        if ($effect->type === EffectType::SATURATION) {
            $player->vitals->addNutrition($effect->level(), 2.0 * $effect->level());
            $player->markDirty();
            return null;
        }

        return $this->addPlayerEffect(new AddPlayerEffect(
            $player->sessionId,
            $effect,
            $cause,
            $dose->intensity,
        ));
    }

    private function applyEffectToEntity(
        AbstractLivingEntity $entity,
        EffectInstance $requested,
        EffectCause $cause,
        float $intensity = 1.0,
        bool $instantLifecycle = true,
    ): void {
        if (!$entity->isAlive()) {
            return;
        }
        $effect = $requested;
        $type = $effect->type;
        if ($entity instanceof Undead) {
            $type = match ($type) {
                EffectType::INSTANT_HEALTH => EffectType::INSTANT_DAMAGE,
                EffectType::INSTANT_DAMAGE => EffectType::INSTANT_HEALTH,
                default => $type,
            };
        }
        if ($type !== $requested->type) {
            $effect = new EffectInstance(
                $type,
                $effect->durationTicks,
                $effect->amplifier,
                $effect->visible,
                $effect->ambient,
                $effect->infinite,
            );
        }
        $instant = $effect->type === EffectType::INSTANT_HEALTH
            || $effect->type === EffectType::INSTANT_DAMAGE
            || $effect->type === EffectType::SATURATION;
        if (!$instant || $instantLifecycle) {
            $effect = $this->pluginEvents?->addEffect($entity, $effect, $cause)
                ?? ($this->pluginEvents === null ? $effect : null);
            if ($effect === null) {
                return;
            }
        }
        if ($effect->type === EffectType::INSTANT_HEALTH) {
            $healed = $entity->heal((float) (4 << min(20, $effect->amplifier)) * $intensity);
            if ($instantLifecycle) {
                $this->pluginEvents?->effectAdded($entity, $effect, $cause, null);
            }
            if ($healed > 0.0) {
                $this->deferredEvents[] = new EntityActorHealthChanged(
                    $entity,
                    $this->tick,
                    $this->players->recipients(),
                );
            }
            return;
        }
        if ($effect->type === EffectType::INSTANT_DAMAGE) {
            if ($instantLifecycle) {
                $this->pluginEvents?->effectAdded($entity, $effect, $cause, null);
            }
            $entity->requestControllerDamage(
                (float) (6 << min(20, $effect->amplifier)) * $intensity,
                ApiEntityDamageCause::MAGIC,
                null,
            );
            return;
        }
        if ($effect->type === EffectType::SATURATION) {
            if ($instantLifecycle) {
                $this->pluginEvents?->effectAdded($entity, $effect, $cause, null);
            }
            return;
        }
        $previous = $entity->effectState()->get($effect->type);
        $entity->addEffect($effect);
        $transition = $entity->effectState()->get($effect->type);
        if ($transition === $previous) {
            return;
        }
        $this->pluginEvents?->effectAdded($entity, $effect, $cause, $previous);
    }

    private function removeEffectFromEntity(
        AbstractLivingEntity $entity,
        EffectType $type,
        EffectCause $cause,
    ): void {
        $effect = $entity->effectState()->get($type);
        if ($effect === null || ($this->pluginEvents !== null
                && !$this->pluginEvents->allowEffectRemoval($entity, $effect, $cause))) {
            return;
        }
        $entity->removeEffect($type);
        $this->pluginEvents?->effectRemoved($entity, $effect, $cause);
    }

    /** @param array<string, EffectInstance> $effects */
    private function triggerDeathEffectConsequences(
        Position|\Bedriox\Api\World\Position $position,
        array $effects,
        string $victimIdentity,
    ): void {
        $simulationPosition = $position instanceof Position
            ? $position
            : new Position($position->x, $position->y, $position->z);
        if (isset($effects[EffectType::WIND_CHARGED->value])) {
            foreach ($this->players->players() as $candidate) {
                if ($candidate->identity->uuid === $victimIdentity || !$candidate->vitals->isAlive()) {
                    continue;
                }
                $dx = $candidate->movement->position->x - $position->x;
                $dz = $candidate->movement->position->z - $position->z;
                $distance = hypot($dx, $dz);
                if ($distance <= 0.000_001 || $distance > 5.0) {
                    continue;
                }
                $force = 1.0 - ($distance / 5.0);
                $candidate->movement->velocityX += ($dx / $distance) * $force;
                $candidate->movement->verticalVelocity = max($candidate->movement->verticalVelocity, 0.35 * $force);
                $candidate->movement->velocityZ += ($dz / $distance) * $force;
                $candidate->movement->verticalState = VerticalState::AIRBORNE;
                $candidate->markDirty();
                $this->deferredEvents[] = new PlayerKnockedBack(
                    $candidate->sessionId,
                    $candidate->snapshot(),
                    $candidate->movement->velocityX,
                    $candidate->movement->verticalVelocity,
                    $candidate->movement->velocityZ,
                    $candidate->movement->clientTick,
                    $this->players->recipients(),
                );
            }
            foreach ($this->entityRuntime->registry()->nearby($this->worldId, $simulationPosition, 5.0, 64) as $entity) {
                if (!$entity instanceof AbstractLivingEntity || 'entity:' . $entity->getUniqueId() === $victimIdentity) {
                    continue;
                }
                $entityPosition = $entity->getPosition();
                $dx = $entityPosition->x - $position->x;
                $dz = $entityPosition->z - $position->z;
                $distance = hypot($dx, $dz);
                if ($distance <= 0.000_001) {
                    continue;
                }
                $force = 1.0 - min(1.0, $distance / 5.0);
                $entity->setMotion(new EntityMotion(
                    ($dx / $distance) * $force,
                    0.35 * $force,
                    ($dz / $distance) * $force,
                ));
                $this->deferredEvents[] = new EntityActorMoved(
                    $entity,
                    $this->tick,
                    $this->players->recipients(),
                    true,
                );
            }
        }
        if (isset($effects[EffectType::WEAVING->value])) {
            $this->spreadDeathCobwebs($position);
        }
        if (isset($effects[EffectType::OOZING->value])) {
            $this->spawnEffectMobs('minecraft:slime', $position, 2);
        }
    }

    private function triggerInfestedSpawn(Position|\Bedriox\Api\World\Position $position): void
    {
        if ($this->dropRandom->integer(1, 10) === 1) {
            $this->spawnEffectMobs('minecraft:silverfish', $position, 1);
        }
    }

    private function spawnEffectMobs(
        string $identifier,
        Position|\Bedriox\Api\World\Position $position,
        int $count,
    ): void {
        for ($index = 0; $index < $count; ++$index) {
            $this->spawnEntity(new EntitySpawnRequest(
                new VanillaEntityIdentifier($identifier),
                SpawnCause::EFFECT,
                $this->worldId,
                new Position($position->x + ($index * 0.25), $position->y, $position->z),
            ));
        }
    }

    private function spreadDeathCobwebs(Position|\Bedriox\Api\World\Position $position): void
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null || $this->blockPalette === null) {
            return;
        }
        try {
            $web = $this->blockStateRegistry->internalId(CanonicalBlockState::from('minecraft:web'));
        } catch (InvalidArgumentException) {
            return;
        }
        $originX = (int) floor($position->x);
        $originY = (int) floor($position->y);
        $originZ = (int) floor($position->z);
        $placed = 0;
        foreach ([[0, 0], [1, 0], [-1, 0], [0, 1], [0, -1]] as [$offsetX, $offsetZ]) {
            if ($placed >= 3) {
                break;
            }
            $x = $originX + $offsetX;
            $z = $originZ + $offsetZ;
            if ($this->blockWorld->blockStateAt($x, $originY, $z)->value !== $this->blockPalette->air->value) {
                continue;
            }
            $block = new BlockPosition($x, $originY, $z);
            $this->setBlockStateAndSchedule($block, $web);
            $this->deferredEvents[] = new BlockChanged('server', $block, $web, $this->players->recipients());
            ++$placed;
        }
    }

    /**
     * @return array{
     *     list<InventoryStack>,
     *     ?string,
     *     ?ApiCraftingRecipe,
     *     ?CraftingGrid,
     *     list<InventoryStack>,
     *     list<RecipeOutput>
     * }
     */
    private function resolveCraftingRequest(Player $player, ApplyInventoryStackRequest $command): array
    {
        if ($command->crafting === null || $this->craftingCatalog === null || $this->itemCatalog === null) {
            return [[], 'crafting_unavailable', null, null, [], []];
        }
        $width = $player->inventory->craftingGridWidth();
        $grid = new CraftingGrid($width, $width, $player->inventory->craftingSlots());
        /** @var array<string, array{InventorySlotReference, int}> $claimed */
        $claimed = [];
        foreach ($command->actions as $action) {
            if ($action->type !== InventoryStackRequestActionType::Consume) {
                continue;
            }
            $key = $action->source->key();
            $current = $claimed[$key] ?? [$action->source, 0];
            $claimed[$key] = [$current[0], $current[1] + $action->count];
        }

        $outputs = [];
        $consumed = [];
        $recipeOutputs = [];
        $apiRecipe = null;
        $complexUuid = $this->craftingCatalog->complexUuid($command->crafting->recipeNetworkId);
        if ($complexUuid !== null) {
            if ($command->crafting->automatic || $this->complexCraftingRecipes === null) {
                return [[], 'complex_recipe_unavailable', null, null, [], []];
            }
            $match = $this->complexCraftingRecipes->match(
                $complexUuid,
                $grid,
                $command->crafting->repetitions,
            );
            if ($match === null) {
                return [[], 'complex_recipe_mismatch', null, null, [], []];
            }
            $claimedBySlot = [];
            foreach ($claimed as [$reference, $count]) {
                if ($reference->container !== InventoryContainer::CraftingInput
                    || isset($claimedBySlot[$reference->slot])) {
                    return [[], 'crafting_consumption_mismatch', null, null, [], []];
                }
                $claimedBySlot[$reference->slot] = $count;
                $stack = $grid->slots[$reference->slot] ?? null;
                if ($stack === null || $stack->count < $count) {
                    return [[], 'crafting_consumption_mismatch', null, null, [], []];
                }
                $consumed[] = $stack->withCountAndNetworkId($count, $stack->stackNetworkId);
            }
            ksort($claimedBySlot);
            if ($claimedBySlot !== $match->consumptionBySlot) {
                return [[], 'crafting_consumption_mismatch', null, null, [], []];
            }
            $recipeOutputs = $match->outputs;
            $apiRecipe = self::apiComplexCraftingRecipe($match, $grid);
        } else {
            $recipe = $this->craftingCatalog->recipes()->recipeByNetworkId($command->crafting->recipeNetworkId);
            if ($recipe === null) {
                return [[], 'unknown_recipe', null, null, [], []];
            }
            $apiRecipe = self::apiCraftingRecipe($recipe);
        }

        if ($complexUuid === null && $command->crafting->automatic) {
            $provided = [];
            $main = $player->inventory->slots();
            foreach ($claimed as [$reference, $count]) {
                $stack = match ($reference->container) {
                    InventoryContainer::Main => $main[$reference->slot] ?? null,
                    InventoryContainer::CraftingInput => $player->inventory->craftingStack($reference->slot),
                    default => null,
                };
                if ($stack === null || $count < 1 || $stack->count < $count) {
                    return [[], 'crafting_consumption_mismatch', null, null, [], []];
                }
                $provided[] = $stack->withCountAndNetworkId($count, $stack->stackNetworkId);
            }
            if (!self::matchesCraftingIngredients(
                $provided,
                $recipe->ingredients(),
                $command->crafting->repetitions,
            )) {
                return [[], 'crafting_consumption_mismatch', null, null, [], []];
            }
            $consumed = $provided;
            $recipeOutputs = $recipe->outputsForInputs($provided);
        } elseif ($complexUuid === null) {
            $match = $recipe->match($grid, $command->crafting->repetitions);
            if ($match === null) {
                return [[], 'recipe_mismatch', null, null, [], []];
            }
            $claimedBySlot = [];
            foreach ($claimed as [$reference, $count]) {
                if ($reference->container !== InventoryContainer::CraftingInput
                    || isset($claimedBySlot[$reference->slot])) {
                    return [[], 'crafting_consumption_mismatch', null, null, [], []];
                }
                $claimedBySlot[$reference->slot] = $count;
                $stack = $grid->slots[$reference->slot] ?? null;
                if ($stack === null || $stack->count < $count) {
                    return [[], 'crafting_consumption_mismatch', null, null, [], []];
                }
                $consumed[] = $stack->withCountAndNetworkId($count, $stack->stackNetworkId);
            }
            ksort($claimedBySlot);
            if ($claimedBySlot !== $match->consumptionBySlot) {
                return [[], 'crafting_consumption_mismatch', null, null, [], []];
            }
            $recipeOutputs = $match->outputs;
        }

        foreach ($recipeOutputs as $output) {
            $count = $output->count * $command->crafting->repetitions;
            if ($count > $this->itemCatalog->type($output->identifier)->maximumStackSize) {
                return [[], 'crafting_output_capacity', null, null, [], []];
            }
            $outputs[] = $output->toInventoryStack(1, $count);
        }

        return [$outputs, null, $apiRecipe, $grid, $consumed, $recipeOutputs];
    }

    /**
     * Matches the exact stacks consumed by an automatic craft against recipe requirements.
     *
     * @param list<InventoryStack> $provided
     * @param list<\Bedriox\Server\Gameplay\Crafting\RecipeIngredient> $ingredients
     */
    private static function matchesCraftingIngredients(array $provided, array $ingredients, int $repetitions): bool
    {
        if ($provided === [] || $ingredients === [] || $repetitions < 1) {
            return false;
        }
        $required = 0;
        foreach ($ingredients as $ingredient) {
            $required += $ingredient->count * $repetitions;
        }
        $supplied = array_sum(array_map(
            static fn(InventoryStack $stack): int => $stack->count,
            $provided,
        ));
        if ($required !== $supplied) {
            return false;
        }

        $source = 0;
        $ingredientOffset = 1;
        $stackOffset = $ingredientOffset + count($ingredients);
        $sink = $stackOffset + count($provided);
        /** @var array<int, list<int>> $adjacent */
        $adjacent = array_fill(0, $sink + 1, []);
        /** @var array<int, array<int, int>> $capacity */
        $capacity = array_fill(0, $sink + 1, []);
        $addEdge = static function (int $from, int $to, int $amount) use (&$adjacent, &$capacity): void {
            if (!isset($capacity[$from][$to])) {
                $adjacent[$from][] = $to;
                $adjacent[$to][] = $from;
                $capacity[$from][$to] = 0;
                $capacity[$to][$from] = 0;
            }
            $capacity[$from][$to] += $amount;
        };

        foreach ($ingredients as $ingredientIndex => $ingredient) {
            $ingredientNode = $ingredientOffset + $ingredientIndex;
            $demand = $ingredient->count * $repetitions;
            $addEdge($source, $ingredientNode, $demand);
            foreach ($provided as $stackIndex => $stack) {
                if ($ingredient->matches($stack)) {
                    $addEdge($ingredientNode, $stackOffset + $stackIndex, $demand);
                }
            }
        }
        foreach ($provided as $stackIndex => $stack) {
            $addEdge($stackOffset + $stackIndex, $sink, $stack->count);
        }

        $flow = 0;
        while (true) {
            $parents = array_fill(0, $sink + 1, -1);
            $parents[$source] = $source;
            /** @var SplQueue<int> $queue */
            $queue = new SplQueue();
            $queue->enqueue($source);
            while (!$queue->isEmpty() && $parents[$sink] === -1) {
                $node = $queue->dequeue();
                foreach ($adjacent[$node] as $next) {
                    if ($parents[$next] === -1 && ($capacity[$node][$next] ?? 0) > 0) {
                        $parents[$next] = $node;
                        $queue->enqueue($next);
                    }
                }
            }
            if ($parents[$sink] === -1) {
                break;
            }
            $increment = PHP_INT_MAX;
            /** @var list<array{int, int}> $path */
            $path = [];
            for ($node = $sink; $node !== $source;) {
                $parent = $parents[$node] ?? -1;
                if ($parent < 0) {
                    return false;
                }
                $path[] = [$parent, $node];
                $increment = min($increment, $capacity[$parent][$node] ?? 0);
                $node = $parent;
            }
            foreach ($path as [$parent, $node]) {
                $capacity[$parent][$node] = ($capacity[$parent][$node] ?? 0) - $increment;
                $capacity[$node][$parent] = ($capacity[$node][$parent] ?? 0) + $increment;
            }
            $flow += $increment;
        }

        return $flow === $required;
    }

    private static function apiCraftingRecipe(CraftingRecipe $recipe): ApiCraftingRecipe
    {
        $outputs = array_map(self::apiRecipeOutput(...), $recipe->outputs());
        if ($recipe instanceof ShapedRecipe) {
            return new ApiShapedRecipe(
                $recipe->identifier(),
                $recipe->width,
                $recipe->height,
                array_map(
                    static fn(?RecipeIngredient $ingredient): ?ApiRecipeIngredient => $ingredient === null
                        ? null
                        : self::apiRecipeIngredient($ingredient),
                    $recipe->ingredientSlots(),
                ),
                $outputs,
                $recipe->priority(),
                $recipe->allowMirror,
            );
        }
        if ($recipe instanceof ShapelessRecipe) {
            return new ApiShapelessRecipe(
                $recipe->identifier(),
                array_map(self::apiRecipeIngredient(...), $recipe->ingredients()),
                $outputs,
                $recipe->priority(),
            );
        }

        throw new InvalidArgumentException('Crafting recipe cannot be represented by the public API.');
    }

    private static function apiComplexCraftingRecipe(
        CraftingRecipeMatch $match,
        CraftingGrid $grid,
    ): ApiCraftingRecipe {
        $ingredients = [];
        foreach ($match->consumptionBySlot as $slot => $totalCount) {
            $stack = $grid->slots[$slot] ?? null;
            if ($stack === null || $totalCount % $match->repetitions !== 0) {
                throw new InvalidArgumentException('Complex crafting match cannot be represented by the public API.');
            }
            $ingredients[] = ApiRecipeIngredient::exact(
                $stack->identifier,
                intdiv($totalCount, $match->repetitions),
                $stack->auxValue,
                $stack->damage,
                $stack->nbt,
            );
        }

        return new ApiShapelessRecipe(
            $match->recipeIdentifier,
            $ingredients,
            array_map(self::apiRecipeOutput(...), $match->outputs),
        );
    }

    private static function apiRecipeIngredient(RecipeIngredient $ingredient): ApiRecipeIngredient
    {
        return new ApiRecipeIngredient(
            $ingredient->identifiers,
            $ingredient->count,
            $ingredient->auxValue,
            $ingredient->damage,
            $ingredient->nbt,
        );
    }

    private static function apiRecipeOutput(RecipeOutput $output): ApiItemStack
    {
        return new ApiItemStack(
            $output->identifier,
            $output->count,
            $output->damage,
            $output->nbt,
            $output->auxValue,
        );
    }

    private static function apiInventoryStack(InventoryStack $stack): ApiItemStack
    {
        return new ApiItemStack(
            $stack->identifier,
            $stack->count,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        );
    }

    private static function apiCraftingGrid(CraftingGrid $grid): ApiCraftingGrid
    {
        return new ApiCraftingGrid(
            $grid->width,
            $grid->height,
            array_map(
                static fn(?InventoryStack $stack): ?ApiItemStack => $stack === null
                    ? null
                    : self::apiInventoryStack($stack),
                $grid->slots,
            ),
        );
    }

    /**
     * @param list<InventoryStack> $residue
     * @return list<InventoryStack>
     */
    private function applyConsumptionInventory(PlayerInventory $inventory, int $hotbarSlot, array $residue): array
    {
        $inventory->decrementSelectedOne();
        $dropped = [];
        foreach ($residue as $stack) {
            if ($inventory->selectedStack() === null) {
                $inventory->replaceSlot($hotbarSlot, $stack);
                continue;
            }
            $leftover = $inventory->add($stack);
            if ($leftover !== null) {
                $dropped[] = $leftover;
            }
        }

        return $dropped;
    }

    private function inventoryStackFromApi(ApiItemStack $stack): InventoryStack
    {
        $type = null;
        if ($this->itemCatalog !== null) {
            if (!$this->itemCatalog->has($stack->identifier)) {
                throw new InvalidArgumentException('Plugin item result is not present in the active item catalog.');
            }
            $type = $this->itemCatalog->type($stack->identifier);
        }
        $maximumStackSize = $type === null
            ? (SupportedInventoryItem::supports($stack->identifier)
                ? SupportedInventoryItem::maximumStackSize($stack->identifier)
                : 64)
            : $type->maximumStackSize;
        if ($stack->count > $maximumStackSize) {
            throw new InvalidArgumentException('Plugin item result exceeds the registered stack capacity.');
        }
        $placedBlockState = $type === null || $type->placedBlockState === null || $this->blockStateRegistry === null
            ? null
            : $this->blockStateRegistry->internalId($type->placedBlockState);

        return new InventoryStack(
            $stack->identifier,
            $stack->count,
            1,
            $placedBlockState,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        );
    }

    private function cancelItemUse(Player $player, ItemUseCancellationReason $reason): ItemUseCancelled
    {
        $key = self::sessionKey($player->sessionId);
        $active = $this->activeItemUses[$key]
            ?? throw new \LogicException('Cannot cancel an item use which is not active.');
        unset($this->activeItemUses[$key]);

        return $this->itemUseCancelledEvent($player, $active, $reason);
    }

    private function itemUseCancelledEvent(
        Player $player,
        ItemUseSession $active,
        ItemUseCancellationReason $reason,
    ): ItemUseCancelled {
        $this->pluginEvents?->itemUseCancelled(
            $player,
            $active->stack,
            ApiItemUseKind::CONSUME,
            $reason,
            $this->tick - $active->startedAtTick,
        );

        return new ItemUseCancelled(
            $player->sessionId,
            $player->runtimeActorId,
            $active->hotbarSlot,
            $active->stack,
            $reason,
            $this->players->recipients(),
            $player->movement->sneaking,
            $player->movement->sprinting,
            $player->movement->sequence,
        );
    }

    private function deferItemUseCancellation(Player $player, ItemUseCancellationReason $reason): void
    {
        if (!isset($this->activeItemUses[self::sessionKey($player->sessionId)])) {
            return;
        }
        $this->deferredEvents[] = $this->cancelItemUse($player, $reason);
    }

    private function reconcileActiveItemUse(?Player $player): void
    {
        if ($player === null) {
            return;
        }
        $active = $this->activeItemUses[self::sessionKey($player->sessionId)] ?? null;
        if ($active === null) {
            return;
        }
        if (!$player->vitals->isAlive()) {
            $this->deferItemUseCancellation($player, ItemUseCancellationReason::DEATH);
        } elseif (!$active->matches($player->inventory->selectedHotbarSlot(), $player->inventory->selectedStack())) {
            $this->deferItemUseCancellation($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
        }
    }

    /** @return list<WorldEvent> */
    private function advanceItemUseSessions(): array
    {
        $events = [];
        foreach ($this->activeItemUses as $key => $active) {
            $player = $this->players->player(substr($key, strlen('session:')));
            if ($player === null) {
                unset($this->activeItemUses[$key], $this->itemCooldowns[$key]);
                continue;
            }
            if (!$active->matches($player->inventory->selectedHotbarSlot(), $player->inventory->selectedStack())) {
                $events[] = $this->cancelItemUse($player, ItemUseCancellationReason::HELD_ITEM_CHANGED);
            } elseif ($this->tick > $active->completionTick() + self::MAXIMUM_ITEM_USE_HOLD_TICKS) {
                $events[] = $this->cancelItemUse($player, ItemUseCancellationReason::TIMED_OUT);
            }
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    /** @return list<WorldEvent> */
    private function advancePlayerEffects(): array
    {
        $events = [];
        foreach ($this->players->players() as $player) {
            if (!$player->vitals->isAlive() || $player->effects->snapshot() === []) {
                continue;
            }
            $levitationVelocity = VanillaEffectBehavior::levitationVelocity(
                $player->effects->snapshot(),
                $player->movement->verticalVelocity,
            );
            if ($levitationVelocity !== null) {
                $player->movement->verticalVelocity = $levitationVelocity;
                $player->movement->fallDistance = 0.0;
                $events[] = new PlayerMotionChanged(
                    $player->sessionId,
                    $player->snapshot(),
                    $player->movement->velocityX,
                    $levitationVelocity,
                    $player->movement->velocityZ,
                    $player->movement->clientTick,
                    false,
                    $this->players->recipients(),
                );
            }
            $transitions = $player->effects->tick(1, function (EffectInstance $effect) use ($player, &$events): void {
                if ($effect->type === EffectType::REGENERATION
                    && $player->vitals->health < VanillaEffectBehavior::maximumHealth($player->effects->snapshot())) {
                    $maximumHealth = VanillaEffectBehavior::maximumHealth($player->effects->snapshot());
                    $amount = min(1.0, $maximumHealth - $player->vitals->health);
                    $allowed = $this->pluginEvents?->regainHealth($player, ApiHealthRegainCause::EFFECT, $amount)
                        ?? ($this->pluginEvents === null ? $amount : null);
                    if ($allowed !== null && $allowed > 0.0) {
                        $applied = min($allowed, $maximumHealth - $player->vitals->health);
                        $player->vitals->health += $applied;
                        $this->pluginEvents?->regainedHealth($player, ApiHealthRegainCause::EFFECT, $applied);
                        $events[] = new PlayerHealed(
                            $player->snapshot(),
                            $applied,
                            HealthRegainCause::EFFECT,
                            $this->players->recipients(),
                        );
                    }
                    return;
                }
                if (in_array($effect->type, [EffectType::POISON, EffectType::FATAL_POISON, EffectType::WITHER], true)) {
                    $minimumHealth = $effect->type === EffectType::POISON ? 1.0 : 0.0;
                    $amount = min(1.0, max(0.0, $player->vitals->health - $minimumHealth));
                    if ($amount <= 0.0) {
                        return;
                    }
                    $damage = $this->damage(new DamagePlayer(
                        $player->sessionId,
                        $amount,
                        DamageCause::Magic,
                    ));
                    if ($damage instanceof PlayerDamaged) {
                        $events[] = $damage;
                        array_push($events, ...$this->drainDeferredEvents());
                    }
                    return;
                }
                if ($effect->type === EffectType::HUNGER) {
                    $player->vitals->exhaust(0.005 * $effect->level());
                }
            }, function (EffectInstance $effect) use ($player): void {
                $this->pluginEvents?->allowEffectRemoval($player, $effect, EffectCause::EXPIRATION);
            });
            $player->markDirty();
            foreach ($transitions as $transition) {
                if ($transition->previous === null) {
                    continue;
                }
                $this->pluginEvents?->effectRemoved(
                    $player,
                    $transition->previous,
                    EffectCause::EXPIRATION,
                    $transition->current,
                );
                if ($transition->previous->type === EffectType::ABSORPTION) {
                    $player->vitals->setAbsorption(
                        VanillaEffectBehavior::absorptionCapacity($player->effects->snapshot()),
                    );
                }
                if ($transition->previous->type === EffectType::HEALTH_BOOST) {
                    $player->vitals->health = min(
                        $player->vitals->health,
                        VanillaEffectBehavior::maximumHealth($player->effects->snapshot()),
                    );
                }
                $events[] = new PlayerEffectChanged(
                    $player->snapshot(),
                    $transition->previous->type,
                    $transition->current,
                    $this->players->recipients(),
                    $this->tick,
                    $transition->current !== null,
                );
            }
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceNutrition(): array
    {
        $events = [];
        foreach ($this->players->players() as $player) {
            if (!$player->vitals->isAlive() || $player->gameMode() !== GameMode::SURVIVAL) {
                $player->vitals->foodTickTimer = 0;
                continue;
            }
            ++$player->vitals->foodTickTimer;
            if ($player->vitals->foodTickTimer < self::NATURAL_REGENERATION_INTERVAL_TICKS) {
                continue;
            }
            $player->vitals->foodTickTimer = 0;
            $maximumHealth = VanillaEffectBehavior::maximumHealth($player->effects->snapshot());
            if ($player->vitals->food < self::NATURAL_REGENERATION_FOOD_THRESHOLD
                || $player->vitals->health >= $maximumHealth) {
                continue;
            }
            $healed = min(
                self::NATURAL_REGENERATION_HEALTH,
                $maximumHealth - $player->vitals->health,
            );
            if ($this->pluginEvents !== null) {
                $healed = $this->pluginEvents->regainHealth(
                    $player,
                    ApiHealthRegainCause::SATURATION,
                    $healed,
                );
                if ($healed === null || $healed <= 0.0) {
                    continue;
                }
                $healed = min(
                    $healed,
                    $maximumHealth - $player->vitals->health,
                );
            }
            $previousNutrition = PluginGameplayEventBridge::nutrition($player);
            $stagedVitals = clone $player->vitals;
            $stagedVitals->exhaust(self::NATURAL_REGENERATION_EXHAUSTION);
            $proposedNutrition = new ApiNutrition(
                (int) $stagedVitals->food,
                $stagedVitals->saturation,
                $stagedVitals->exhaustion,
            );
            if ($this->pluginEvents !== null) {
                $proposedNutrition = $this->pluginEvents->nutritionChange(
                    $player,
                    $previousNutrition,
                    $proposedNutrition,
                    ApiFoodLevelChangeCause::REGENERATION,
                );
                if ($proposedNutrition === null) {
                    continue;
                }
            }
            $player->vitals->health += $healed;
            $player->vitals->setNutrition(
                $proposedNutrition->foodLevel,
                $proposedNutrition->saturationLevel,
                $proposedNutrition->exhaustionLevel,
            );
            $player->markDirty();
            $currentNutrition = PluginGameplayEventBridge::nutrition($player);
            $this->pluginEvents?->regainedHealth($player, ApiHealthRegainCause::SATURATION, $healed);
            $this->pluginEvents?->nutritionChanged(
                $player,
                $previousNutrition,
                $currentNutrition,
                ApiFoodLevelChangeCause::REGENERATION,
            );
            $events[] = new PlayerHealed(
                $player->snapshot(),
                $healed,
                HealthRegainCause::SATURATION,
                $this->players->recipients(),
            );
            $events[] = new NutritionChanged(
                $player->snapshot(),
                $previousNutrition->foodLevel,
                $previousNutrition->saturationLevel,
                $previousNutrition->exhaustionLevel,
                NutritionChangeReason::NATURAL_REGENERATION,
                [$player->sessionId],
            );
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceWeather(): array
    {
        if ($this->blockWorld === null) {
            return [];
        }
        $previousCycle = $this->blockWorld->weather();
        $previous = $previousCycle->weather;
        if (!$this->blockWorld->advanceWeather()) {
            return [];
        }

        $proposed = $this->blockWorld->weather()->weather;
        $accepted = $this->pluginEvents === null
            ? $proposed
            : $this->pluginEvents->allowWeatherChange(
                $this->worldId,
                $previous,
                $proposed,
                WeatherChangeCause::NATURAL,
            );
        if ($accepted === null) {
            $this->blockWorld->setWeather($previousCycle);

            return [];
        }
        if ($accepted != $proposed) {
            $this->blockWorld->setCurrentWeather($accepted);
        }
        $this->pluginEvents?->weatherChanged(
            $this->worldId,
            $previous,
            $accepted,
            WeatherChangeCause::NATURAL,
        );

        return [new WeatherChanged($previous, $accepted, $this->players->recipients())];
    }

    public function setWeather(WeatherState $weather, WeatherChangeCause $cause = WeatherChangeCause::PLUGIN): bool
    {
        if ($this->blockWorld === null) {
            return false;
        }
        $previous = $this->blockWorld->weather()->weather;
        $accepted = $this->pluginEvents === null
            ? $weather
            : $this->pluginEvents->allowWeatherChange($this->worldId, $previous, $weather, $cause);
        if ($accepted === null) {
            return false;
        }
        $this->blockWorld->setCurrentWeather($accepted);
        $this->pluginEvents?->weatherChanged($this->worldId, $previous, $accepted, $cause);
        $this->deferredEvents[] = new WeatherChanged($previous, $accepted, $this->players->recipients());

        return true;
    }

    /** @return list<WorldEvent> */
    private function advanceEnvironmentalBlocks(): array
    {
        if ($this->environmentTicks === null || $this->fluidFlow === null || $this->fluidWorld === null
            || $this->blockWorld === null || $this->blockStateRegistry === null) {
            return [];
        }
        $drain = $this->environmentTicks->drain($this->tick, 256, 1_500);
        $events = [];
        foreach ($drain->ticks as $scheduled) {
            if ($scheduled->type === EnvironmentTickType::FROSTED_ICE) {
                if ($this->frostedIceState === null || $this->waterState === null) {
                    continue;
                }
                $current = $this->blockWorld->loadedBlockStateAt(
                    $scheduled->position->x,
                    $scheduled->position->y,
                    $scheduled->position->z,
                );
                if ($current === null || $current->value !== $this->frostedIceState->value) {
                    continue;
                }
                $previous = $this->setBlockStateAndSchedule($scheduled->position, $this->waterState);
                $events[] = new BlockChanged(
                    'server',
                    $scheduled->position,
                    $this->waterState,
                    $this->players->recipients(),
                    false,
                    $previous,
                );
                continue;
            }
            if ($scheduled->type !== EnvironmentTickType::FLUID) {
                continue;
            }
            $cell = $this->fluidWorld->cellAt($scheduled->position);
            if ($cell?->fluid === null) {
                continue;
            }
            $plan = $this->fluidFlow->plan($this->fluidWorld, $scheduled->position);
            foreach ($plan->mutations as $mutation) {
                try {
                    $state = $this->blockStateRegistry->internalId($mutation->state);
                    $previous = $this->blockWorld->loadedBlockStateAt(
                        $mutation->position->x,
                        $mutation->position->y,
                        $mutation->position->z,
                    );
                    if ($previous === null || $previous->value === $state->value) {
                        continue;
                    }
                    $this->setBlockStateAndSchedule($mutation->position, $state, false);
                    $events[] = new BlockChanged(
                        'server',
                        $mutation->position,
                        $state,
                        $this->players->recipients(),
                        false,
                        $previous,
                    );
                } catch (InvalidArgumentException|OverflowException) {
                    continue;
                }
            }
            $remaining = $this->fluidWorld->cellAt($scheduled->position)?->fluid;
            if ($remaining !== null || $plan->deferredPositions !== []) {
                $retryType = $remaining === null ? $cell->fluid->type : $remaining->type;
                $this->scheduleEnvironmentTick(
                    $scheduled->position,
                    $retryType,
                    $plan->deferredPositions === [] ? null : 20,
                );
            }
        }

        return $events;
    }

    private function setBlockStateAndSchedule(
        BlockPosition $position,
        InternalBlockStateId $state,
        bool $prioritizeNeighborFluids = true,
    ): InternalBlockStateId {
        if ($this->blockWorld === null) {
            throw new \LogicException('The authoritative block world is unavailable.');
        }
        $previous = $this->blockWorld->setBlockState($position->x, $position->y, $position->z, $state);
        if ($previous->value !== $state->value) {
            $this->scheduleFluidNeighborhood($position, $prioritizeNeighborFluids);
        }

        return $previous;
    }

    private function scheduleFluidNeighborhood(BlockPosition $origin, bool $prioritizeNeighbors): void
    {
        if ($this->fluidWorld === null || $this->environmentTicks === null) {
            return;
        }
        foreach ([[0, 0, 0], [0, 1, 0], [0, -1, 0], [0, 0, -1], [0, 0, 1], [-1, 0, 0], [1, 0, 0]] as $index => [$x, $y, $z]) {
            try {
                $position = new BlockPosition($origin->x + $x, $origin->y + $y, $origin->z + $z);
            } catch (InvalidArgumentException) {
                continue;
            }
            $fluid = $this->fluidWorld->cellAt($position)?->fluid;
            if ($fluid !== null) {
                $this->scheduleEnvironmentTick(
                    $position,
                    $fluid->type,
                    $index !== 0 && $prioritizeNeighbors ? 1 : null,
                );
            }
        }
    }

    private function scheduleEnvironmentTick(BlockPosition $position, FluidType $type, ?int $delay = null): void
    {
        try {
            $this->environmentTicks?->schedule(
                $position,
                EnvironmentTickType::FLUID,
                $this->tick,
                $delay ?? $type->tickDelay(),
            );
        } catch (OverflowException) {
            // Saturation is observable through the queue metrics; gameplay remains bounded and authoritative.
        }
    }

    /** @return list<WorldEvent> */
    private function advancePlayerEnvironment(): array
    {
        if ($this->blockWorld === null) {
            return [];
        }
        $events = [];
        foreach ($this->players->players() as $player) {
            if (!$player->vitals->isAlive()) {
                continue;
            }
            $previousAir = $player->vitals->airTicks;
            $previousFire = $player->vitals->fireTicks;
            $position = $player->movement->position;
            $headState = $this->blockWorld->blockStateAt(
                (int) floor($position->x),
                (int) floor($position->y + 1.62),
                (int) floor($position->z),
            );
            $feetState = $this->blockWorld->blockStateAt(
                (int) floor($position->x),
                (int) floor($position->y + 0.1),
                (int) floor($position->z),
            );
            $effects = $player->effects->snapshot();
            $headFluid = $this->blockStateRegistry === null
                ? null
                : FluidState::fromCanonical($this->blockStateRegistry->state($headState));
            $feetFluid = $this->blockStateRegistry === null
                ? null
                : FluidState::fromCanonical($this->blockStateRegistry->state($feetState));
            $headY = $position->y + 1.62;
            $submerged = $headFluid?->type === FluidType::WATER
                ? floor($headY) + $headFluid->height() > $headY
                : $headState->value === $this->waterState?->value;
            if (!$submerged || VanillaEffectBehavior::canBreatheUnderwater($effects)) {
                $player->vitals->airTicks = min(
                    \Bedriox\Server\Player\PlayerVitals::MAX_AIR_TICKS,
                    $player->vitals->airTicks + 4,
                );
            } else {
                $respiration = $this->armorEnchantmentLevel(
                    $player,
                    ArmorSlot::Head,
                    VanillaEnchantments::RESPIRATION,
                );
                $airConsumed = $respiration === 0 || $this->dropRandom->integer(0, $respiration) === 0;
                if ($airConsumed) {
                    --$player->vitals->airTicks;
                }
                if ($airConsumed && $player->vitals->airTicks <= -20) {
                    $player->vitals->airTicks = 0;
                    $events[] = $this->damage(new DamagePlayer(
                        $player->sessionId,
                        self::DROWNING_DAMAGE,
                        DamageCause::Drowning,
                    ));
                    array_push($events, ...$this->drainDeferredEvents());
                }
            }

            $touchingWater = $headFluid?->type === FluidType::WATER || $feetFluid?->type === FluidType::WATER
                || $headState->value === $this->waterState?->value || $feetState->value === $this->waterState?->value;
            $touchingLava = $headFluid?->type === FluidType::LAVA || $feetFluid?->type === FluidType::LAVA
                || $headState->value === $this->lavaState?->value || $feetState->value === $this->lavaState?->value;
            if ($touchingWater || VanillaEffectBehavior::hasFireResistance($effects)) {
                $player->vitals->fireTicks = 0;
            } elseif ($player->vitals->fireTicks > 0
                && $this->blockWorld->weather()->weather->isRaining()
                && ($this->tick + $player->runtimeActorId) % 20 === 0
                && $this->isPlayerExposedToRain($player)) {
                $player->vitals->fireTicks = 0;
            } elseif ($touchingLava) {
                $player->vitals->fireTicks = max(
                    $player->vitals->fireTicks,
                    $this->fireProtectionAdjustedTicks($player, 300),
                );
                if ($this->tick % 10 === 0) {
                    $events[] = $this->damage(new DamagePlayer(
                        $player->sessionId,
                        self::LAVA_CONTACT_DAMAGE,
                        DamageCause::Fire,
                    ));
                    array_push($events, ...$this->drainDeferredEvents());
                }
            } elseif ($player->vitals->fireTicks > 0) {
                --$player->vitals->fireTicks;
                if ($player->vitals->fireTicks % 20 === 0) {
                    $events[] = $this->damage(new DamagePlayer(
                        $player->sessionId,
                        self::FIRE_TICK_DAMAGE,
                        DamageCause::Fire,
                    ));
                    array_push($events, ...$this->drainDeferredEvents());
                }
            }
            $airChanged = $player->vitals->airTicks !== $previousAir;
            $publishAir = $airChanged && (
                ($this->tick + $player->runtimeActorId) % 20 === 0
                || $player->vitals->airTicks === 0
                || $player->vitals->airTicks === \Bedriox\Server\Player\PlayerVitals::MAX_AIR_TICKS
            );
            $fireStateChanged = ($previousFire > 0) !== ($player->vitals->fireTicks > 0);
            if ($airChanged || $player->vitals->fireTicks !== $previousFire) {
                $player->markDirty();
            }
            if ($publishAir || $fireStateChanged) {
                $events[] = new PlayerEnvironmentChanged(
                    $player->snapshot(),
                    $this->players->recipients(),
                    $this->tick,
                    $publishAir,
                    $fireStateChanged,
                );
            }
        }

        return $events;
    }

    private function isPlayerExposedToRain(Player $player): bool
    {
        if ($this->blockWorld === null || $this->blockCollisionRegistry === null) {
            return false;
        }
        $position = $player->movement->position;
        $x = (int) floor($position->x);
        $z = (int) floor($position->z);
        for ($y = min(319, (int) floor($position->y + 1.8)); $y <= 319; ++$y) {
            $state = $this->blockWorld->loadedBlockStateAt($x, $y, $z);
            if ($state === null) {
                return false;
            }
            $shape = $this->blockCollisionRegistry->find($state);
            if ($shape !== null && !$shape->isEmpty()) {
                return false;
            }
        }

        return true;
    }

    private function playerIsSubmerged(Player $player): bool
    {
        if ($this->blockWorld === null) {
            return false;
        }
        $position = $player->movement->position;
        $headY = $position->y + 1.62;
        $headState = $this->blockWorld->blockStateAt(
            (int) floor($position->x),
            (int) floor($headY),
            (int) floor($position->z),
        );
        $fluid = $this->blockStateRegistry === null
            ? null
            : FluidState::fromCanonical($this->blockStateRegistry->state($headState));

        return $fluid?->type === FluidType::WATER
            ? floor($headY) + $fluid->height() > $headY
            : $headState->value === $this->waterState?->value;
    }

    private function movementEnchantmentValidationMultiplier(Player $player): float
    {
        $multiplier = 1.0;
        if ($this->playerIsSubmerged($player)) {
            $depthStrider = $this->armorEnchantmentLevel(
                $player,
                ArmorSlot::Feet,
                VanillaEnchantments::DEPTH_STRIDER,
            );
            $multiplier = max($multiplier, 1.0 + (min(3, $depthStrider) * 0.1));
        }
        if ($player->movement->sneaking) {
            $swiftSneak = $this->armorEnchantmentLevel(
                $player,
                ArmorSlot::Legs,
                VanillaEnchantments::SWIFT_SNEAK,
            );
            $multiplier = max($multiplier, 1.0 + (min(3, $swiftSneak) * 0.15));
        }
        if ($this->blockWorld !== null && $this->blockStateRegistry !== null) {
            $position = $player->movement->position;
            $below = $this->blockWorld->blockStateAt(
                (int) floor($position->x),
                (int) floor($position->y - 0.1),
                (int) floor($position->z),
            );
            $identifier = $this->blockStateRegistry->state($below)->identifier();
            if ($identifier === 'minecraft:soul_sand' || $identifier === 'minecraft:soul_soil') {
                $soulSpeed = $this->armorEnchantmentLevel(
                    $player,
                    ArmorSlot::Feet,
                    VanillaEnchantments::SOUL_SPEED,
                );
                $multiplier = max($multiplier, 1.0 + (min(3, $soulSpeed) * 0.105));
            }
        }

        return $multiplier;
    }

    private function applyFrostWalker(Player $player): void
    {
        $level = min(2, $this->armorEnchantmentLevel(
            $player,
            ArmorSlot::Feet,
            VanillaEnchantments::FROST_WALKER,
        ));
        if ($level < 1 || $this->blockWorld === null || $this->blockStateRegistry === null
            || $this->environmentTicks === null || $this->frostedIceState === null) {
            return;
        }
        $radius = $level + 2;
        $centerX = (int) floor($player->movement->position->x);
        $centerY = (int) floor($player->movement->position->y - 0.1);
        $centerZ = (int) floor($player->movement->position->z);
        for ($x = $centerX - $radius; $x <= $centerX + $radius; ++$x) {
            for ($z = $centerZ - $radius; $z <= $centerZ + $radius; ++$z) {
                $dx = ($x + 0.5) - $player->movement->position->x;
                $dz = ($z + 0.5) - $player->movement->position->z;
                if (($dx * $dx) + ($dz * $dz) > $radius * $radius) {
                    continue;
                }
                try {
                    $position = new BlockPosition($x, $centerY, $z);
                } catch (InvalidArgumentException) {
                    continue;
                }
                $current = $this->blockWorld->loadedBlockStateAt($x, $centerY, $z);
                $above = $this->blockWorld->loadedBlockStateAt($x, $centerY + 1, $z);
                if ($current === null || $above === null || $above->value !== $this->blockPalette?->air->value) {
                    continue;
                }
                $state = $this->blockStateRegistry->state($current);
                if ($state->identifier() !== 'minecraft:water'
                    || ($state->properties()['liquid_depth'] ?? 0) !== 0) {
                    continue;
                }
                $previous = $this->setBlockStateAndSchedule($position, $this->frostedIceState, false);
                $this->deferredEvents[] = new BlockChanged(
                    'server',
                    $position,
                    $this->frostedIceState,
                    $this->players->recipients(),
                    false,
                    $previous,
                );
                try {
                    $salt = abs(($x * 73428767) ^ ($z * 912931) ^ $this->tick);
                    $this->environmentTicks->schedule(
                        $position,
                        EnvironmentTickType::FROSTED_ICE,
                        $this->tick,
                        60 + ($salt % 61),
                    );
                } catch (OverflowException) {
                    // The environmental queue exposes saturation and remains bounded.
                }
            }
        }
    }

    private function pruneItemCooldowns(string $key): void
    {
        foreach ($this->itemCooldowns[$key] ?? [] as $identifier => $expiry) {
            if ($expiry <= $this->tick) {
                unset($this->itemCooldowns[$key][$identifier]);
            }
        }
        if (($this->itemCooldowns[$key] ?? []) === []) {
            unset($this->itemCooldowns[$key]);
        }
    }

    private function setItemCooldown(string $key, string $identifier, int $ticks): void
    {
        $this->pruneItemCooldowns($key);
        if (count($this->itemCooldowns[$key] ?? []) >= 64) {
            asort($this->itemCooldowns[$key], SORT_NUMERIC);
            $oldest = array_key_first($this->itemCooldowns[$key]);
            unset($this->itemCooldowns[$key][$oldest]);
        }
        $this->itemCooldowns[$key][$identifier] = $this->tick + $ticks;
    }

    private function heldItemType(Player $player): ?\Bedriox\Server\Gameplay\Item\ItemType
    {
        $held = $player->inventory->selectedStack();
        if ($held === null || $this->itemCatalog === null || !$this->itemCatalog->has($held->identifier)) {
            return null;
        }

        return $this->itemCatalog->type($held->identifier);
    }

    private function meleeDamage(Player $player): float
    {
        return $this->heldItemType($player)?->tool?->attackDamage()
            ?? CombatRules::EMPTY_HAND_DAMAGE;
    }

    private function entityMeleeDamage(AbstractLivingEntity $entity, float $fallback): float
    {
        $held = $entity->equipmentState()->getItem(ApiEquipmentSlot::MAIN_HAND);
        if ($held === null || $this->itemCatalog === null || !$this->itemCatalog->has($held->identifier)) {
            return $fallback;
        }

        return max($fallback, $this->itemCatalog->type($held->identifier)->tool?->attackDamage() ?? $fallback);
    }

    private function effectReducedDamage(Player $player, float $damage): float
    {
        return max(0.0, $damage * VanillaEffectBehavior::incomingDamageMultiplier($player->effects->snapshot()));
    }

    private function armorReducedDamage(
        Player $player,
        float $damage,
        DamageCause $cause,
        int $breachLevel = 0,
    ): float {
        $effectiveDefense = $player->inventory->defensePoints()
            * (1.0 - min(0.6, max(0, $breachLevel) * 0.15));
        $afterArmor = $cause === DamageCause::Fall
            ? $damage
            : max(0.0, $damage * (1.0 - ($effectiveDefense * 0.04)));
        $protection = EnchantmentEffects::protectionFactor($this->armorEnchantments($player), $cause);

        return max(0.0, $afterArmor * (1.0 - ($protection * 0.04)));
    }

    private function entityArmorReducedDamage(
        AbstractLivingEntity $entity,
        float $damage,
        int $breachLevel = 0,
    ): float {
        if ($this->itemCatalog === null) {
            return $damage;
        }
        $defense = 0;
        foreach ([
            ApiEquipmentSlot::HEAD,
            ApiEquipmentSlot::CHEST,
            ApiEquipmentSlot::LEGS,
            ApiEquipmentSlot::FEET,
        ] as $slot) {
            $item = $entity->equipmentState()->getItem($slot);
            if ($item === null || !$this->itemCatalog->has($item->identifier)) {
                continue;
            }
            $armor = $this->itemCatalog->type($item->identifier)->armor;
            if ($armor !== null) {
                $defense += $armor->defensePoints;
            }
        }

        $effectiveDefense = min(20, $defense)
            * (1.0 - min(0.6, max(0, $breachLevel) * 0.15));

        return max(0.0, $damage * (1.0 - ($effectiveDefense * 0.04)));
    }

    private function damageEntityArmor(AbstractLivingEntity $entity, float $baseDamage): void
    {
        if ($this->itemCatalog === null) {
            return;
        }
        $wear = max((int) floor($baseDamage / 4.0), 1);
        foreach ([
            ApiEquipmentSlot::HEAD,
            ApiEquipmentSlot::CHEST,
            ApiEquipmentSlot::LEGS,
            ApiEquipmentSlot::FEET,
        ] as $slot) {
            $item = $entity->equipmentState()->getItem($slot);
            if ($item === null || !$this->itemCatalog->has($item->identifier)) {
                continue;
            }
            $armor = $this->itemCatalog->type($item->identifier)->armor;
            if ($armor === null) {
                continue;
            }
            $actualWear = EnchantmentEffects::durabilityDamage(
                $wear,
                EnchantmentEffects::level($item->nbt, VanillaEnchantments::UNBREAKING),
                true,
                $this->dropRandom,
            );
            if ($actualWear === 0) {
                continue;
            }
            $damage = $item->damage + $actualWear;
            $entity->equipmentState()->setItem(
                $slot,
                $damage >= $armor->maximumDurability
                    ? null
                    : new ApiItemStack(
                        $item->identifier,
                        $item->count,
                        $damage,
                        $item->nbt,
                        $item->auxValue,
                    ),
            );
        }
    }

    private function damageArmor(Player $player, float $baseDamage, DamageCause $cause): bool
    {
        if ($cause === DamageCause::Fall || $this->itemCatalog === null) {
            return false;
        }
        $defaultWear = max((int) floor($baseDamage / 4.0), 1);
        $changed = false;
        foreach (ArmorSlot::cases() as $slot) {
            $stack = $player->inventory->armorStack($slot);
            if ($stack === null || !$this->itemCatalog->has($stack->identifier)) {
                continue;
            }
            $definition = $this->itemCatalog->type($stack->identifier)->armor;
            if ($definition === null || $definition->slot !== $slot) {
                continue;
            }
            $apiSlot = self::apiEquipmentSlot($slot);
            $enchantmentAdjustedWear = EnchantmentEffects::durabilityDamage(
                $defaultWear,
                EnchantmentEffects::level($stack->nbt, VanillaEnchantments::UNBREAKING),
                true,
                $this->dropRandom,
            );
            if ($enchantmentAdjustedWear === 0) {
                continue;
            }
            $wear = $this->pluginEvents?->itemDamage(
                $player,
                $stack,
                ApiItemDamageCause::DAMAGE_ABSORPTION,
                $apiSlot,
                $enchantmentAdjustedWear,
            ) ?? ($this->pluginEvents === null ? $enchantmentAdjustedWear : null);
            if ($wear === null || $wear === 0) {
                continue;
            }
            $newDamage = $stack->damage + $wear;
            $replacement = $newDamage >= $definition->maximumDurability
                ? null
                : $stack->withDamage($newDamage);
            $player->inventory->replaceArmorSlot($slot, $replacement);
            $this->pluginEvents?->equipmentChanged($player, $apiSlot, $stack, $replacement);
            if ($replacement === null) {
                $this->pluginEvents?->itemBroken(
                    $player,
                    $stack,
                    ApiItemDamageCause::DAMAGE_ABSORPTION,
                    $apiSlot,
                );
            }
            $changed = true;
        }

        return $changed;
    }

    private static function apiEquipmentSlot(ArmorSlot $slot): ApiEquipmentSlot
    {
        return match ($slot) {
            ArmorSlot::Head => ApiEquipmentSlot::HEAD,
            ArmorSlot::Chest => ApiEquipmentSlot::CHEST,
            ArmorSlot::Legs => ApiEquipmentSlot::LEGS,
            ArmorSlot::Feet => ApiEquipmentSlot::FEET,
        };
    }

    private function damageHeldTool(
        Player $player,
        ?\Bedriox\Server\Gameplay\Item\ItemType $heldType,
        bool $attack = false,
    ): void {
        $tool = $heldType?->tool;
        $held = $player->inventory->selectedStack();
        if ($tool === null || $held === null) {
            return;
        }
        $wear = $attack ? $tool->durabilityDamagePerAttack : $tool->durabilityDamagePerBlock;
        $wear = EnchantmentEffects::durabilityDamage(
            $wear,
            EnchantmentEffects::level($held->nbt, VanillaEnchantments::UNBREAKING),
            false,
            $this->dropRandom,
        );
        if ($wear === 0) {
            return;
        }
        $cause = $attack ? ApiItemDamageCause::ENTITY_ATTACK : ApiItemDamageCause::BLOCK_BREAK;
        $wear = $this->pluginEvents?->itemDamage(
            $player,
            $held,
            $cause,
            ApiEquipmentSlot::MAIN_HAND,
            $wear,
        ) ?? ($this->pluginEvents === null ? $wear : null);
        if ($wear === null || $wear === 0) {
            return;
        }
        $damage = $held->damage + $wear;
        $remaining = $damage >= $tool->durability ? null : $held->withDamage($damage);
        $player->inventory->replaceSlot($player->inventory->selectedHotbarSlot(), $remaining);
        $player->markDirty();
        if ($remaining === null) {
            $this->pluginEvents?->itemBroken($player, $held, $cause, ApiEquipmentSlot::MAIN_HAND);
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: true,
        );
    }

    /** @return array<string, int> */
    private function heldEnchantments(Player $player): array
    {
        return WorkstationItemData::enchantments($player->inventory->selectedStack()?->nbt);
    }

    /** @return list<array<string, int>> */
    private function armorEnchantments(Player $player): array
    {
        $enchantments = [];
        foreach (ArmorSlot::cases() as $slot) {
            $stack = $player->inventory->armorStack($slot);
            if ($stack !== null) {
                $enchantments[] = WorkstationItemData::enchantments($stack->nbt);
            }
        }

        return $enchantments;
    }

    private function armorEnchantmentLevel(Player $player, ArmorSlot $slot, string $identifier): int
    {
        return EnchantmentEffects::level($player->inventory->armorStack($slot)?->nbt, $identifier);
    }

    private function fireProtectionAdjustedTicks(Player $player, int $ticks): int
    {
        $level = 0;
        foreach ($this->armorEnchantments($player) as $enchantments) {
            $level = max($level, $enchantments[VanillaEnchantments::FIRE_PROTECTION] ?? 0);
        }

        return max(1, (int) floor($ticks * (1.0 - min(0.8, $level * 0.15))));
    }

    private function applyPlayerThorns(Player $wearer, Player $attacker): bool
    {
        $reflectedDamage = 0.0;
        $equipmentChanged = false;
        foreach (ArmorSlot::cases() as $slot) {
            $stack = $wearer->inventory->armorStack($slot);
            $level = EnchantmentEffects::level($stack?->nbt, VanillaEnchantments::THORNS);
            if ($stack === null || $level === 0) {
                continue;
            }
            $triggered = $this->dropRandom->integer(1, 100) <= min(100, $level * 15);
            if ($triggered) {
                $reflectedDamage += $level > 10 ? $level - 10 : $this->dropRandom->integer(1, 4);
            }
            $equipmentChanged = $this->damageArmorSlot(
                $wearer,
                $slot,
                $stack,
                $triggered ? 3 : 1,
                ApiItemDamageCause::ENCHANTMENT,
            ) || $equipmentChanged;
        }
        if ($reflectedDamage > 0.0) {
            $event = $this->damage(new DamagePlayer(
                $attacker->sessionId,
                $reflectedDamage,
                DamageCause::Thorns,
            ));
            if (!$event instanceof CommandRejected) {
                $this->deferredEvents[] = $event;
            }
        }

        return $equipmentChanged;
    }

    private function applyEntityThorns(AbstractLivingEntity $wearer, Player $attacker): void
    {
        $reflectedDamage = 0.0;
        foreach ([
            ApiEquipmentSlot::HEAD,
            ApiEquipmentSlot::CHEST,
            ApiEquipmentSlot::LEGS,
            ApiEquipmentSlot::FEET,
        ] as $slot) {
            $stack = $wearer->equipmentState()->getItem($slot);
            $level = EnchantmentEffects::level($stack?->nbt, VanillaEnchantments::THORNS);
            if ($stack === null || $level === 0) {
                continue;
            }
            $triggered = $this->dropRandom->integer(1, 100) <= min(100, $level * 15);
            if ($triggered) {
                $reflectedDamage += $level > 10 ? $level - 10 : $this->dropRandom->integer(1, 4);
            }
            $this->damageEntityEquipment($wearer, $slot, $stack, $triggered ? 3 : 1);
        }
        if ($reflectedDamage > 0.0) {
            $event = $this->damage(new DamagePlayer(
                $attacker->sessionId,
                $reflectedDamage,
                DamageCause::Thorns,
            ));
            if (!$event instanceof CommandRejected) {
                $this->deferredEvents[] = $event;
            }
        }
    }

    private function damageArmorSlot(
        Player $player,
        ArmorSlot $slot,
        InventoryStack $stack,
        int $wear,
        ApiItemDamageCause $cause,
    ): bool {
        if ($this->itemCatalog === null || !$this->itemCatalog->has($stack->identifier)) {
            return false;
        }
        $definition = $this->itemCatalog->type($stack->identifier)->armor;
        if ($definition === null || $definition->slot !== $slot) {
            return false;
        }
        $wear = EnchantmentEffects::durabilityDamage(
            $wear,
            EnchantmentEffects::level($stack->nbt, VanillaEnchantments::UNBREAKING),
            true,
            $this->dropRandom,
        );
        if ($wear === 0) {
            return false;
        }
        $apiSlot = self::apiEquipmentSlot($slot);
        $wear = $this->pluginEvents?->itemDamage($player, $stack, $cause, $apiSlot, $wear)
            ?? ($this->pluginEvents === null ? $wear : null);
        if ($wear === null || $wear === 0) {
            return false;
        }
        $replacement = $stack->damage + $wear >= $definition->maximumDurability
            ? null
            : $stack->withDamage($stack->damage + $wear);
        $player->inventory->replaceArmorSlot($slot, $replacement);
        $this->pluginEvents?->equipmentChanged($player, $apiSlot, $stack, $replacement);
        if ($replacement === null) {
            $this->pluginEvents?->itemBroken($player, $stack, $cause, $apiSlot);
        }

        return true;
    }

    private function damageEntityEquipment(
        AbstractLivingEntity $entity,
        ApiEquipmentSlot $slot,
        ApiItemStack $stack,
        int $wear,
    ): void {
        if ($this->itemCatalog === null || !$this->itemCatalog->has($stack->identifier)) {
            return;
        }
        $definition = $this->itemCatalog->type($stack->identifier)->armor;
        if ($definition === null) {
            return;
        }
        $wear = EnchantmentEffects::durabilityDamage(
            $wear,
            EnchantmentEffects::level($stack->nbt, VanillaEnchantments::UNBREAKING),
            true,
            $this->dropRandom,
        );
        if ($wear === 0) {
            return;
        }
        $entity->equipmentState()->setItem(
            $slot,
            $stack->damage + $wear >= $definition->maximumDurability
                ? null
                : new ApiItemStack(
                    $stack->identifier,
                    $stack->count,
                    $stack->damage + $wear,
                    $stack->nbt,
                    $stack->auxValue,
                ),
        );
    }

    /** @param array<string, int> $enchantments */
    private function maceDensityDamage(Player $attacker, array $enchantments): float
    {
        if ($attacker->inventory->selectedStack()?->identifier !== 'minecraft:mace'
            || $attacker->movement->fallDistance <= 1.5) {
            return 0.0;
        }
        $density = $enchantments[VanillaEnchantments::DENSITY] ?? 0;

        return max(0.0, $attacker->movement->fallDistance - 1.5) * (0.5 * $density);
    }

    /** @param array<string, int> $enchantments */
    private function applyWindBurst(Player $attacker, array $enchantments): void
    {
        if ($attacker->inventory->selectedStack()?->identifier !== 'minecraft:mace'
            || $attacker->movement->fallDistance <= 1.5) {
            return;
        }
        $level = $enchantments[VanillaEnchantments::WIND_BURST] ?? 0;
        $attacker->movement->fallDistance = 0.0;
        if ($level === 0) {
            return;
        }
        $attacker->movement->verticalVelocity = min(1.5, 0.7 + (0.1 * $level));
        $attacker->movement->verticalState = VerticalState::AIRBORNE;
        $attacker->movement->jumpAuthorizedUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $attacker->markDirty();
        $this->deferredEvents[] = new PlayerMotionChanged(
            $attacker->sessionId,
            $attacker->snapshot(),
            $attacker->movement->velocityX,
            $attacker->movement->verticalVelocity,
            $attacker->movement->velocityZ,
            $attacker->movement->clientTick,
            true,
            $this->players->recipients(),
        );
    }

    private function blockIsReachable(PlayerSnapshot $player, BlockPosition $position): bool
    {
        return hypot(
            hypot(($position->x + 0.5) - $player->position->x, ($position->z + 0.5) - $player->position->z),
            ($position->y + 0.5) - ($player->position->y + 1.62),
        ) <= self::MAXIMUM_BLOCK_REACH;
    }

    private function selectHotbarSlot(SelectHotbarSlot $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $before = null;
        if ($this->pluginEvents !== null) {
            $before = clone $player->inventory;
            $proposed = clone $player->inventory;
            $proposed->selectHotbarSlot($command->hotbarSlot);
            if (!$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
                return new HeldItemChanged(
                    $player->sessionId,
                    $player->runtimeActorId,
                    $player->inventory->selectedHotbarSlot(),
                    $player->inventory->selectedStack(),
                    $this->players->recipients($player->sessionId),
                );
            }
        }
        $player->inventory->selectHotbarSlot($command->hotbarSlot);
        $player->markDirty();
        if ($this->pluginEvents !== null) {
            $this->pluginEvents->inventoryChanged($player, $before);
        }

        return new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $command->hotbarSlot,
            $player->inventory->selectedStack(),
            $this->players->recipients($player->sessionId),
        );
    }

    private function inventoryStackRequest(ApplyInventoryStackRequest $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        foreach ($command->actions as $action) {
            if ($action->source->container === InventoryContainer::OpenedContainer
                || $action->destination->container === InventoryContainer::OpenedContainer) {
                return $this->openedContainerStackRequest($player, $command);
            }
        }
        $rejectionReason = $command->rejectionReason;
        $craftingOutputs = [];
        $craftingRecipe = null;
        $craftingGrid = null;
        $craftingConsumed = [];
        $craftingEventOutputs = [];
        $automaticCrafting = $command->crafting !== null && $command->crafting->automatic;
        $predictionOnly = $command->actions !== [];
        $usesCreatedOutput = false;
        foreach ($command->actions as $action) {
            $predictionOnly = $predictionOnly && $action->type === InventoryStackRequestActionType::MineBlock;
            if ($action->source->container === InventoryContainer::CreatedOutput
                || $action->destination->container === InventoryContainer::CreatedOutput) {
                $usesCreatedOutput = true;
                break;
            }
        }
        if ($command->authoritativeCreativeStack !== null && $command->crafting !== null) {
            $rejectionReason = 'mixed_created_output';
        } elseif ($command->crafting !== null && $rejectionReason === null) {
            [
                $craftingOutputs,
                $rejectionReason,
                $craftingRecipe,
                $craftingGrid,
                $craftingConsumed,
                $recipeOutputs,
            ] = $this->resolveCraftingRequest($player, $command);
            if ($rejectionReason === null) {
                $craftingEventOutputs = array_map(self::apiRecipeOutput(...), $recipeOutputs);
            }
        }
        if ($command->authoritativeCreativeStack !== null && $player->gameMode() !== GameMode::CREATIVE) {
            $rejectionReason = 'creative_requires_creative_mode';
        } elseif ($command->authoritativeCreativeStack !== null && !$usesCreatedOutput) {
            $rejectionReason = 'unused_creative_output';
        } elseif ($usesCreatedOutput && $command->authoritativeCreativeStack === null && $craftingOutputs === []) {
            $rejectionReason = 'missing_created_output';
        } elseif ($craftingOutputs !== [] && !$usesCreatedOutput) {
            $rejectionReason = 'unused_crafting_output';
        }
        if ($rejectionReason === null && $command->crafting !== null && $this->pluginEvents !== null) {
            $preflight = clone $player->inventory;
            $preflightResult = $preflight->applyStackRequest(
                $command->requestId,
                $command->actions,
                createdOutputUnlimited: false,
                createdOutputs: $craftingOutputs,
                allowMainConsumption: $automaticCrafting,
            );
            if (!$preflightResult->success) {
                $rejectionReason = $preflightResult->reason;
            }
        }
        if ($rejectionReason === null && $command->crafting !== null && $this->pluginEvents !== null
            && $craftingRecipe !== null && $craftingGrid !== null) {
            try {
                $pluginOutputs = $this->pluginEvents->craft(
                    $player,
                    $craftingRecipe,
                    self::apiCraftingGrid($craftingGrid),
                    $command->crafting->repetitions,
                    array_map(self::apiInventoryStack(...), $craftingConsumed),
                    $craftingEventOutputs,
                );
                if ($pluginOutputs === null) {
                    $rejectionReason = 'plugin_cancelled';
                } else {
                    $craftingEventOutputs = $pluginOutputs;
                    $craftingOutputs = [];
                    foreach ($craftingEventOutputs as $eventOutput) {
                        $output = $this->inventoryStackFromApi($eventOutput);
                        $count = $eventOutput->count * $command->crafting->repetitions;
                        if ($this->itemCatalog === null
                            || $count > $this->itemCatalog->type($eventOutput->identifier)->maximumStackSize) {
                            throw new InvalidArgumentException('Plugin craft output exceeds the admitted stack capacity.');
                        }
                        $craftingOutputs[] = $output->withCountAndNetworkId($count, 1);
                    }
                }
            } catch (InvalidArgumentException|OverflowException) {
                $rejectionReason = 'plugin_result';
            }
        }
        if ($rejectionReason === null && !$predictionOnly && $player->gameMode() !== GameMode::CREATIVE) {
            $bindingCheck = clone $player->inventory;
            $bindingResult = $bindingCheck->applyStackRequest(
                $command->requestId,
                $command->actions,
                $command->authoritativeCreativeStack,
                createdOutputUnlimited: $command->authoritativeCreativeStack !== null,
                createdOutputs: $craftingOutputs,
                allowMainConsumption: $automaticCrafting,
            );
            if ($bindingResult->success && self::removesBoundArmor($player->inventory, $bindingCheck)) {
                $rejectionReason = 'binding_curse';
            }
        }
        if ($this->pluginEvents === null || $predictionOnly) {
            $result = $rejectionReason === null
                ? $player->inventory->applyStackRequest(
                    $command->requestId,
                    $command->actions,
                    $command->authoritativeCreativeStack,
                    createdOutputUnlimited: $command->authoritativeCreativeStack !== null,
                    createdOutputs: $craftingOutputs,
                    allowMainConsumption: $automaticCrafting,
                )
                : new InventoryStackRequestResult(false, reason: $rejectionReason);
        } else {
            $before = clone $player->inventory;
            $proposed = clone $player->inventory;
            /** @var array<string, array{ApiEquipmentSlot, ?InventoryStack, ?InventoryStack}> $equipmentChanges */
            $equipmentChanges = [];
            $result = $rejectionReason === null
                ? $proposed->applyStackRequest(
                    $command->requestId,
                    $command->actions,
                    $command->authoritativeCreativeStack,
                    createdOutputUnlimited: $command->authoritativeCreativeStack !== null,
                    createdOutputs: $craftingOutputs,
                    allowMainConsumption: $automaticCrafting,
                )
                : new InventoryStackRequestResult(false, reason: $rejectionReason);
            if ($result->success) {
                try {
                    foreach (self::equipmentChanges($before, $proposed) as [$slot, $previous, $next]) {
                        $event = $this->pluginEvents->equipmentChange($player, $slot, $previous, $next);
                        if ($event === null) {
                            $result = new InventoryStackRequestResult(false, reason: 'plugin_cancelled');
                            break;
                        }
                        $replacement = $event->item() === null
                            ? null
                            : $this->inventoryStackFromApi($event->item());
                        self::replaceEquipment($proposed, $slot, $replacement);
                        $equipmentChanges[$slot->value] = [$slot, $previous, $replacement];
                    }
                } catch (InvalidArgumentException|OverflowException) {
                    $result = new InventoryStackRequestResult(false, reason: 'plugin_result');
                }
            }
            if ($result->success && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
                $result = new InventoryStackRequestResult(false, reason: 'plugin_cancelled');
            } elseif ($result->success) {
                $result = $player->inventory->applyStackRequest(
                    $command->requestId,
                    $command->actions,
                    $command->authoritativeCreativeStack,
                    createdOutputUnlimited: $command->authoritativeCreativeStack !== null,
                    createdOutputs: $craftingOutputs,
                    allowMainConsumption: $automaticCrafting,
                );
                if ($result->success) {
                    foreach ($equipmentChanges as [$slot, $previous, $replacement]) {
                        self::replaceEquipment($player->inventory, $slot, $replacement);
                        $this->pluginEvents->equipmentChanged($player, $slot, $previous, $replacement);
                    }
                    $this->pluginEvents->inventoryChanged($player, $before);
                    if ($craftingRecipe !== null && $craftingGrid !== null && $command->crafting !== null) {
                        $this->pluginEvents->crafted(
                            $player,
                            $craftingRecipe,
                            self::apiCraftingGrid($craftingGrid),
                            $command->crafting->repetitions,
                            array_map(self::apiInventoryStack(...), $craftingConsumed),
                            $craftingEventOutputs,
                        );
                    }
                }
            }
        }
        if ($result->success && !$predictionOnly) {
            $player->markDirty();
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            $command->requestId,
            $result->success,
            $result->affectedSlots,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            $result->selectedStackChanged,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            $result->reason,
            $command->responseMode,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function openedContainerStackRequest(
        Player $player,
        ApplyInventoryStackRequest $command,
    ): InventoryStackRequestProcessed {
        $key = self::sessionKey($player->sessionId);
        $session = $this->openContainers[$key] ?? null;
        if (!$session instanceof PlayerContainerSession) {
            return new InventoryStackRequestProcessed(
                $player->sessionId,
                $command->requestId,
                false,
                [],
                $player->inventory->slots(),
                $player->inventory->cursorStack(),
                $player->inventory->selectedHotbarSlot(),
                $player->inventory->selectedStack(),
                false,
                $player->runtimeActorId,
                $this->players->recipients($player->sessionId),
                'container_not_open',
                $command->responseMode,
                armorInventory: $player->inventory->armorSlots(),
                offhandStack: $player->inventory->offhandStack(),
                craftingInventory: $player->inventory->craftingSlots(),
            );
        }
        $workstationRequest = $command->workstation
            ?? ($session->type === ApiContainerType::ENCHANTING_TABLE ? null : $session->workstationRequest);
        if ($session->type->isTransient()) {
            if ($command->rejectionReason === null
                && $command->workstation !== null
                && !($session->type === ApiContainerType::ENCHANTING_TABLE
                    && $command->workstation->type
                        === \Bedriox\Server\Simulation\Command\WorkstationRequestType::ENCHANT)) {
                try {
                    $this->refreshTransientWorkstationPreview($player, $session, $workstationRequest);
                } catch (InvalidArgumentException|OverflowException|ContainerRevisionMismatchException) {
                    $command = new ApplyInventoryStackRequest(
                        $command->session,
                        $command->requestId,
                        $command->actions,
                        'workstation_preview',
                        $command->responseMode,
                        workstation: $command->workstation,
                    );
                }
            }
            $command = $this->normalizeTransientWorkstationRequest($session, $command);
        }
        $reason = $command->rejectionReason;
        $resultSlot = $session->type->resultSlot();
        if ($reason === null && $resultSlot !== null) {
            foreach ($command->actions as $action) {
                if ($action->destination->container === InventoryContainer::OpenedContainer
                    && $action->destination->slot === $resultSlot) {
                    $reason = 'container_result_read_only';
                    break;
                }
            }
        }
        if ($command->authoritativeCreativeStack !== null || $command->crafting !== null) {
            $reason = 'container_mixed_action';
        } elseif (!hash_equals($session->canonicalRevision, $session->inventory->revision())) {
            $reason = 'container_revision';
            $this->refreshContainerProjection($player, $session);
        }
        $beforePlayer = clone $player->inventory;
        $beforeProjection = clone $session->projection;
        $proposedPlayer = clone $player->inventory;
        $proposedProjection = clone $session->projection;
        $workstationSelection = $session->type->isTransient()
            && $command->workstation !== null
            && $command->actions === [];
        $result = $reason === null
            ? ($workstationSelection
                ? new InventoryStackRequestResult(true, [])
                : $proposedPlayer->applyOpenedContainerStackRequest(
                    $command->requestId,
                    $command->actions,
                    $proposedProjection,
                ))
            : new InventoryStackRequestResult(false, reason: $reason);
        $furnaceExtraction = null;
        if ($result->success
            && in_array($session->type, [ApiContainerType::FURNACE, ApiContainerType::BLAST_FURNACE, ApiContainerType::SMOKER], true)
            && $session->position !== null
            && $this->blockWorld !== null) {
            $beforeResult = $beforeProjection->stackAt(FurnaceBlockEntity::SLOT_RESULT);
            $afterResult = $proposedProjection->stackAt(FurnaceBlockEntity::SLOT_RESULT);
            $extractedCount = $beforeResult->count ?? 0;
            if ($beforeResult !== null && $afterResult !== null
                && self::sameInventoryStackType($beforeResult, $afterResult)) {
                $extractedCount -= $afterResult->count;
            }
            if ($beforeResult !== null && $extractedCount > 0) {
                $entity = $this->blockWorld->blockEntityAt($session->position);
                if (!$entity instanceof FurnaceBlockEntity) {
                    $result = new InventoryStackRequestResult(false, reason: 'furnace_missing');
                } else {
                    $experience = intdiv($entity->storedExperienceMilli + 500, 1_000);
                    $extracted = new ApiItemStack(
                        $beforeResult->identifier,
                        $extractedCount,
                        $beforeResult->damage,
                        $beforeResult->nbt,
                        $beforeResult->auxValue,
                    );
                    $apiFurnaceType = match ($entity->furnaceType) {
                        FurnaceType::Furnace => \Bedriox\Api\Processing\FurnaceType::FURNACE,
                        FurnaceType::BlastFurnace => \Bedriox\Api\Processing\FurnaceType::BLAST_FURNACE,
                        FurnaceType::Smoker => \Bedriox\Api\Processing\FurnaceType::SMOKER,
                    };
                    $extractEvent = $this->pluginEvents?->furnaceExtract(
                        $this->pluginEvents->playerView($player),
                        new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
                        $apiFurnaceType,
                        $extracted,
                        $experience,
                    );
                    if ($this->pluginEvents !== null && $extractEvent === null) {
                        $result = new InventoryStackRequestResult(false, reason: 'plugin_cancelled');
                    } else {
                        $furnaceExtraction = [
                            'entityRevision' => $entity->revision,
                            'experience' => $extractEvent?->experience() ?? $experience,
                            'result' => $extracted,
                            'type' => $apiFurnaceType,
                        ];
                    }
                }
            }
        }
        $committedWorkstationResult = null;
        $workstationExperiencePrevious = null;
        $workstationExperienceTarget = null;
        $workstationExperienceCause = null;
        if ($result->success && $session->type->isTransient()) {
            try {
                $workstationResult = $this->stageTransientWorkstation(
                    $player,
                    $session,
                    $command,
                    $beforeProjection,
                    $proposedPlayer,
                    $proposedProjection,
                    $workstationRequest,
                );
                if ($workstationResult === false) {
                    $result = new InventoryStackRequestResult(false, reason: 'workstation_result');
                } else {
                    $committedWorkstationResult = $workstationResult;
                }
            } catch (InvalidArgumentException|OverflowException) {
                $result = new InventoryStackRequestResult(false, reason: 'workstation_result');
            }
            if ($result->success) {
                $affected = [];
                foreach ($result->affectedSlots as $reference) {
                    $affected[$reference->responseKey()] = $reference;
                }
                if ($workstationSelection && $command->workstation->responseSlots !== []) {
                    foreach ($command->workstation->responseSlots as $reference) {
                        $affected[$reference->responseKey()] = $reference;
                    }
                } else {
                    foreach ($proposedProjection->slots() as $slot => $stack) {
                        if (self::sameInventoryStack($beforeProjection->stackAt($slot), $stack)) {
                            continue;
                        }
                        $reference = new InventorySlotReference(InventoryContainer::OpenedContainer, $slot, 0);
                        $affected[$reference->responseKey()] = $reference;
                    }
                }
                $result = new InventoryStackRequestResult(
                    true,
                    array_values($affected),
                    selectedStackChanged: $result->selectedStackChanged,
                );
            }
        }
        if ($result->success && $committedWorkstationResult instanceof WorkstationResult
            && $committedWorkstationResult->experienceLevelCost > 0
            && $player->gameMode()->consumesItems()) {
            $workstationExperiencePrevious = $player->experience->snapshot();
            $targetLevel = $workstationExperiencePrevious->level - $committedWorkstationResult->experienceLevelCost;
            if ($targetLevel < 0) {
                $result = new InventoryStackRequestResult(false, reason: 'workstation_experience');
            } else {
                $targetPoints = \Bedriox\Server\Player\ExperienceMath::totalPointsToReachLevel($targetLevel)
                    + (int) floor(
                        $workstationExperiencePrevious->progress
                        * \Bedriox\Server\Player\ExperienceMath::pointsToCompleteLevel($targetLevel),
                    );
                $workstationExperienceCause = $session->type === ApiContainerType::ENCHANTING_TABLE
                    ? \Bedriox\Api\Player\ExperienceChangeCause::ENCHANTING
                    : \Bedriox\Api\Player\ExperienceChangeCause::ANVIL;
                $workstationExperienceTarget = $this->pluginEvents === null
                    ? new \Bedriox\Api\Player\ExperienceSnapshot($targetPoints)
                    : $this->pluginEvents->experienceChange($player, $targetPoints, $workstationExperienceCause);
                if ($workstationExperienceTarget === null) {
                    $result = new InventoryStackRequestResult(false, reason: 'workstation_experience_cancelled');
                }
            }
        }
        if ($result->success
            && $session->type === ApiContainerType::SHULKER_BOX
            && self::requestPlacesShulkerInOpenedContainer($command->actions, $beforePlayer, $beforeProjection)) {
            $result = new InventoryStackRequestResult(false, reason: 'shulker_nesting');
        }
        $transaction = null;
        if ($result->success) {
            try {
                $transaction = $this->containerTransactionView(
                    $player,
                    $command,
                    $beforePlayer,
                    $beforeProjection,
                    $proposedPlayer,
                    $proposedProjection,
                    $session->inventory,
                );
                if ($this->pluginEvents !== null
                    && !$this->pluginEvents->allowContainerTransaction($player, $transaction)) {
                    $result = new InventoryStackRequestResult(false, reason: 'plugin_cancelled');
                }
            } catch (InvalidArgumentException|OverflowException) {
                $result = new InventoryStackRequestResult(false, reason: 'plugin_result');
            }
        }
        if ($result->success) {
            try {
                if (!hash_equals($session->canonicalRevision, $session->inventory->revision())) {
                    throw new ContainerRevisionMismatchException();
                }
                if (($this->openContainers[self::sessionKey($player->sessionId)] ?? null) !== $session) {
                    throw new ContainerRevisionMismatchException();
                }
                if (!$player->inventory->matchesState($beforePlayer)) {
                    throw new ContainerRevisionMismatchException();
                }
                if ($session->playerOwnedEnderChest) {
                    $this->stageEnderChestContents($proposedPlayer, $proposedProjection);
                }
                $playerInventoryChanged = $beforePlayer->exportState() != $proposedPlayer->exportState();
                $this->commitContainerContents($session, array_map(
                    static fn(?InventoryStack $stack): ?ApiItemStack => $stack === null
                        ? null
                        : self::apiInventoryStack($stack),
                    $proposedProjection->slots(),
                ));
                if (!$player->inventory->commitStagedState($beforePlayer, $proposedPlayer)) {
                    throw new ContainerRevisionMismatchException();
                }
                $session->projection->commit(
                    $proposedProjection->indexedStacks(),
                    $proposedProjection->lastRequestIds(),
                );
                $session->canonicalRevision = $session->inventory->revision();
                if (is_array($furnaceExtraction) && $session->position !== null && $this->blockWorld !== null) {
                    $furnace = $this->blockWorld->blockEntityAt($session->position);
                    if (!$furnace instanceof FurnaceBlockEntity
                        || $furnace->revision < $furnaceExtraction['entityRevision']) {
                        throw new ContainerRevisionMismatchException();
                    }
                    $cleared = $furnace->withState(
                        $furnace->inventory,
                        $furnace->burnTime,
                        $furnace->burnDuration,
                        $furnace->cookTime,
                        0,
                    );
                    $this->blockWorld->setBlockEntity($cleared);
                    $this->worldContainers?->synchronizeFurnace($cleared);
                    if ($furnaceExtraction['experience'] > 0) {
                        array_push($this->deferredEvents, ...$this->spawnExperienceOrbs(
                            $furnaceExtraction['experience'],
                            new Position(
                                $session->position->x + 0.5,
                                $session->position->y + 1.0,
                                $session->position->z + 0.5,
                            ),
                        ));
                    }
                    $this->pluginEvents?->furnaceExtracted(
                        $this->pluginEvents->playerView($player),
                        new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
                        $furnaceExtraction['type'],
                        $furnaceExtraction['result'],
                        $furnaceExtraction['experience'],
                    );
                }
                if ($session->type->isTransient()) {
                    $session->workstationRequest = $workstationRequest;
                }
                $player->markDirty();
                if ($playerInventoryChanged) {
                    $this->pluginEvents?->inventoryChanged($player, $beforePlayer);
                }
                if ($transaction !== null) {
                    $this->pluginEvents?->containerTransactionCommitted($player, $transaction);
                }
                if ($committedWorkstationResult instanceof WorkstationResult) {
                    if ($workstationExperienceTarget instanceof \Bedriox\Api\Player\ExperienceSnapshot
                        && $workstationExperiencePrevious instanceof \Bedriox\Api\Player\ExperienceSnapshot
                        && $workstationExperienceCause instanceof \Bedriox\Api\Player\ExperienceChangeCause) {
                        $player->experience->setTotalPoints($workstationExperienceTarget->totalPoints);
                        $this->pluginEvents?->experienceChanged(
                            $player,
                            $workstationExperiencePrevious,
                            $workstationExperienceCause,
                        );
                        $this->deferredEvents[] = new PlayerExperienceChanged(
                            $player->snapshot(),
                            $workstationExperiencePrevious,
                            $workstationExperienceCause,
                        );
                    }
                    $this->publishWorkstationProcessed(
                        $player,
                        $session,
                        $beforeProjection,
                        $committedWorkstationResult,
                        $workstationRequest,
                    );
                    if ($committedWorkstationResult->experience > 0 && $session->position !== null) {
                        array_push($this->deferredEvents, ...$this->spawnExperienceOrbs(
                            $committedWorkstationResult->experience,
                            new Position(
                                $session->position->x + 0.5,
                                $session->position->y + 1.0,
                                $session->position->z + 0.5,
                            ),
                        ));
                    }
                }
                if ($session->type === ApiContainerType::ENCHANTING_TABLE) {
                    $this->deferEnchantingOptions($player, $session);
                }
                $this->deferContainerViewerSync($player, $session, $result->affectedSlots);
            } catch (InvalidArgumentException|OverflowException|ContainerRevisionMismatchException) {
                $result = new InventoryStackRequestResult(false, reason: 'container_commit');
                $this->refreshContainerProjection($player, $session);
            }
        }

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            $command->requestId,
            $result->success,
            $result->affectedSlots,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            $result->selectedStackChanged,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            $result->reason,
            $command->responseMode,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
            openedContainerInventory: $session->projection->slots(),
            openedContainerWindowId: $session->windowId,
        );
    }

    private function normalizeTransientWorkstationRequest(
        PlayerContainerSession $session,
        ApplyInventoryStackRequest $command,
    ): ApplyInventoryStackRequest {
        $resultSlot = $session->type->resultSlot();
        if ($resultSlot === null) {
            if ($session->type === ApiContainerType::ENCHANTING_TABLE) {
                return $command->workstation?->type === \Bedriox\Server\Simulation\Command\WorkstationRequestType::ENCHANT
                    ? new ApplyInventoryStackRequest(
                        $command->session,
                        $command->requestId,
                        [],
                        $command->rejectionReason,
                        $command->responseMode,
                        workstation: $command->workstation,
                    )
                    : $command;
            }
            return new ApplyInventoryStackRequest(
                $command->session,
                $command->requestId,
                [],
                'workstation_layout',
                $command->responseMode,
                workstation: $command->workstation,
            );
        }
        $actions = [];
        foreach ($command->actions as $action) {
            if ($action->type === InventoryStackRequestActionType::Consume
                || $action->type === InventoryStackRequestActionType::SelectCraftingResult) {
                continue;
            }
            $source = $action->source;
            $destination = $action->destination;
            if ($source->container === InventoryContainer::CreatedOutput) {
                $preview = $session->projection->stackAt($resultSlot);
                if ($preview === null) {
                    return new ApplyInventoryStackRequest(
                        $command->session,
                        $command->requestId,
                        [],
                        'workstation_result',
                        $command->responseMode,
                        workstation: $command->workstation,
                    );
                }
                $source = new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    $resultSlot,
                    $preview->stackNetworkId,
                    $source->responseContainerId,
                    responseSlot: $source->responseSlot,
                    responseContainerDynamicId: $source->responseContainerDynamicId,
                );
            }
            if ($destination->container === InventoryContainer::CreatedOutput) {
                $preview = $session->projection->stackAt($resultSlot);
                if ($preview === null) {
                    return new ApplyInventoryStackRequest(
                        $command->session,
                        $command->requestId,
                        [],
                        'workstation_result',
                        $command->responseMode,
                        workstation: $command->workstation,
                    );
                }
                $destination = new InventorySlotReference(
                    InventoryContainer::OpenedContainer,
                    $resultSlot,
                    $preview->stackNetworkId,
                    $destination->responseContainerId,
                    responseSlot: $destination->responseSlot,
                    responseContainerDynamicId: $destination->responseContainerDynamicId,
                );
            }
            $actions[] = new InventoryStackRequestAction($action->type, $source, $destination, $action->count);
        }

        return new ApplyInventoryStackRequest(
            $command->session,
            $command->requestId,
            $actions,
            $command->rejectionReason,
            $command->responseMode,
            $command->authoritativeCreativeStack,
            $command->crafting,
            $command->workstation,
        );
    }

    private function refreshTransientWorkstationPreview(
        Player $player,
        PlayerContainerSession $session,
        ?WorkstationRequest $request,
    ): void {
        if ($this->transientWorkstations === null) {
            throw new InvalidArgumentException('Transient workstation processing is unavailable.');
        }
        if ($session->type === ApiContainerType::ENCHANTING_TABLE) {
            $this->deferEnchantingOptions($player, $session);
            $session->workstationRequest = $request;
            return;
        }
        $resultSlot = $session->type->resultSlot();
        if ($resultSlot === null) {
            throw new InvalidArgumentException('Transient workstation has no result slot.');
        }
        $context = $this->workstationContext($player, $session, $session->projection, $request);
        $result = $this->transientWorkstations->evaluate(
            $session->type,
            $this->containerItems($session->projection->slots(), $resultSlot),
            $context,
        );
        $this->replaceWorkstationPreview($player->inventory, $session->projection, $resultSlot, $result?->outputs[0] ?? null);
        $this->commitContainerContents($session, array_map(
            static fn(?InventoryStack $stack): ?ApiItemStack => $stack === null
                ? null : self::apiInventoryStack($stack),
            $session->projection->slots(),
        ));
        $session->canonicalRevision = $session->inventory->revision();
        $session->workstationRequest = $request;
    }

    /** Returns false when an attempted result extraction is not backed by an authoritative proposal. */
    private function stageTransientWorkstation(
        Player $player,
        PlayerContainerSession $session,
        ApplyInventoryStackRequest $command,
        \Bedriox\Server\Player\OpenedContainerInventory $before,
        PlayerInventory $proposedPlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $proposed,
        ?WorkstationRequest $request,
    ): WorkstationResult|false|null {
        if ($this->transientWorkstations === null) {
            return false;
        }
        if ($session->type === ApiContainerType::ENCHANTING_TABLE) {
            if ($request?->type !== \Bedriox\Server\Simulation\Command\WorkstationRequestType::ENCHANT) {
                return null;
            }
            $option = $session->enchantingOptions[$request->recipeNetworkId] ?? null;
            $input = $before->stackAt(0);
            $lapis = $before->stackAt(1);
            if (!$option instanceof \Bedriox\Api\Processing\EnchantingOption
                || !$input instanceof InventoryStack
                || !$lapis instanceof InventoryStack) {
                return false;
            }
            $processed = $this->transientWorkstations->enchanting()->apply(
                new ContainerItemStack($input->identifier, 1, $input->damage, $input->nbt, $input->auxValue),
                $option,
                $player->experience->snapshot()->level,
                $lapis->count,
            );
            if ($processed === null) {
                return false;
            }
            $authoritative = new WorkstationResult(
                [0 => 1, 1 => $processed->lapisCost],
                [$processed->item],
                experienceLevelCost: $processed->experienceLevelCost,
                lapisCost: $processed->lapisCost,
            );
            if (!$this->allowWorkstationProcess($player, $session, $before, $authoritative, $request)) {
                return false;
            }
            $indexed = $proposed->indexedStacks();
            $proposedInput = $indexed[0] ?? null;
            $proposedLapis = $indexed[1] ?? null;
            if (!$proposedInput instanceof InventoryStack || !$proposedLapis instanceof InventoryStack
                || $proposedLapis->count < $authoritative->lapisCost) {
                return false;
            }
            $output = $proposedPlayer->projectOpenedContainer(
                $proposed->identifier . '/enchant',
                [$this->inventoryStackFromContainerItem($authoritative->outputs[0])],
            )->stackAt(0);
            if ($output === null) {
                return false;
            }
            $indexed[0] = $output;
            if ($proposedLapis->count === $authoritative->lapisCost) {
                unset($indexed[1]);
            } else {
                $indexed[1] = $proposedLapis->withCountAndNetworkId(
                    $proposedLapis->count - $authoritative->lapisCost,
                    $proposedLapis->stackNetworkId,
                );
            }
            $proposed->commit($indexed, $proposed->lastRequestIds());

            return $authoritative;
        }
        $resultSlot = $session->type->resultSlot();
        if ($resultSlot === null) {
            return false;
        }
        $takingResult = false;
        $takenCount = 0;
        foreach ($command->actions as $action) {
            if ($action->source->container === InventoryContainer::OpenedContainer
                && $action->source->slot === $resultSlot) {
                $takingResult = true;
                $takenCount += $action->count;
            }
        }
        $context = $this->workstationContext($player, $session, $before, $request);
        $authoritative = $this->transientWorkstations->evaluate(
            $session->type,
            $this->containerItems($before->slots(), $resultSlot),
            $context,
        );
        if ($takingResult) {
            $preview = $before->stackAt($resultSlot);
            $output = $authoritative?->outputs[0] ?? null;
            if ($preview === null || $output === null || $takenCount !== $output->count
                || !self::sameInventoryStack($preview, $this->inventoryStackFromContainerItem($output))) {
                return false;
            }
            if (!$this->allowWorkstationProcess($player, $session, $before, $authoritative, $request)) {
                return false;
            }
            $indexed = $proposed->indexedStacks();
            foreach ($authoritative->consumedBySlot as $slot => $count) {
                $input = $indexed[$slot] ?? null;
                if (!$input instanceof InventoryStack || $input->count < $count) {
                    return false;
                }
                if ($input->count === $count) {
                    unset($indexed[$slot]);
                } else {
                    $indexed[$slot] = $input->withCountAndNetworkId($input->count - $count, $input->stackNetworkId);
                }
            }
            $proposed->commit($indexed, $proposed->lastRequestIds());
        }
        $nextContext = $this->workstationContext($player, $session, $proposed, $request);
        $next = $this->transientWorkstations->evaluate(
            $session->type,
            $this->containerItems($proposed->slots(), $resultSlot),
            $nextContext,
        );
        $this->replaceWorkstationPreview($proposedPlayer, $proposed, $resultSlot, $next?->outputs[0] ?? null);

        return $takingResult ? $authoritative : null;
    }

    private function workstationContext(
        Player $player,
        PlayerContainerSession $session,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        ?WorkstationRequest $request,
    ): \Bedriox\Server\Gameplay\Processing\WorkstationEvaluationContext {
        $first = $inventory->stackAt(0);
        $addition = $inventory->stackAt(1);
        $operation = match ($addition?->identifier) {
            'minecraft:empty_map' => CartographyOperation::CLONE,
            'minecraft:paper' => CartographyOperation::SCALE,
            'minecraft:glass_pane' => CartographyOperation::LOCK,
            default => ($request?->filteredText !== null ? CartographyOperation::RENAME : null),
        };
        $networkChoice = $request?->recipeNetworkId;
        $recipeSourceIndex = $networkChoice === null
            ? null
            : $this->craftingCatalog?->workstationSourceIndex($networkChoice);
        return new \Bedriox\Server\Gameplay\Processing\WorkstationEvaluationContext(
            recipeSourceIndex: $recipeSourceIndex,
            name: $request?->filteredText,
            maximumDurability: $first === null || $this->itemCatalog === null
                ? 0 : ($this->itemCatalog->type($first->identifier)->durability() ?? 0),
            enchantingOption: $networkChoice !== null && $networkChoice >= 1 && $networkChoice <= 3
                ? $networkChoice - 1 : 0,
            bookshelves: $session->position === null ? 0 : $this->countEnchantingBookshelves($session->position),
            enchantmentSeed: (int) (hexdec(substr(hash('sha256', strtolower($player->identity->uuid)), 0, 7))),
            playerLevel: $player->experience->snapshot()->level,
            availableLapis: $addition === null ? 0 : $addition->count,
            loomPattern: $request?->patternId,
            cartographyOperation: $session->type === ApiContainerType::CARTOGRAPHY_TABLE ? $operation : null,
        );
    }

    private function countEnchantingBookshelves(BlockPosition $table): int
    {
        if ($this->blockWorld === null || $this->blockPalette === null) {
            return 0;
        }
        $count = 0;
        for ($x = -2; $x <= 2; ++$x) {
            for ($z = -2; $z <= 2; ++$z) {
                if (abs($x) !== 2 && abs($z) !== 2) {
                    continue;
                }
                $spaceX = max(-1, min(1, $x));
                $spaceZ = max(-1, min(1, $z));
                for ($y = 0; $y <= 1; ++$y) {
                    $space = $this->blockWorld->loadedBlockStateAt(
                        $table->x + $spaceX,
                        $table->y + $y,
                        $table->z + $spaceZ,
                    );
                    if ($space === null || $space->value !== $this->blockPalette->air->value) {
                        continue 3;
                    }
                }
                for ($y = 0; $y <= 1; ++$y) {
                    $shelf = $this->blockWorld->loadedBlockStateAt($table->x + $x, $table->y + $y, $table->z + $z);
                    if ($shelf !== null && $this->blockIdentifier($shelf->value) === 'minecraft:bookshelf'
                        && ++$count === 15) {
                        return $count;
                    }
                }
            }
        }

        return $count;
    }

    private function deferEnchantingOptions(Player $player, PlayerContainerSession $session): void
    {
        if ($this->transientWorkstations === null || $session->position === null) {
            return;
        }
        $input = $session->projection->stackAt(0);
        if ($input === null) {
            $session->enchantingOptions = [];
            $this->deferredEvents[] = new EnchantingOptionsUpdated($player->sessionId, []);
            return;
        }
        $item = new ContainerItemStack($input->identifier, 1, $input->damage, $input->nbt, $input->auxValue);
        $context = $this->workstationContext($player, $session, $session->projection, null);
        $options = $this->transientWorkstations->enchanting()->options(
            $item,
            $context->bookshelves,
            $context->enchantmentSeed,
        );
        if ($this->pluginEvents !== null) {
            $event = $this->pluginEvents->enchantingOptions(
                $this->pluginEvents->playerView($player),
                new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
                self::apiInventoryStack($input),
                $options,
            );
            $options = $event?->options() ?? [];
            if ($event !== null) {
                $this->pluginEvents->enchantingOptionsGenerated(
                    $this->pluginEvents->playerView($player),
                    new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
                    self::apiInventoryStack($input),
                    $options,
                );
            }
        }
        $networkOptions = [];
        foreach ($options as $option) {
            if ($session->nextEnchantingOptionNetworkId > 0xffffffff) {
                $session->nextEnchantingOptionNetworkId = 1;
            }
            $networkOptions[$session->nextEnchantingOptionNetworkId++] = $option;
        }
        $session->enchantingOptions = $networkOptions;
        $this->deferredEvents[] = new EnchantingOptionsUpdated($player->sessionId, $networkOptions);
    }

    /**
     * @param list<InventoryStack|null> $slots
     * @return list<ContainerItemStack|null>
     */
    private function containerItems(array $slots, int $resultSlot): array
    {
        $items = [];
        foreach ($slots as $slot => $stack) {
            $items[] = $slot === $resultSlot || $stack === null ? null : new ContainerItemStack(
                $stack->identifier,
                $stack->count,
                $stack->damage,
                $stack->nbt,
                $stack->auxValue,
            );
        }
        return $items;
    }

    private function replaceWorkstationPreview(
        PlayerInventory $player,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        int $resultSlot,
        ?ContainerItemStack $output,
    ): void {
        $indexed = $inventory->indexedStacks();
        unset($indexed[$resultSlot]);
        if ($output !== null) {
            $projected = $player->projectOpenedContainer(
                $inventory->identifier . '/result',
                [$this->inventoryStackFromContainerItem($output)],
            )->stackAt(0);
            if ($projected !== null) {
                $indexed[$resultSlot] = $projected;
            }
        }
        $inventory->commit($indexed, $inventory->lastRequestIds());
    }

    private function allowWorkstationProcess(
        Player $player,
        PlayerContainerSession $session,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        WorkstationResult $result,
        ?WorkstationRequest $request,
    ): bool {
        if ($this->pluginEvents === null || $session->position === null) {
            return true;
        }
        $apiPlayer = $this->pluginEvents->playerView($player);
        $position = new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z);
        $inputs = $this->apiWorkstationInputs($inventory, $session->type->resultSlot());
        $output = self::apiInventoryStack($this->inventoryStackFromContainerItem($result->outputs[0]));
        $stoneRecipe = $request?->recipeNetworkId;
        $pattern = $request?->patternId;
        return match ($session->type) {
            ApiContainerType::STONECUTTER => isset($inputs[0]) && $stoneRecipe !== null && $this->pluginEvents->stonecutterProcess(
                $apiPlayer,
                $position,
                $inputs[0],
                $output,
                'minecraft:stonecutter/' . ($this->craftingCatalog?->workstationSourceIndex($stoneRecipe) ?? 0),
            ) !== null,
            ApiContainerType::SMITHING_TABLE => isset($inputs[0], $inputs[1], $inputs[2])
                && $this->pluginEvents->smithingProcess(
                    $apiPlayer,
                    $position,
                    $output->identifier === $inputs[1]->identifier
                        ? SmithingRecipeType::TRIM : SmithingRecipeType::TRANSFORM,
                    $inputs[0],
                    $inputs[1],
                    $inputs[2],
                    $output,
                ) !== null,
            ApiContainerType::ANVIL => isset($inputs[0]) && $this->pluginEvents->anvilProcess(
                $apiPlayer,
                $position,
                $inputs[0],
                $inputs[1] ?? null,
                $output,
                $result->experienceLevelCost,
                $request->filteredText ?? '',
            ) !== null,
            ApiContainerType::GRINDSTONE => isset($inputs[0]) && $this->pluginEvents->grindstoneProcess(
                $apiPlayer,
                $position,
                $inputs[0],
                $inputs[1] ?? null,
                $output,
                $result->experience,
            ) !== null,
            ApiContainerType::ENCHANTING_TABLE => isset($inputs[0])
                && $this->allowEnchantingProcess($player, $session, $inventory, $inputs[0], $output, $request),
            ApiContainerType::LOOM => isset($inputs[0], $inputs[1]) && $pattern !== null && $this->pluginEvents->loomProcess(
                $apiPlayer,
                $position,
                $inputs[0],
                $inputs[1],
                $inputs[2] ?? null,
                $output,
                $pattern,
            ) !== null,
            ApiContainerType::CARTOGRAPHY_TABLE => isset($inputs[0]) && $this->pluginEvents->cartographyProcess(
                $apiPlayer,
                $position,
                $this->cartographyOperation($inventory, $request) ?? CartographyOperation::RENAME,
                $inputs[0],
                $inputs[1] ?? null,
                $output,
            ) !== null,
            default => false,
        };
    }

    private function publishWorkstationProcessed(
        Player $player,
        PlayerContainerSession $session,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        WorkstationResult $result,
        ?WorkstationRequest $request,
    ): void {
        if ($this->pluginEvents === null || $session->position === null) {
            return;
        }
        $apiPlayer = $this->pluginEvents->playerView($player);
        $position = new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z);
        $inputs = $this->apiWorkstationInputs($inventory, $session->type->resultSlot());
        $output = self::apiInventoryStack($this->inventoryStackFromContainerItem($result->outputs[0]));
        $stoneRecipe = $request?->recipeNetworkId;
        $pattern = $request?->patternId;
        switch ($session->type) {
            case ApiContainerType::STONECUTTER:
                if (isset($inputs[0]) && $stoneRecipe !== null) {
                    $this->pluginEvents->stonecutterProcessed($apiPlayer, $position, $inputs[0], $output, 'minecraft:stonecutter/' . ($this->craftingCatalog?->workstationSourceIndex($stoneRecipe) ?? 0));
                }
                break;
            case ApiContainerType::SMITHING_TABLE:
                if (isset($inputs[0], $inputs[1], $inputs[2])) {
                    $this->pluginEvents->smithingProcessed($apiPlayer, $position, $output->identifier === $inputs[1]->identifier ? SmithingRecipeType::TRIM : SmithingRecipeType::TRANSFORM, $inputs[0], $inputs[1], $inputs[2], $output);
                }
                break;
            case ApiContainerType::ANVIL:
                if (isset($inputs[0])) {
                    $this->pluginEvents->anvilProcessed($apiPlayer, $position, $inputs[0], $inputs[1] ?? null, $output, $result->experienceLevelCost, $request->filteredText ?? '');
                }
                break;
            case ApiContainerType::GRINDSTONE:
                if (isset($inputs[0])) {
                    $this->pluginEvents->grindstoneProcessed($apiPlayer, $position, $inputs[0], $inputs[1] ?? null, $output, $result->experience);
                }
                break;
            case ApiContainerType::ENCHANTING_TABLE:
                $this->publishEnchantingProcessed($player, $session, $inventory, $inputs[0] ?? null, $output, $request);
                break;
            case ApiContainerType::LOOM:
                if (isset($inputs[0], $inputs[1]) && $pattern !== null) {
                    $this->pluginEvents->loomProcessed($apiPlayer, $position, $inputs[0], $inputs[1], $inputs[2] ?? null, $output, $pattern);
                }
                break;
            case ApiContainerType::CARTOGRAPHY_TABLE:
                if (isset($inputs[0])) {
                    $this->pluginEvents->cartographyProcessed($apiPlayer, $position, $this->cartographyOperation($inventory, $request) ?? CartographyOperation::RENAME, $inputs[0], $inputs[1] ?? null, $output);
                }
                break;
            default:
                break;
        }
    }

    /** @return array<int, ApiItemStack> */
    private function apiWorkstationInputs(
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        ?int $resultSlot,
    ): array {
        $inputs = [];
        foreach ($inventory->slots() as $slot => $stack) {
            if ($slot !== $resultSlot && $stack !== null) {
                $inputs[$slot] = self::apiInventoryStack($stack);
            }
        }
        return $inputs;
    }

    private function cartographyOperation(
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        ?WorkstationRequest $request,
    ): ?CartographyOperation {
        return match ($inventory->stackAt(1)?->identifier) {
            'minecraft:empty_map' => CartographyOperation::CLONE,
            'minecraft:paper' => CartographyOperation::SCALE,
            'minecraft:glass_pane' => CartographyOperation::LOCK,
            default => $request?->filteredText !== null ? CartographyOperation::RENAME : null,
        };
    }

    private function allowEnchantingProcess(
        Player $player,
        PlayerContainerSession $session,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        ApiItemStack $input,
        ApiItemStack $output,
        ?WorkstationRequest $request,
    ): bool {
        if ($this->pluginEvents === null || $session->position === null || $this->transientWorkstations === null) {
            return true;
        }
        $option = $request === null ? null : ($session->enchantingOptions[$request->recipeNetworkId] ?? null);
        return $option !== null && $this->pluginEvents->playerEnchantItem(
            $this->pluginEvents->playerView($player),
            new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
            $input,
            $output,
            $option,
        ) !== null;
    }

    private function publishEnchantingProcessed(
        Player $player,
        PlayerContainerSession $session,
        \Bedriox\Server\Player\OpenedContainerInventory $inventory,
        ?ApiItemStack $input,
        ApiItemStack $output,
        ?WorkstationRequest $request,
    ): bool {
        if ($input === null || $this->pluginEvents === null || $session->position === null || $this->transientWorkstations === null) {
            return false;
        }
        $option = $request === null ? null : ($session->enchantingOptions[$request->recipeNetworkId] ?? null);
        if ($option === null) {
            return false;
        }
        $this->pluginEvents->playerEnchantedItem(
            $this->pluginEvents->playerView($player),
            new ApiBlockPosition($session->position->x, $session->position->y, $session->position->z),
            $input,
            $output,
            $option,
        );
        return true;
    }

    private function refreshContainerProjection(Player $player, PlayerContainerSession $session): void
    {
        $session->projection = $player->inventory->refreshOpenedContainer(
            $session->projection,
            array_map(
                fn(?ApiItemStack $stack): ?InventoryStack => $stack === null
                    ? null
                    : $this->inventoryStackFromApi($stack),
                $session->inventory->contents(),
            ),
        );
        $session->canonicalRevision = $session->inventory->revision();
    }

    private function containerTransactionView(
        Player $player,
        ApplyInventoryStackRequest $command,
        PlayerInventory $beforePlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $beforeOpened,
        PlayerInventory $afterPlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $afterOpened,
        LiveContainerInventory $container,
    ): ApiInventoryTransaction {
        $before = $this->containerTransactionInventoryViews($player, $beforePlayer, $beforeOpened, $container);
        $after = $this->containerTransactionInventoryViews($player, $afterPlayer, $afterOpened, $container);
        $actions = [];
        $seen = [];
        foreach ($command->actions as $action) {
            $type = self::containerTransactionActionType($action, $beforePlayer, $beforeOpened, $afterPlayer, $afterOpened);
            foreach ([$action->source, $action->destination] as $reference) {
                [$inventoryIdentifier, $slot, $previous] = self::containerTransactionSlot(
                    $player,
                    $beforePlayer,
                    $beforeOpened,
                    $container,
                    $reference,
                );
                [, , $next] = self::containerTransactionSlot(
                    $player,
                    $afterPlayer,
                    $afterOpened,
                    $container,
                    $reference,
                );
                $key = $inventoryIdentifier . ':' . $slot;
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;
                $actions[] = new ApiInventoryTransactionAction(
                    $type,
                    $inventoryIdentifier,
                    $slot,
                    $previous === null ? null : self::apiInventoryStack($previous),
                    $next === null ? null : self::apiInventoryStack($next),
                );
            }
        }
        if ($actions === []) {
            $actions = self::containerTransactionSlotChanges(
                $player,
                $beforePlayer,
                $beforeOpened,
                $afterPlayer,
                $afterOpened,
                $container,
            );
        }

        return new ApiInventoryTransaction(
            'container:' . $command->requestId . ':' . strtolower($player->identity->uuid),
            ApiInventoryTransactionCause::PLAYER,
            $before,
            $after,
            $actions,
        );
    }

    /** @return list<ApiInventoryTransactionAction> */
    private static function containerTransactionSlotChanges(
        Player $player,
        PlayerInventory $beforePlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $beforeOpened,
        PlayerInventory $afterPlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $afterOpened,
        LiveContainerInventory $container,
    ): array {
        $prefix = 'player/' . strtolower($player->identity->uuid);
        $inventories = [
            [$prefix . '/main', $beforePlayer->slots(), $afterPlayer->slots()],
            [$prefix . '/cursor', [$beforePlayer->cursorStack()], [$afterPlayer->cursorStack()]],
            [$prefix . '/armor', $beforePlayer->armorSlots(), $afterPlayer->armorSlots()],
            [$prefix . '/offhand', [$beforePlayer->offhandStack()], [$afterPlayer->offhandStack()]],
            [self::containerInventoryIdentifier($container), $beforeOpened->slots(), $afterOpened->slots()],
        ];
        $actions = [];
        foreach ($inventories as [$identifier, $before, $after]) {
            foreach ($before as $slot => $previous) {
                $next = $after[$slot];
                if (self::sameInventoryStack($previous, $next)) {
                    continue;
                }
                $actions[] = new ApiInventoryTransactionAction(
                    ApiInventoryActionType::SLOT_CHANGE,
                    $identifier,
                    $slot,
                    $previous === null ? null : self::apiInventoryStack($previous),
                    $next === null ? null : self::apiInventoryStack($next),
                );
            }
        }

        return $actions;
    }

    private function containerDropTransactionView(
        Player $player,
        DropItem $command,
        PlayerInventory $beforePlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $beforeOpened,
        PlayerInventory $afterPlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $afterOpened,
        LiveContainerInventory $container,
    ): ApiInventoryTransaction {
        [, $slot, $previous] = self::containerTransactionSlot(
            $player,
            $beforePlayer,
            $beforeOpened,
            $container,
            $command->source,
        );
        [$identifier, , $next] = self::containerTransactionSlot(
            $player,
            $afterPlayer,
            $afterOpened,
            $container,
            $command->source,
        );

        return new ApiInventoryTransaction(
            'container-drop:' . $command->requestId . ':' . strtolower($player->identity->uuid),
            ApiInventoryTransactionCause::PLAYER,
            $this->containerTransactionInventoryViews($player, $beforePlayer, $beforeOpened, $container),
            $this->containerTransactionInventoryViews($player, $afterPlayer, $afterOpened, $container),
            [new ApiInventoryTransactionAction(
                ApiInventoryActionType::DROP,
                $identifier,
                $slot,
                $previous === null ? null : self::apiInventoryStack($previous),
                $next === null ? null : self::apiInventoryStack($next),
            )],
        );
    }

    /**
     * @return list<ApiInventoryView>
     */
    private function containerTransactionInventoryViews(
        Player $player,
        PlayerInventory $inventory,
        \Bedriox\Server\Player\OpenedContainerInventory $opened,
        LiveContainerInventory $container,
    ): array {
        $prefix = 'player/' . strtolower($player->identity->uuid);

        return [
            self::transactionInventoryView($prefix . '/main', $inventory->slots()),
            self::transactionInventoryView($prefix . '/cursor', [$inventory->cursorStack()]),
            self::transactionInventoryView($prefix . '/armor', $inventory->armorSlots()),
            self::transactionInventoryView($prefix . '/offhand', [$inventory->offhandStack()]),
            self::transactionInventoryView($container->identifier(), $opened->slots()),
        ];
    }

    /** @param list<InventoryStack|null> $slots */
    private static function transactionInventoryView(string $identifier, array $slots): ApiInventoryView
    {
        if ($identifier === '') {
            throw new InvalidArgumentException('Transaction inventory identifier must not be empty.');
        }
        $apiSlots = array_map(
            static fn(?InventoryStack $stack): ?ApiItemStack => $stack === null
                ? null
                : self::apiInventoryStack($stack),
            $slots,
        );
        $hash = hash_init('sha256');
        foreach ($apiSlots as $slot => $stack) {
            hash_update($hash, pack('V', $slot));
            if ($stack === null) {
                hash_update($hash, "\0");
                continue;
            }
            hash_update($hash, "\1" . $stack->identifier . "\0");
            hash_update($hash, pack('V3', $stack->count, $stack->damage, $stack->auxValue));
            hash_update($hash, $stack->nbt?->toBinary() ?? '');
        }

        return new ApiInventoryView($identifier, $apiSlots, hash_final($hash));
    }

    /** @return array{non-empty-string, int, ?InventoryStack} */
    private static function containerTransactionSlot(
        Player $player,
        PlayerInventory $inventory,
        \Bedriox\Server\Player\OpenedContainerInventory $opened,
        LiveContainerInventory $container,
        InventorySlotReference $reference,
    ): array {
        $prefix = 'player/' . strtolower($player->identity->uuid);
        $containerIdentifier = self::containerInventoryIdentifier($container);

        return match ($reference->container) {
            InventoryContainer::Main => [$prefix . '/main', $reference->slot, $inventory->stackAt($reference->slot)],
            InventoryContainer::Cursor => [$prefix . '/cursor', 0, $inventory->cursorStack()],
            InventoryContainer::Armor => [$prefix . '/armor', $reference->slot, $inventory->armorStack($reference->slot)],
            InventoryContainer::Offhand => [$prefix . '/offhand', 0, $inventory->offhandStack()],
            InventoryContainer::OpenedContainer => [
                $containerIdentifier,
                $reference->slot,
                $opened->stackAt($reference->slot),
            ],
            default => throw new InvalidArgumentException('Unsupported storage-container transaction slot.'),
        };
    }

    /** @return non-empty-string */
    private static function containerInventoryIdentifier(LiveContainerInventory $container): string
    {
        $identifier = $container->identifier();
        if ($identifier === '') {
            throw new InvalidArgumentException('Container inventory identifier must not be empty.');
        }

        return $identifier;
    }

    private static function containerTransactionActionType(
        InventoryStackRequestAction $action,
        PlayerInventory $beforePlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $beforeOpened,
        PlayerInventory $afterPlayer,
        \Bedriox\Server\Player\OpenedContainerInventory $afterOpened,
    ): ApiInventoryActionType {
        if ($action->type === InventoryStackRequestActionType::Swap) {
            return ApiInventoryActionType::SWAP;
        }
        $beforeSource = self::containerTransactionStack($beforePlayer, $beforeOpened, $action->source);
        $afterSource = self::containerTransactionStack($afterPlayer, $afterOpened, $action->source);
        $beforeDestination = self::containerTransactionStack($beforePlayer, $beforeOpened, $action->destination);
        if ($afterSource !== null && ($beforeSource === null || $afterSource->count < $beforeSource->count)) {
            return ApiInventoryActionType::SPLIT;
        }
        if ($beforeDestination !== null) {
            return ApiInventoryActionType::MERGE;
        }

        return ApiInventoryActionType::MOVE;
    }

    private static function containerTransactionStack(
        PlayerInventory $inventory,
        \Bedriox\Server\Player\OpenedContainerInventory $opened,
        InventorySlotReference $reference,
    ): ?InventoryStack {
        return match ($reference->container) {
            InventoryContainer::Main => $inventory->stackAt($reference->slot),
            InventoryContainer::Cursor => $inventory->cursorStack(),
            InventoryContainer::Armor => $inventory->armorStack($reference->slot),
            InventoryContainer::Offhand => $inventory->offhandStack(),
            InventoryContainer::OpenedContainer => $opened->stackAt($reference->slot),
            default => null,
        };
    }

    /**
     * @param list<InventoryStackRequestAction> $actions
     */
    private static function requestPlacesShulkerInOpenedContainer(
        array $actions,
        PlayerInventory $player,
        \Bedriox\Server\Player\OpenedContainerInventory $opened,
    ): bool {
        foreach ($actions as $action) {
            if ($action->destination->container !== InventoryContainer::OpenedContainer) {
                continue;
            }
            $stack = self::containerTransactionStack($player, $opened, $action->source);
            if ($stack !== null && WorldContainerStore::isShulkerBoxIdentifier($stack->identifier)) {
                return true;
            }
        }

        return false;
    }

    private function stageEnderChestContents(
        PlayerInventory $inventory,
        \Bedriox\Server\Player\OpenedContainerInventory $projection,
    ): void {
        if ($projection->size !== PlayerInventory::ENDER_CHEST_SLOT_COUNT) {
            throw new InvalidArgumentException('Ender Chest projection has an invalid size.');
        }
        foreach ($projection->slots() as $slot => $stack) {
            $inventory->replaceEnderChestSlot($slot, $stack);
        }
    }

    /** @param list<ApiItemStack|null> $contents */
    private function commitContainerContents(PlayerContainerSession $session, array $contents): void
    {
        if ($session->worldContainer !== null) {
            if ($this->worldContainers === null) {
                throw new \LogicException('World container storage is unavailable.');
            }
            $this->worldContainers->replaceAndPersist(
                $session->worldContainer,
                $contents,
                $session->canonicalRevision,
            );
            if ($session->worldContainer->type === ApiContainerType::BREWING_STAND) {
                $this->scheduleBrewingStand($session->worldContainer->position);
            }
            if (in_array($session->worldContainer->type, [
                ApiContainerType::FURNACE,
                ApiContainerType::BLAST_FURNACE,
                ApiContainerType::SMOKER,
            ], true)) {
                $this->scheduleFurnace($session->worldContainer->position);
            }

            return;
        }
        $session->inventory->replaceContents($contents, $session->canonicalRevision);
    }

    /** @param list<InventorySlotReference> $affectedSlots */
    private function deferContainerViewerSync(
        Player $owner,
        PlayerContainerSession $session,
        array $affectedSlots,
    ): void {
        $changedSlots = [];
        foreach ($affectedSlots as $reference) {
            if ($reference->container === InventoryContainer::OpenedContainer) {
                $changedSlots[$reference->slot] = $reference->slot;
            }
        }
        if ($changedSlots === []) {
            return;
        }
        $additional = [];
        foreach ($this->openContainers as $key => $otherSession) {
            if ($otherSession === $session
                || $otherSession->inventory->identifier() !== $session->inventory->identifier()) {
                continue;
            }
            $other = $this->players->player(substr($key, strlen('session:')));
            if ($other === null) {
                continue;
            }
            $this->refreshContainerProjection($other, $otherSession);
            $additional[] = new ContainerViewerProjection(
                $other->sessionId,
                $otherSession->windowId,
                $otherSession->projection->slots(),
            );
        }
        $this->deferredEvents[] = new ContainerContentsChanged(
            $owner->sessionId,
            $session->windowId,
            $session->type,
            $session->position,
            $session->projection->slots(),
            $additional === [] ? array_values($changedSlots) : [],
            $additional,
            $session->pairedPosition,
            $session->layout,
        );
    }

    private static function removesBoundArmor(PlayerInventory $before, PlayerInventory $after): bool
    {
        foreach (ArmorSlot::cases() as $slot) {
            $previous = $before->armorStack($slot);
            if ($previous === null
                || EnchantmentEffects::level($previous->nbt, VanillaEnchantments::BINDING) < 1
                || self::sameInventoryStack($previous, $after->armorStack($slot))) {
                continue;
            }

            return true;
        }

        return false;
    }

    /** @return list<array{ApiEquipmentSlot, ?InventoryStack, ?InventoryStack}> */
    private static function equipmentChanges(PlayerInventory $before, PlayerInventory $after): array
    {
        $changes = [];
        foreach (ArmorSlot::cases() as $slot) {
            $previous = $before->armorStack($slot);
            $next = $after->armorStack($slot);
            if (!self::sameInventoryStack($previous, $next)) {
                $changes[] = [self::apiEquipmentSlot($slot), $previous, $next];
            }
        }
        if (!self::sameInventoryStack($before->offhandStack(), $after->offhandStack())) {
            $changes[] = [ApiEquipmentSlot::OFF_HAND, $before->offhandStack(), $after->offhandStack()];
        }

        return $changes;
    }

    private static function replaceEquipment(
        PlayerInventory $inventory,
        ApiEquipmentSlot $slot,
        ?InventoryStack $stack,
    ): void {
        switch ($slot) {
            case ApiEquipmentSlot::HEAD:
                $inventory->replaceArmorSlot(ArmorSlot::Head, $stack);
                break;
            case ApiEquipmentSlot::CHEST:
                $inventory->replaceArmorSlot(ArmorSlot::Chest, $stack);
                break;
            case ApiEquipmentSlot::LEGS:
                $inventory->replaceArmorSlot(ArmorSlot::Legs, $stack);
                break;
            case ApiEquipmentSlot::FEET:
                $inventory->replaceArmorSlot(ArmorSlot::Feet, $stack);
                break;
            case ApiEquipmentSlot::OFF_HAND:
                $inventory->replaceOffhand($stack);
                break;
            case ApiEquipmentSlot::MAIN_HAND:
                throw new InvalidArgumentException('Main-hand replacement is not an equipment-container transaction.');
        }
    }

    private static function sameInventoryStack(?InventoryStack $left, ?InventoryStack $right): bool
    {
        return ($left === null && $right === null)
            || ($left !== null && $right !== null
                && $left->identifier === $right->identifier
                && $left->count === $right->count
                && $left->damage === $right->damage
                && $left->auxValue === $right->auxValue
                && $left->placedBlockState?->value === $right->placedBlockState?->value
                && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? ''));
    }

    private static function sameInventoryStackType(InventoryStack $left, InventoryStack $right): bool
    {
        return $left->identifier === $right->identifier
            && $left->damage === $right->damage
            && $left->auxValue === $right->auxValue
            && $left->placedBlockState?->value === $right->placedBlockState?->value
            && ($left->nbt?->toBinary() ?? '') === ($right->nbt?->toBinary() ?? '');
    }

    /** @return list<InventorySlotReference> */
    private static function changedMainInventorySlots(
        PlayerInventory $before,
        PlayerInventory $after,
    ): array {
        $changes = [];
        for ($slot = 0; $slot < PlayerInventory::SLOT_COUNT; ++$slot) {
            if (!self::sameInventoryStack($before->stackAt($slot), $after->stackAt($slot))) {
                $changes[] = new InventorySlotReference(InventoryContainer::Main, $slot, 0);
            }
        }

        return $changes;
    }

    private function placeBlock(PlaceBlock $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null || $this->blockWorld === null || $this->blockPalette === null) {
            return new CommandRejected($command->session, 'block_world_unavailable');
        }
        $placedPosition = self::adjacentBlock($command->clickedPosition, $command->face);
        if ($placedPosition === null) {
            return new CommandRejected($command->session, 'block_position');
        }
        $clickedState = $this->blockWorld->blockStateAt(
            $command->clickedPosition->x,
            $command->clickedPosition->y,
            $command->clickedPosition->z,
        );
        $clickedIdentifier = $this->blockIdentifier($clickedState->value);
        if (WorldContainerStore::isStorageBlock($clickedIdentifier)
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            if ($command->sequence > $player->placementSequence) {
                $player->placementSequence = $command->sequence;
            }

            return $this->openWorldContainer($player, $command->clickedPosition, $clickedIdentifier);
        }
        if ($clickedIdentifier === 'minecraft:crafting_table'
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            $player->inventory->setCraftingGridWidth(3);
            if ($command->sequence > $player->placementSequence) {
                $player->placementSequence = $command->sequence;
            }

            return new CraftingTableOpened($player->sessionId, $command->clickedPosition);
        }
        $transientWorkstation = TransientWorkstationType::fromBlockIdentifier($clickedIdentifier);
        if ($transientWorkstation !== null
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            if ($command->sequence > $player->placementSequence) {
                $player->placementSequence = $command->sequence;
            }
            $type = $transientWorkstation->containerType();
            $inventory = new SimpleContainerInventory(
                self::transientWorkstationInventoryIdentifier(
                    $player->sessionId,
                    $command->clickedPosition,
                    $type,
                ),
                $transientWorkstation->slotCount(),
            );

            return $this->openContainerInventory(
                $player,
                $type,
                $inventory,
                $command->clickedPosition,
            );
        }
        if (($clickedIdentifier === 'minecraft:campfire' || $clickedIdentifier === 'minecraft:soul_campfire')
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            return $this->useCampfire(
                $player,
                $command,
                $clickedIdentifier,
                $clickedState,
                $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z),
            );
        }
        if ($clickedIdentifier === 'minecraft:composter'
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            return $this->useComposter(
                $player,
                $command,
                $clickedState,
                $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z),
            );
        }
        if ($clickedIdentifier === 'minecraft:cauldron'
            && !$player->movement->sneaking
            && $this->blockIsReachable($player->snapshot(), $command->clickedPosition)) {
            return $this->useCauldron(
                $player,
                $command,
                $clickedState,
                $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z),
            );
        }
        $key = self::sessionKey($command->session);
        $activeBreak = $this->breakingBlocks[$key] ?? null;
        unset($this->breakingBlocks[$key]);
        $held = $player->inventory->selectedStack();
        $heldType = $held !== null && $this->itemCatalog?->has($held->identifier) === true
            ? $this->itemCatalog->type($held->identifier)
            : null;
        $aquaticBucketType = $held === null ? null : AquaticBucketRegistry::typeForBucket($held->identifier);
        $bucketFluid = match ($held?->identifier) {
            'minecraft:water_bucket' => FluidType::WATER,
            'minecraft:lava_bucket' => FluidType::LAVA,
            default => $aquaticBucketType === null ? null : FluidType::WATER,
        };
        $heldPlacesBlock = $heldType?->placedBlockState !== null || $held?->placedBlockState !== null;
        if ($bucketFluid === null && $heldPlacesBlock && $this->fluidState($clickedState) !== null) {
            $placedPosition = $command->clickedPosition;
        }
        $placedState = $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z);
        if ($held?->identifier === 'minecraft:bucket') {
            return $this->fillFluidBucket($player, $command, $clickedState, $held, $activeBreak['position'] ?? null);
        }
        if ($held !== null && $this->entityTypes !== null
            && isset($this->entityTypes->spawnEggMappings()[$held->identifier])) {
            return $this->useSpawnEgg(
                $player,
                $command,
                $placedPosition,
                $clickedState,
                $placedState,
                $held,
                $activeBreak['position'] ?? null,
            );
        }
        $placementStateValid = true;
        $storageEntityValid = true;
        $storageEntity = null;
        try {
            $placedBlockState = $bucketFluid !== null && $this->blockStateRegistry !== null
                ? $this->blockStateRegistry->internalId(FluidState::source($bucketFluid)->canonicalState())
                : ($heldType?->placedBlockState !== null && $this->blockPlacementStates !== null
                ? $this->blockPlacementStates->resolve(
                    $heldType->placedBlockState,
                    $command->face,
                    $player->movement->yaw,
                )
                : $held?->placedBlockState);
        } catch (InvalidArgumentException) {
            $placedBlockState = null;
            $placementStateValid = false;
        }
        $placedIdentifier = $bucketFluid === null
            ? $heldType?->placedBlockState?->identifier() ?? $held?->identifier
            : $bucketFluid->value;
        if ($placedIdentifier !== null) {
            try {
                $storageEntity = $this->storageBlockEntityForPlacement(
                    $placedPosition,
                    $placedIdentifier,
                    $command->face,
                    $held,
                );
            } catch (InvalidArgumentException) {
                $storageEntityValid = false;
            }
        }
        $correctionReason = match (true) {
            !$player->gameMode()->canBuild() => 'gamemode',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            $held === null => 'empty_hand',
            !$placementStateValid => 'unsupported_state',
            !$storageEntityValid => 'invalid_block_entity_item',
            $placedBlockState === null => 'unsupported_item',
            $clickedState->value === $this->blockPalette->air->value => 'clicked_air',
            !$this->isReplaceablePlacementState($placedState) => 'occupied',
            !$this->blockIsReachable($player->snapshot(), $command->clickedPosition) => 'reach',
            $this->placementIntersectsPlayer($placedPosition) => 'collision',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        if ($correctionReason !== null) {
            return new BlockPlacementCorrected(
                $command->session,
                $command->clickedPosition,
                $clickedState,
                $placedPosition,
                $placedState,
                $player->inventory->selectedHotbarSlot(),
                $held,
                $activeBreak['position'] ?? null,
                $correctionReason,
            );
        }
        if ($this->pluginEvents !== null
            && !$this->pluginEvents->allowBlockPlace($player, $placedPosition, $held->identifier)) {
            return new BlockPlacementCorrected(
                $command->session,
                $command->clickedPosition,
                $clickedState,
                $placedPosition,
                $placedState,
                $player->inventory->selectedHotbarSlot(),
                $held,
                $activeBreak['position'] ?? null,
                'plugin_cancelled',
            );
        }
        try {
            $this->setBlockStateAndSchedule($placedPosition, $placedBlockState);
        } catch (OverflowException) {
            return new BlockPlacementCorrected(
                $command->session,
                $command->clickedPosition,
                $clickedState,
                $placedPosition,
                $placedState,
                $player->inventory->selectedHotbarSlot(),
                $held,
                $activeBreak['position'] ?? null,
                'capacity',
            );
        }
        if ($aquaticBucketType !== null) {
            $this->spawnEntity(new EntitySpawnRequest(
                $aquaticBucketType,
                SpawnCause::BUCKET,
                $this->worldId,
                new Position($placedPosition->x + 0.5, $placedPosition->y, $placedPosition->z + 0.5),
                $player->movement->yaw,
            ));
        }
        $placedIdentifier = $bucketFluid === null
            ? $heldType?->placedBlockState?->identifier() ?? $held->identifier
            : $bucketFluid->value;
        if ($storageEntity !== null) {
            $this->installStorageBlockEntity($player, $storageEntity, $placedIdentifier);
        }
        $this->refreshPlayerGroundStates();
        if ($player->gameMode()->consumesItems() && $bucketFluid !== null) {
            $player->inventory->replaceSlot(
                $player->inventory->selectedHotbarSlot(),
                new InventoryStack('minecraft:bucket', 1, $held->stackNetworkId),
            );
            $remaining = $player->inventory->selectedStack();
        } else {
            $remaining = $player->gameMode()->consumesItems()
                ? $player->inventory->decrementSelectedOne()
                : $held;
        }
        if ($player->gameMode()->consumesItems()) {
            $player->markDirty();
        }
        $this->pluginEvents?->blockPlaced($player, $placedPosition, $held->identifier);

        return new BlockPlaced(
            $command->session,
            $player->runtimeActorId,
            $placedPosition,
            $placedBlockState,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $this->players->recipients(),
            $activeBreak['position'] ?? null,
        );
    }

    private function fillFluidBucket(
        Player $player,
        PlaceBlock $command,
        InternalBlockStateId $clickedState,
        InventoryStack $held,
        ?BlockPosition $stoppedBreakingPosition,
    ): WorldEvent {
        $placedPosition = self::adjacentBlock($command->clickedPosition, $command->face)
            ?? $command->clickedPosition;
        $placedState = $this->blockWorld?->loadedBlockStateAt(
            $placedPosition->x,
            $placedPosition->y,
            $placedPosition->z,
        ) ?? $clickedState;
        $fluid = $this->blockStateRegistry === null
            ? null
            : FluidState::fromCanonical($this->blockStateRegistry->state($clickedState));
        $failure = match (true) {
            !$player->gameMode()->canBuild() => 'gamemode',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            $fluid === null || !$fluid->isSource() => 'fluid_source',
            !$this->blockIsReachable($player->snapshot(), $command->clickedPosition) => 'reach',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        if ($failure === null && $this->pluginEvents !== null
            && !$this->pluginEvents->allowBlockPlace($player, $command->clickedPosition, $held->identifier)) {
            $failure = 'plugin_cancelled';
        }
        if ($failure !== null || $this->blockPalette === null || $this->blockWorld === null) {
            return new BlockPlacementCorrected(
                $command->session,
                $command->clickedPosition,
                $clickedState,
                $placedPosition,
                $placedState,
                $player->inventory->selectedHotbarSlot(),
                $held,
                $stoppedBreakingPosition,
                $failure ?? 'block_world_unavailable',
            );
        }

        $this->setBlockStateAndSchedule($command->clickedPosition, $this->blockPalette->air);
        $filledIdentifier = $fluid->type === FluidType::WATER
            ? 'minecraft:water_bucket'
            : 'minecraft:lava_bucket';
        $player->inventory->replaceSlot(
            $player->inventory->selectedHotbarSlot(),
            new InventoryStack($filledIdentifier, 1, $held->stackNetworkId),
        );
        $player->markDirty();
        $remaining = $player->inventory->selectedStack();
        $this->pluginEvents?->blockPlaced($player, $command->clickedPosition, $filledIdentifier);

        return new BlockPlaced(
            $command->session,
            $player->runtimeActorId,
            $command->clickedPosition,
            $this->blockPalette->air,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $this->players->recipients(),
            $stoppedBreakingPosition,
        );
    }

    private function useSpawnEgg(
        Player $player,
        PlaceBlock $command,
        BlockPosition $spawnBlock,
        InternalBlockStateId $clickedState,
        InternalBlockStateId $placedState,
        InventoryStack $held,
        ?BlockPosition $stoppedBreakingPosition,
    ): WorldEvent {
        $failure = match (true) {
            !$player->gameMode()->canBuild() => 'gamemode',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            $clickedState->value === $this->blockPalette?->air->value => 'clicked_air',
            !$this->blockIsReachable($player->snapshot(), $command->clickedPosition) => 'reach',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        $definition = $this->entityTypes?->spawnEggMappings()[$held->identifier] ?? null;
        $type = $definition === null
            ? null
            : new VanillaEntityIdentifier($definition->identifier());
        if ($failure === null && $type === null) {
            $failure = 'unsupported_entity';
        }
        if ($failure === null) {
            $outcome = $this->spawnEntity(new EntitySpawnRequest(
                $type,
                SpawnCause::SPAWN_EGG,
                $this->worldId,
                new Position($spawnBlock->x + 0.5, $spawnBlock->y, $spawnBlock->z + 0.5),
                $player->movement->yaw,
            ));
            $failure = $outcome->failure;
        }
        if ($failure !== null) {
            return new BlockPlacementCorrected(
                $player->sessionId,
                $command->clickedPosition,
                $clickedState,
                $spawnBlock,
                $placedState,
                $player->inventory->selectedHotbarSlot(),
                $held,
                $stoppedBreakingPosition,
                $failure,
            );
        }

        $remaining = $player->gameMode()->consumesItems()
            ? $player->inventory->decrementSelectedOne()
            : $held;
        if ($player->gameMode()->consumesItems()) {
            $player->markDirty();
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $this->players->recipients($player->sessionId),
            ownerSlotCorrection: false,
        );

        return new BlockPlacementCorrected(
            $player->sessionId,
            $command->clickedPosition,
            $clickedState,
            $spawnBlock,
            $placedState,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $stoppedBreakingPosition,
            'spawn_egg_used',
        );
    }

    private function useCampfire(
        Player $player,
        PlaceBlock $command,
        string $blockIdentifier,
        InternalBlockStateId $clickedState,
        InternalBlockStateId $adjacentState,
    ): WorldEvent {
        $entity = $this->blockWorld?->blockEntityAt($command->clickedPosition);
        $held = $player->inventory->selectedStack();
        $campfireType = $blockIdentifier === 'minecraft:soul_campfire'
            ? CampfireType::SoulCampfire
            : CampfireType::Campfire;
        $persistentHeld = $held === null ? null : new ContainerItemStack(
            $held->identifier,
            1,
            $held->damage,
            $held->nbt,
            $held->auxValue,
        );
        $recipe = $persistentHeld === null || $this->processingRecipes === null
            ? null
            : CampfireRecipeResolver::match($this->processingRecipes, $campfireType, $persistentHeld);
        $freeSlot = null;
        if ($entity instanceof CampfireBlockEntity) {
            for ($slot = 0; $slot < CampfireBlockEntity::SLOT_COUNT; ++$slot) {
                if ($entity->inventory->stackAt($slot) === null) {
                    $freeSlot = $slot;
                    break;
                }
            }
        }
        $canonical = $this->blockStateRegistry?->state($clickedState);
        $lit = ($canonical?->properties()['extinguished'] ?? 0) === 0;
        $failure = match (true) {
            !$entity instanceof CampfireBlockEntity => 'campfire_block_entity',
            $held === null => 'empty_hand',
            $recipe === null => 'campfire_recipe',
            $freeSlot === null => 'campfire_full',
            !$lit => 'campfire_unlit',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        $cookStartEvent = null;
        if ($failure === null) {
            $cookStartEvent = $this->pluginEvents?->campfireCookStart(
                new \Bedriox\Api\World\BlockPosition(
                    $command->clickedPosition->x,
                    $command->clickedPosition->y,
                    $command->clickedPosition->z,
                ),
                $freeSlot,
                new ApiItemStack(
                    $persistentHeld->identifier,
                    $persistentHeld->count,
                    $persistentHeld->damage,
                    $persistentHeld->nbt,
                    $persistentHeld->auxValue,
                ),
                new ApiItemStack(
                    $recipe->output->identifier,
                    $recipe->output->count,
                    $recipe->output->damage,
                    $recipe->output->nbt,
                    $recipe->output->auxValue,
                ),
                CampfireBlockEntity::DEFAULT_COOK_TIME_TICKS,
                $campfireType === CampfireType::SoulCampfire,
            );
            if ($this->pluginEvents !== null && $cookStartEvent === null) {
                $failure = 'plugin_cancelled';
            }
        }
        if ($failure === null) {
            $progress = $entity->progressBySlot;
            $duration = $entity->durationBySlot;
            $progress[$freeSlot] = 0;
            $duration[$freeSlot] = $cookStartEvent?->cookTicks() ?? CampfireBlockEntity::DEFAULT_COOK_TIME_TICKS;
            $updated = $entity->withState(
                $entity->inventory->withStack($freeSlot, $persistentHeld),
                $progress,
                $duration,
            );
            $this->blockWorld->setBlockEntity($updated);
            $this->deferredEvents[] = new BlockEntityChanged($updated, $this->players->recipients());
            $remaining = $player->gameMode()->consumesItems()
                ? $player->inventory->decrementSelectedOne()
                : $held;
            if ($player->gameMode()->consumesItems()) {
                $player->markDirty();
            }
            $this->deferredEvents[] = new HeldItemChanged(
                $player->sessionId,
                $player->runtimeActorId,
                $player->inventory->selectedHotbarSlot(),
                $remaining,
                $this->players->recipients($player->sessionId),
                ownerSlotCorrection: false,
            );
            if ($cookStartEvent !== null) {
                $this->pluginEvents->campfireCookingStarted(
                    $cookStartEvent->position,
                    $cookStartEvent->slot,
                    $cookStartEvent->input,
                    $cookStartEvent->result,
                    $cookStartEvent->cookTicks(),
                    $cookStartEvent->soulCampfire,
                );
            }
            $this->scheduleCampfire($command->clickedPosition);
        }

        return new BlockPlacementCorrected(
            $player->sessionId,
            $command->clickedPosition,
            $clickedState,
            self::adjacentBlock($command->clickedPosition, $command->face) ?? $command->clickedPosition,
            $adjacentState,
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            null,
            $failure ?? 'campfire_cooking_started',
        );
    }

    private function useComposter(
        Player $player,
        PlaceBlock $command,
        InternalBlockStateId $clickedState,
        InternalBlockStateId $adjacentState,
    ): WorldEvent {
        $registry = $this->blockStateRegistry;
        if ($registry === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'composter_state');
        }
        $canonical = $registry->state($clickedState);
        $level = $canonical->properties()['composter_fill_level'] ?? null;
        $held = $player->inventory->selectedStack();
        $item = $held === null ? null : new ContainerItemStack(
            $held->identifier,
            1,
            $held->damage,
            $held->nbt,
            $held->auxValue,
        );
        $failure = match (true) {
            !is_int($level) => 'composter_state',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        if ($failure !== null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, $failure);
        }
        $cause = $level === 8
            ? \Bedriox\Api\Processing\ComposterChangeCause::EXTRACT
            : \Bedriox\Api\Processing\ComposterChangeCause::INSERT;
        $result = $level === 8
            ? $this->composters->extract(new ComposterState($level))
            : ($item === null ? null : $this->composters->insert(
                new ComposterState($level),
                $item,
                $this->dropRandom->integer(0, 99),
            ));
        if ($result === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'composter_item');
        }
        $apiPosition = new ApiBlockPosition(
            $command->clickedPosition->x,
            $command->clickedPosition->y,
            $command->clickedPosition->z,
        );
        $apiItem = $item === null ? null : new ApiItemStack(
            $item->identifier,
            $item->count,
            $item->damage,
            $item->nbt,
            $item->auxValue,
        );
        $change = $this->pluginEvents?->composterChange(
            $this->pluginEvents->playerView($player),
            $apiPosition,
            $level,
            $result->state->level,
            $cause,
            $apiItem,
        );
        if ($this->pluginEvents !== null && $change === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'plugin_cancelled');
        }
        $newLevel = $change?->newLevel() ?? $result->state->level;
        if (!$this->applyProcessingInventory($player, $result->consumed, $result->output)) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'inventory_capacity');
        }
        $properties = $canonical->properties();
        $properties['composter_fill_level'] = $newLevel;
        $updated = $registry->internalId(CanonicalBlockState::from('minecraft:composter', $properties));
        $this->setBlockStateAndSchedule($command->clickedPosition, $updated);
        if ($newLevel === 7) {
            $this->scheduleComposter($command->clickedPosition);
        }
        $this->pluginEvents?->composterChanged(
            $this->pluginEvents->playerView($player),
            $apiPosition,
            $level,
            $newLevel,
            $cause,
            $apiItem,
        );

        return new BlockChanged($player->sessionId, $command->clickedPosition, $updated, $this->players->recipients());
    }

    private function useCauldron(
        Player $player,
        PlaceBlock $command,
        InternalBlockStateId $clickedState,
        InternalBlockStateId $adjacentState,
    ): WorldEvent {
        $registry = $this->blockStateRegistry;
        $world = $this->blockWorld;
        if ($registry === null || $world === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'cauldron_state');
        }
        $canonical = $registry->state($clickedState);
        $properties = $canonical->properties();
        $level = $properties['fill_level'] ?? null;
        $liquid = $properties['cauldron_liquid'] ?? null;
        $entity = $world->blockEntityAt($command->clickedPosition);
        $potionAux = $entity instanceof CauldronBlockEntity ? $entity->potionAuxValue : null;
        $content = match (true) {
            $level === 0 => \Bedriox\Api\Processing\CauldronContentType::EMPTY,
            $potionAux !== null => \Bedriox\Api\Processing\CauldronContentType::POTION,
            $liquid === 'water' => \Bedriox\Api\Processing\CauldronContentType::WATER,
            $liquid === 'lava' => \Bedriox\Api\Processing\CauldronContentType::LAVA,
            $liquid === 'powder_snow' => \Bedriox\Api\Processing\CauldronContentType::POWDER_SNOW,
            default => null,
        };
        $held = $player->inventory->selectedStack();
        $item = $held === null ? null : new ContainerItemStack(
            $held->identifier,
            1,
            $held->damage,
            $held->nbt,
            $held->auxValue,
        );
        $failure = match (true) {
            !is_int($level) || $content === null => 'cauldron_state',
            $item === null => 'empty_hand',
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            default => null,
        };
        if ($command->sequence > $player->placementSequence) {
            $player->placementSequence = $command->sequence;
        }
        if ($failure !== null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, $failure);
        }
        $state = new CauldronState($content, $level, $potionAux);
        $result = $this->cauldrons->interact($state, $item);
        if ($result === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'cauldron_item');
        }
        $cause = match ($held->identifier) {
            'minecraft:water_bucket', 'minecraft:lava_bucket', 'minecraft:powder_snow_bucket', 'minecraft:bucket' =>
                \Bedriox\Api\Processing\CauldronChangeCause::BUCKET,
            'minecraft:glass_bottle', 'minecraft:potion' => \Bedriox\Api\Processing\CauldronChangeCause::BOTTLE,
            default => \Bedriox\Api\Processing\CauldronChangeCause::WASH,
        };
        $apiPosition = new ApiBlockPosition(
            $command->clickedPosition->x,
            $command->clickedPosition->y,
            $command->clickedPosition->z,
        );
        $apiItem = new ApiItemStack(
            $item->identifier,
            $item->count,
            $item->damage,
            $item->nbt,
            $item->auxValue,
        );
        $change = $this->pluginEvents?->cauldronChange(
            $this->pluginEvents->playerView($player),
            $apiPosition,
            $content,
            $level,
            $result->state->content,
            $result->state->level,
            $cause,
            $apiItem,
        );
        if ($this->pluginEvents !== null && $change === null) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'plugin_cancelled');
        }
        try {
            $next = new CauldronState(
                $change?->newContent() ?? $result->state->content,
                $change?->newLevel() ?? $result->state->level,
                ($change?->newContent() ?? $result->state->content) === \Bedriox\Api\Processing\CauldronContentType::POTION
                    ? $result->state->potionAuxValue
                    : null,
            );
        } catch (InvalidArgumentException) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'plugin_outcome');
        }
        if (!$this->applyProcessingInventory($player, true, $result->heldItem)) {
            return $this->processingCorrection($player, $command, $clickedState, $adjacentState, 'inventory_capacity');
        }
        $updated = $registry->internalId(CanonicalBlockState::from('minecraft:cauldron', [
            'cauldron_liquid' => match ($next->content) {
                \Bedriox\Api\Processing\CauldronContentType::LAVA => 'lava',
                \Bedriox\Api\Processing\CauldronContentType::POWDER_SNOW => 'powder_snow',
                default => 'water',
            },
            'fill_level' => $next->level,
        ]));
        $this->setBlockStateAndSchedule($command->clickedPosition, $updated);
        if ($next->content === \Bedriox\Api\Processing\CauldronContentType::POTION) {
            $cauldron = new CauldronBlockEntity($command->clickedPosition, $next->potionAuxValue);
            $world->setBlockEntity($cauldron);
            $this->deferredEvents[] = new BlockEntityChanged($cauldron, $this->players->recipients());
        } elseif ($entity instanceof CauldronBlockEntity) {
            $world->removeBlockEntity($command->clickedPosition);
        }
        $this->pluginEvents?->cauldronChanged(
            $this->pluginEvents->playerView($player),
            $apiPosition,
            $content,
            $level,
            $next->content,
            $next->level,
            $cause,
            $apiItem,
        );

        return new BlockChanged($player->sessionId, $command->clickedPosition, $updated, $this->players->recipients());
    }

    private function processingCorrection(
        Player $player,
        PlaceBlock $command,
        InternalBlockStateId $clickedState,
        InternalBlockStateId $adjacentState,
        string $reason,
    ): BlockPlacementCorrected {
        return new BlockPlacementCorrected(
            $player->sessionId,
            $command->clickedPosition,
            $clickedState,
            self::adjacentBlock($command->clickedPosition, $command->face) ?? $command->clickedPosition,
            $adjacentState,
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            reason: $reason,
        );
    }

    private function applyProcessingInventory(
        Player $player,
        bool $consumeHeld,
        ?ContainerItemStack $output,
    ): bool {
        if (!$player->gameMode()->consumesItems()) {
            return true;
        }
        $before = $player->inventory->slots();
        $staged = clone $player->inventory;
        $held = $staged->selectedStack();
        if ($consumeHeld && $held === null) {
            return false;
        }
        if ($consumeHeld && $output !== null && $held->count === 1) {
            $staged->replaceSlot(
                $staged->selectedHotbarSlot(),
                new InventoryStack($output->identifier, $output->count, 1, damage: $output->damage, nbt: $output->nbt, auxValue: $output->auxValue),
            );
        } else {
            if ($consumeHeld) {
                $staged->decrementSelectedOne();
            }
            if ($output !== null && $staged->add(new InventoryStack(
                $output->identifier,
                $output->count,
                1,
                damage: $output->damage,
                nbt: $output->nbt,
                auxValue: $output->auxValue,
            )) !== null) {
                return false;
            }
        }
        $player->inventory->replaceMainContents($staged->slots());
        $player->markDirty();
        foreach ($before as $slot => $stack) {
            $replacement = $player->inventory->stackAt($slot);
            if (self::sameInventoryStack($stack, $replacement)) {
                continue;
            }
            $this->deferredEvents[] = new InventorySlotChanged($player->sessionId, $slot, $replacement);
        }
        $this->deferredEvents[] = new HeldItemChanged(
            $player->sessionId,
            $player->runtimeActorId,
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            $this->players->recipients($player->sessionId),
        );

        return true;
    }

    private function storageBlockEntityForPlacement(
        BlockPosition $position,
        string $blockIdentifier,
        int $clickedFace,
        ?InventoryStack $held,
    ): ?\Bedriox\Server\World\BlockEntity\BlockEntity {
        $type = WorldContainerStore::blockEntityType($blockIdentifier);
        if ($type === null) {
            return null;
        }
        if ($type === BlockEntityType::ShulkerBox) {
            return $this->shulkerItems->decode($held?->nbt, $position, $clickedFace);
        }
        if ($type === BlockEntityType::BrewingStand) {
            return BrewingStandBlockEntity::empty($position);
        }
        if (in_array($type, [BlockEntityType::Furnace, BlockEntityType::BlastFurnace, BlockEntityType::Smoker], true)) {
            $furnaceType = match ($type) {
                BlockEntityType::Furnace => FurnaceType::Furnace,
                BlockEntityType::BlastFurnace => FurnaceType::BlastFurnace,
                BlockEntityType::Smoker => FurnaceType::Smoker,
            };

            return FurnaceBlockEntity::empty($furnaceType, $position);
        }
        if ($type === BlockEntityType::Campfire) {
            return CampfireBlockEntity::empty(
                $blockIdentifier === 'minecraft:soul_campfire' ? CampfireType::SoulCampfire : CampfireType::Campfire,
                $position,
            );
        }

        return $type->ownsPersistentInventory()
            ? ContainerBlockEntity::empty($type, $position)
            : new SimpleBlockEntity($type, $position);
    }

    private function installStorageBlockEntity(
        Player $player,
        \Bedriox\Server\World\BlockEntity\BlockEntity $entity,
        string $blockIdentifier,
    ): void {
        if ($this->blockWorld === null) {
            return;
        }
        $this->blockWorld->setBlockEntity($entity);
        $changed = [$entity];
        if ($entity instanceof ContainerBlockEntity && $entity->type === BlockEntityType::Chest) {
            $paired = $this->pairPlacedChest($player, $entity, $blockIdentifier);
            if ($paired !== []) {
                $changed = $paired;
            }
        }
        foreach ($changed as $blockEntity) {
            $this->deferredEvents[] = new BlockEntityChanged($blockEntity, $this->players->recipients());
        }
    }

    /** @return list<ContainerBlockEntity> */
    private function pairPlacedChest(
        Player $player,
        ContainerBlockEntity $placed,
        string $blockIdentifier,
    ): array {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return [];
        }
        $placedState = $this->blockWorld->blockStateAt(
            $placed->position->x,
            $placed->position->y,
            $placed->position->z,
        );
        $facing = $this->blockStateRegistry->state($placedState)->properties()['minecraft:cardinal_direction'] ?? null;
        $sides = match ($facing) {
            'north' => [[-1, 0, false], [1, 0, true]],
            'south' => [[1, 0, false], [-1, 0, true]],
            'west' => [[0, 1, false], [0, -1, true]],
            'east' => [[0, -1, false], [0, 1, true]],
            default => [],
        };
        foreach ($sides as [$offsetX, $offsetZ, $clockwise]) {
            $candidatePosition = new BlockPosition(
                $placed->position->x + $offsetX,
                $placed->position->y,
                $placed->position->z + $offsetZ,
            );
            $candidateState = $this->blockWorld->blockStateAt(
                $candidatePosition->x,
                $candidatePosition->y,
                $candidatePosition->z,
            );
            if ($candidateState->value !== $placedState->value
                || $this->blockIdentifier($candidateState->value) !== $blockIdentifier) {
                continue;
            }
            $candidate = $this->blockWorld->blockEntityAt($candidatePosition);
            if (!$candidate instanceof ContainerBlockEntity
                || $candidate->type !== BlockEntityType::Chest
                || $candidate->pairedPosition !== null) {
                continue;
            }
            [$lead, $other] = $clockwise ? [$candidate, $placed] : [$placed, $candidate];
            if ($this->pluginEvents !== null
                && !$this->pluginEvents->allowChestPair($player, $lead->position, $other->position, $blockIdentifier)) {
                return [];
            }
            $lead = $lead->withPair($other->position, true);
            $other = $other->withPair($lead->position, false);
            $this->blockWorld->setBlockEntities($lead, $other);
            $this->pluginEvents?->chestPaired($player, $lead->position, $other->position, $blockIdentifier);

            return [$lead, $other];
        }

        return [];
    }

    private function openWorldContainer(
        Player $player,
        BlockPosition $position,
        string $blockIdentifier,
    ): WorldEvent {
        if ($this->blockWorld === null || $this->worldContainers === null) {
            return new CommandRejected($player->sessionId, 'container_world_unavailable');
        }
        $type = WorldContainerStore::containerType($blockIdentifier);
        if ($type === null || $this->containerIsObstructed($position, $type)) {
            return new CommandRejected($player->sessionId, $type === null ? 'container_type' : 'container_obstructed');
        }
        $worldContainer = null;
        $playerOwnedEnderChest = $type === ApiContainerType::ENDER_CHEST;
        if ($playerOwnedEnderChest) {
            if (!$this->blockWorld->blockEntityAt($position) instanceof SimpleBlockEntity) {
                return new CommandRejected($player->sessionId, 'container_block_entity');
            }
            $inventory = new SimpleContainerInventory(
                'player/' . strtolower($player->identity->uuid) . '/ender_chest',
                PlayerInventory::ENDER_CHEST_SLOT_COUNT,
                array_map(
                    static fn(?InventoryStack $stack): ?ApiItemStack => $stack === null
                        ? null
                        : self::apiInventoryStack($stack),
                    $player->inventory->enderChestSlots(),
                ),
            );
        } else {
            $worldContainer = $this->worldContainers->resolve($position, $blockIdentifier);
            if (!$worldContainer instanceof ResolvedWorldContainer) {
                return new CommandRejected($player->sessionId, 'container_block_entity');
            }
            $inventory = $worldContainer->inventory;
            $type = $worldContainer->type;
            if ($type === ApiContainerType::BREWING_STAND) {
                $this->scheduleBrewingStand($worldContainer->position);
            }
            if (in_array($type, [ApiContainerType::FURNACE, ApiContainerType::BLAST_FURNACE, ApiContainerType::SMOKER], true)) {
                $this->scheduleFurnace($worldContainer->position);
            }
            if ($worldContainer->pairedPosition !== null
                && $this->containerIsObstructed($worldContainer->pairedPosition, $type)) {
                return new CommandRejected($player->sessionId, 'container_obstructed');
            }
        }
        return $this->openContainerInventory(
            $player,
            $type,
            $inventory,
            $playerOwnedEnderChest ? $position : $worldContainer->position,
            $playerOwnedEnderChest ? null : $worldContainer->pairedPosition,
            $playerOwnedEnderChest ? null : $worldContainer->customName,
            worldContainer: $worldContainer,
            playerOwnedEnderChest: $playerOwnedEnderChest,
        );
    }

    private function openContainerInventory(
        Player $player,
        ApiContainerType $type,
        LiveContainerInventory $inventory,
        ?BlockPosition $position = null,
        ?BlockPosition $pairedPosition = null,
        ?string $title = null,
        ?ApiContainerLayout $layout = null,
        ?ResolvedWorldContainer $worldContainer = null,
        bool $playerOwnedEnderChest = false,
        ?string $owningPlugin = null,
    ): WorldEvent {
        $key = self::sessionKey($player->sessionId);
        if (isset($this->openContainers[$key])) {
            return new CommandRejected($player->sessionId, 'container_already_open');
        }
        try {
            $projection = $player->inventory->projectOpenedContainer(
                $inventory->identifier(),
                array_map(
                    fn(?ApiItemStack $stack): ?InventoryStack => $stack === null
                        ? null
                        : $this->inventoryStackFromApi($stack),
                    $inventory->contents(),
                ),
            );
            $windowId = $this->allocateContainerWindowId($key);
            $session = new PlayerContainerSession(
                $windowId,
                $type,
                $inventory,
                $projection,
                $inventory->revision(),
                $position,
                $pairedPosition,
                $title,
                $layout,
                worldContainer: $worldContainer,
                playerOwnedEnderChest: $playerOwnedEnderChest,
                owningPlugin: $owningPlugin,
            );
            $view = $this->containerView($session, [$player->identity->uuid]);
            if ($this->pluginEvents !== null && !$this->pluginEvents->allowContainerOpen($player, $view)) {
                return new CommandRejected($player->sessionId, 'plugin_cancelled');
            }
            $firstViewer = !$this->hasContainerPresentationViewer($type, $position, $pairedPosition);
            $inventory->addViewer($player->identity->uuid);
            $this->openContainers[$key] = $session;
            if ($type === ApiContainerType::ENCHANTING_TABLE) {
                $this->deferEnchantingOptions($player, $session);
            }
            if ($type === ApiContainerType::BREWING_STAND && $position !== null) {
                $brewing = $this->blockWorld?->blockEntityAt($position);
                if ($brewing instanceof BrewingStandBlockEntity) {
                    $this->deferredEvents[] = new BrewingStandUpdated(
                        [new ContainerViewerProjection($player->sessionId, $windowId, $projection->slots())],
                        [],
                        $brewing->brewTime,
                        $brewing->fuelAmount,
                        $brewing->fuelTotal,
                    );
                }
            }
            if (in_array($type, [ApiContainerType::FURNACE, ApiContainerType::BLAST_FURNACE, ApiContainerType::SMOKER], true)
                && $position !== null) {
                $furnace = $this->blockWorld?->blockEntityAt($position);
                if ($furnace instanceof FurnaceBlockEntity) {
                    $this->deferredEvents[] = new FurnaceUpdated(
                        [new ContainerViewerProjection($player->sessionId, $windowId, $projection->slots())],
                        [],
                        $furnace->cookTime,
                        $furnace->burnTime,
                        $furnace->burnDuration,
                        $furnace->storedExperienceMilli,
                    );
                }
            }
            if ($firstViewer && $type === ApiContainerType::BARREL && $position !== null) {
                $barrelChanged = $this->setBarrelOpen($player->sessionId, $position, true);
                if ($barrelChanged !== null) {
                    $this->deferredEvents[] = $barrelChanged;
                }
            }
            $this->pluginEvents?->containerOpened($player, $this->containerView($session));

            return new ContainerOpened(
                $player->sessionId,
                $windowId,
                $type,
                $session->position,
                $projection->slots(),
                $firstViewer ? $this->players->recipients() : [],
                $session->pairedPosition,
                $session->title,
                $session->layout,
            );
        } catch (InvalidArgumentException|OverflowException) {
            return new CommandRejected($player->sessionId, 'container_projection');
        }
    }

    private function closeContainerCommand(CloseContainer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        $session = $this->openContainers[self::sessionKey($command->session)] ?? null;
        if ($player === null || $session === null
            || ($command->windowId !== 0xff && $command->windowId !== $session->windowId)) {
            return new CommandRejected($command->session, 'container_not_open');
        }

        return $this->closeContainer($player, ApiInventoryCloseReason::CLIENT, false)
            ?? new CommandRejected($command->session, 'container_not_open');
    }

    private function closeContainer(
        Player $player,
        ApiInventoryCloseReason $reason,
        bool $serverInitiated,
    ): ?ContainerClosed {
        $key = self::sessionKey($player->sessionId);
        $session = $this->openContainers[$key] ?? null;
        if (!$session instanceof PlayerContainerSession) {
            return null;
        }
        unset($this->openContainers[$key]);
        $session->inventory->removeViewer($player->identity->uuid);
        if ($session->type->isTransient()) {
            $this->returnTransientWorkstationInputs($player, $session);
        }
        $view = $this->containerView($session);
        $lastViewer = !$this->hasContainerPresentationViewer(
            $session->type,
            $session->position,
            $session->pairedPosition,
        );
        if ($session->type === ApiContainerType::BARREL
            && $session->position !== null
            && $lastViewer) {
            $barrelChanged = $this->setBarrelOpen($player->sessionId, $session->position, false);
            if ($barrelChanged !== null) {
                $this->deferredEvents[] = $barrelChanged;
            }
        }
        $this->pluginEvents?->containerClosed($player, $view, $reason);

        return new ContainerClosed(
            $player->sessionId,
            $session->windowId,
            $session->type,
            $session->position,
            $lastViewer ? $this->players->recipients() : [],
            $session->pairedPosition,
            $serverInitiated,
            $session->layout,
        );
    }

    private function returnTransientWorkstationInputs(Player $player, PlayerContainerSession $session): void
    {
        $resultSlot = $session->type->resultSlot();
        $before = clone $player->inventory;
        $changed = false;
        foreach ($session->inventory->contents() as $slot => $item) {
            if ($slot === $resultSlot || $item === null) {
                continue;
            }
            $remaining = $player->inventory->add($this->inventoryStackFromApi($item));
            $changed = true;
            if ($remaining === null || !$this->itemEntities->canSpawn()) {
                continue;
            }
            $entity = $this->itemEntities->spawn(
                $remaining,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 1.0,
                    $player->movement->position->z,
                ),
                new ItemEntityMotion(0.0, 0.05, 0.0),
                10,
            );
            $this->deferredEvents[] = new ItemEntitySpawned($entity, $this->players->recipients());
        }
        if ($changed) {
            $player->markDirty();
            foreach ($before->slots() as $slot => $previous) {
                $replacement = $player->inventory->stackAt($slot);
                if (self::sameInventoryStack($previous, $replacement)) {
                    continue;
                }
                $this->deferredEvents[] = new InventorySlotChanged(
                    $player->sessionId,
                    $slot,
                    $replacement,
                );
            }
            if (!self::sameInventoryStack($before->selectedStack(), $player->inventory->selectedStack())) {
                $this->deferredEvents[] = new HeldItemChanged(
                    $player->sessionId,
                    $player->runtimeActorId,
                    $player->inventory->selectedHotbarSlot(),
                    $player->inventory->selectedStack(),
                    $this->players->recipients($player->sessionId),
                );
            }
            $this->pluginEvents?->inventoryChanged($player, $before);
        }
    }

    private function resolveWorldContainer(BlockPosition $position): ?ResolvedWorldContainer
    {
        if ($this->blockWorld === null || $this->worldContainers === null) {
            return null;
        }
        $identifier = $this->blockIdentifier($this->blockWorld->blockStateAt(
            $position->x,
            $position->y,
            $position->z,
        )->value);

        return $this->worldContainers->resolve($position, $identifier);
    }

    private function ownedVirtualContainer(string $plugin, string $identifier): ?VirtualContainer
    {
        $virtual = $this->virtualContainers[$identifier] ?? null;

        return $virtual instanceof VirtualContainer && strcasecmp($virtual->ownerPlugin, $plugin) === 0
            ? $virtual
            : null;
    }

    /** @param list<ApiItemStack|null> $contents */
    private function validateApiContainerContents(array $contents, int $size): void
    {
        if (count($contents) !== $size) {
            throw new InvalidArgumentException('Container replacement must preserve its typed layout.');
        }
        foreach ($contents as $stack) {
            if ($stack !== null) {
                $this->inventoryStackFromApi($stack);
            }
        }
    }

    private function synchronizeContainerInventory(LiveContainerInventory $inventory): void
    {
        $viewers = [];
        foreach ($this->openContainers as $key => $session) {
            if ($session->inventory->identifier() !== $inventory->identifier()) {
                continue;
            }
            $player = $this->players->player(substr($key, strlen('session:')));
            if ($player === null) {
                continue;
            }
            $this->refreshContainerProjection($player, $session);
            $viewers[] = [$player, $session];
        }
        if ($viewers === []) {
            return;
        }
        [$owner, $ownerSession] = array_shift($viewers);
        $additional = [];
        foreach ($viewers as [$viewer, $viewerSession]) {
            $additional[] = new ContainerViewerProjection(
                $viewer->sessionId,
                $viewerSession->windowId,
                $viewerSession->projection->slots(),
            );
        }
        $this->deferredEvents[] = new ContainerContentsChanged(
            $owner->sessionId,
            $ownerSession->windowId,
            $ownerSession->type,
            $ownerSession->position,
            $ownerSession->projection->slots(),
            [],
            $additional,
            $ownerSession->pairedPosition,
            $ownerSession->layout,
        );
    }

    private static function containerInventoryView(
        ApiContainerType $type,
        LiveContainerInventory $inventory,
        ?BlockPosition $position = null,
        ?BlockPosition $pairedPosition = null,
        ?string $title = null,
    ): ApiContainerView {
        $viewerUuids = $inventory->viewerUuids();
        if ($pairedPosition !== null) {
            sort($viewerUuids, SORT_STRING);
        }

        return new ApiContainerView(
            $type,
            new ApiInventoryView(
                self::containerInventoryIdentifier($inventory),
                $inventory->contents(),
                $inventory->revision(),
            ),
            $position === null
                ? null
                : new \Bedriox\Api\World\BlockPosition($position->x, $position->y, $position->z),
            $title,
            $viewerUuids,
        );
    }

    /** @param list<string>|null $viewerUuids */
    private function containerView(PlayerContainerSession $session, ?array $viewerUuids = null): ApiContainerView
    {
        return new ApiContainerView(
            $session->type,
            new ApiInventoryView(
                self::containerInventoryIdentifier($session->inventory),
                $session->inventory->contents(),
                $session->inventory->revision(),
            ),
            $session->position === null
                ? null
                : new \Bedriox\Api\World\BlockPosition(
                    $session->position->x,
                    $session->position->y,
                    $session->position->z,
                ),
            $session->title,
            $viewerUuids ?? $session->inventory->viewerUuids(),
        );
    }

    private function containerIsObstructed(BlockPosition $position, ApiContainerType $type): bool
    {
        if ($this->blockWorld === null || $this->blockPalette === null) {
            return false;
        }
        if ($type === ApiContainerType::SHULKER_BOX) {
            $entity = $this->blockWorld->blockEntityAt($position);
            if (!$entity instanceof ContainerBlockEntity || $entity->type !== BlockEntityType::ShulkerBox) {
                return true;
            }
            $extension = self::adjacentBlock($position, $entity->facing);

            return $extension === null || $this->blockAtIsSolid($extension);
        }
        if (!in_array($type, [
            ApiContainerType::CHEST,
            ApiContainerType::DOUBLE_CHEST,
            ApiContainerType::TRAPPED_CHEST,
            ApiContainerType::DOUBLE_TRAPPED_CHEST,
            ApiContainerType::ENDER_CHEST,
        ], true)
            || $position->y >= \Bedriox\Server\World\Chunk::MAX_Y) {
            return false;
        }

        return $this->blockAtIsSolid(new BlockPosition($position->x, $position->y + 1, $position->z));
    }

    private function blockAtIsSolid(BlockPosition $position): bool
    {
        if ($this->blockWorld === null || $this->blockPalette === null) {
            return true;
        }
        $state = $this->blockWorld->blockStateAt($position->x, $position->y, $position->z);
        if ($state->value === $this->blockPalette->air->value) {
            return false;
        }
        if ($this->blockProperties !== null && $this->blockStateRegistry !== null) {
            try {
                return $this->blockProperties->propertiesForState($this->blockStateRegistry->state($state))->solid();
            } catch (InvalidArgumentException) {
                // Fall back to admitted collision data for a state not present in the active physical-property set.
            }
        }
        $shape = $this->blockCollisionRegistry?->find($state);

        return $shape === null || !$shape->isEmpty();
    }

    private function hasContainerPresentationViewer(
        ApiContainerType $type,
        ?BlockPosition $position,
        ?BlockPosition $pairedPosition,
    ): bool {
        $identity = self::containerPresentationIdentity($type, $position, $pairedPosition);
        if ($identity === null) {
            return false;
        }
        foreach ($this->openContainers as $session) {
            if (self::containerPresentationIdentity(
                $session->type,
                $session->position,
                $session->pairedPosition,
            ) === $identity) {
                return true;
            }
        }

        return false;
    }

    private static function containerPresentationIdentity(
        ApiContainerType $type,
        ?BlockPosition $position,
        ?BlockPosition $pairedPosition,
    ): ?string {
        if ($position === null || $type === ApiContainerType::VIRTUAL) {
            return null;
        }
        $positions = [self::containerPositionIdentity($position)];
        if ($pairedPosition !== null) {
            $positions[] = self::containerPositionIdentity($pairedPosition);
            sort($positions, SORT_STRING);
        }

        return $type->value . '/' . implode('/', $positions);
    }

    private static function containerPositionIdentity(BlockPosition $position): string
    {
        return $position->x . ':' . $position->y . ':' . $position->z;
    }

    private function setBarrelOpen(string $ownerSessionId, BlockPosition $position, bool $open): ?BlockChanged
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return null;
        }
        $current = $this->blockWorld->blockStateAt($position->x, $position->y, $position->z);
        $canonical = $this->blockStateRegistry->state($current);
        if ($canonical->identifier() !== 'minecraft:barrel') {
            return null;
        }
        $properties = $canonical->properties();
        $openBit = $open ? 1 : 0;
        if (($properties['open_bit'] ?? null) === $openBit) {
            return null;
        }
        $properties['open_bit'] = $openBit;
        $updated = $this->blockStateRegistry->internalId(CanonicalBlockState::from('minecraft:barrel', $properties));
        $this->setBlockStateAndSchedule($position, $updated);

        return new BlockChanged($ownerSessionId, $position, $updated, $this->players->recipients());
    }

    private function allocateContainerWindowId(string $sessionKey): int
    {
        $candidate = $this->nextContainerWindowIds[$sessionKey] ?? 2;
        if ($candidate < 2 || $candidate > 99) {
            $candidate = 2;
        }
        $this->nextContainerWindowIds[$sessionKey] = $candidate === 99 ? 2 : $candidate + 1;

        return $candidate;
    }

    private function placementIntersectsPlayer(BlockPosition $block): bool
    {
        $blockBox = AxisAlignedBox::unitAt($block->x, $block->y, $block->z);
        foreach ($this->players->snapshots() as $player) {
            if ($blockBox->intersects(PlayerCollisionShape::at($player->position))) {
                return true;
            }
        }

        return false;
    }

    private function refreshPlayerGroundStates(): void
    {
        if ($this->collisionResolver === null) {
            return;
        }
        foreach ($this->players->snapshots() as $snapshot) {
            $player = $this->players->player($snapshot->sessionId);
            if ($player === null) {
                continue;
            }
            $grounded = $this->collisionResolver->isGrounded($player->movement->position);
            $player->movement->verticalState = $grounded ? VerticalState::GROUNDED : VerticalState::AIRBORNE;
            if ($grounded) {
                $player->movement->verticalVelocity = 0.0;
            }
        }
    }

    private function pluginMessage(SendPluginMessage $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }

        return new ChatBroadcast(
            $player->sessionId,
            '00000000-0000-0000-0000-000000000000',
            'Bedriox',
            0,
            $command->message,
            [$player->sessionId],
        );
    }

    private function pluginTeleport(TeleportPlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $destination = $command->position;
        $yaw = $command->yaw ?? $player->movement->yaw;
        $pitch = $command->pitch ?? $player->movement->pitch;
        $from = $player->movement->position;
        if ($this->pluginEvents !== null) {
            $decision = $this->pluginEvents->teleport($player, $destination, $yaw, $pitch);
            if ($decision === null) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            $destination = $decision->destination;
            $yaw = $decision->yaw;
            $pitch = $decision->pitch;
        }
        $this->forceDismountPlayer($player, MountReason::TELEPORT);
        $this->deferItemUseCancellation($player, ItemUseCancellationReason::TELEPORT);
        $closed = $this->closeContainer($player, ApiInventoryCloseReason::TELEPORT, true);
        if ($closed !== null) {
            $this->deferredEvents[] = $closed;
        }
        $player->movement->position = $destination;
        $player->movement->yaw = $yaw;
        $player->movement->headYaw = $yaw;
        $player->movement->pitch = $pitch;
        $player->movement->mode = MovementMode::STOPPED;
        $player->movement->velocityX = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->velocityZ = 0.0;
        $player->movement->fallDistance = 0.0;
        $player->movement->jumpAuthorizedUntilTick = -1;
        $player->movement->lastTick = $this->tick;
        $player->movement->verticalState = $this->collisionResolver?->isGrounded($destination) === true
            ? VerticalState::GROUNDED
            : VerticalState::AIRBORNE;
        unset($this->breakingBlocks[self::sessionKey($command->session)]);
        $player->markDirty();
        $this->pluginEvents?->teleported($player, $from);

        return new MovementCorrected(
            $player->snapshot(),
            'plugin_teleport',
            $this->players->recipients($player->sessionId),
            true,
        );
    }

    private function pluginBlock(SetPluginBlock $command): WorldEvent
    {
        if ($this->blockWorld === null || $this->blockPalette === null) {
            return new CommandRejected($command->sessionId(), 'block_world_unavailable');
        }
        $state = $this->blockCatalog !== null && $this->blockStateRegistry !== null
            && $this->blockCatalog->has($command->identifier)
            && $command->identifier !== 'minecraft:air'
            ? $this->blockStateRegistry->internalId($this->blockCatalog->type($command->identifier)->state)
            : match ($command->identifier) {
                'minecraft:air' => $this->blockPalette->air,
                'minecraft:bedrock' => $this->blockPalette->bedrock,
                'minecraft:dirt' => $this->blockPalette->dirt,
                'minecraft:grass_block' => $this->blockPalette->grassBlock,
                default => null,
            };
        if ($state === null) {
            return new CommandRejected($command->sessionId(), 'unsupported_block');
        }
        try {
            $this->setBlockStateAndSchedule($command->position, $state);
        } catch (OverflowException) {
            return new CommandRejected($command->sessionId(), 'block_capacity');
        }
        $this->refreshPlayerGroundStates();

        return new BlockChanged(
            $command->sessionId(),
            $command->position,
            $state,
            $this->players->recipients(),
            false,
        );
    }

    private function pluginParticle(SpawnPluginParticle $command): WorldEvent
    {
        $pluginCount = $this->particlesByPluginThisTick[$command->plugin] ?? 0;
        if ($this->particlesThisTick >= self::MAXIMUM_PARTICLES_PER_WORLD_PER_TICK
            || $pluginCount >= self::MAXIMUM_PARTICLES_PER_PLUGIN_PER_TICK) {
            return new CommandRejected($command->sessionId(), 'particle_budget');
        }
        ++$this->particlesThisTick;
        $this->particlesByPluginThisTick[$command->plugin] = $pluginCount + 1;

        $recipients = $this->players->recipients();
        if ($command->targetIdentities !== null) {
            $requested = array_fill_keys($command->targetIdentities, true);
            $recipients = array_values(array_filter(
                $recipients,
                function (string $sessionId) use ($requested): bool {
                    $player = $this->players->player($sessionId);

                    return $player !== null && isset($requested[$player->identity->uuid]);
                },
            ));
        }

        return new ParticleSpawned($command->position, $command->particle, $recipients, $this->dimension);
    }

    private function pluginInventorySlot(SetPluginInventorySlot $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$this->pluginInventoryStackIsAdmitted($command->stack)) {
            return new CommandRejected($command->session, 'unsupported_item');
        }
        $before = clone $player->inventory;
        $proposed = clone $player->inventory;
        try {
            $proposed->replaceSlot($command->slot, $command->stack);
        } catch (InvalidArgumentException|OverflowException) {
            return new CommandRejected($command->session, 'invalid_item');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $player->inventory->replaceSlot($command->slot, $command->stack);
        $player->markDirty();
        $this->pluginEvents?->inventoryChanged($player, $before);

        return $this->pluginInventoryProjection($player, $before);
    }

    private function pluginInventoryContents(SetPluginInventoryContents $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        foreach ($command->contents as $stack) {
            if (!$this->pluginInventoryStackIsAdmitted($stack)) {
                return new CommandRejected($command->session, 'unsupported_item');
            }
        }
        $before = clone $player->inventory;
        $proposed = clone $player->inventory;
        try {
            $proposed->replaceMainContents($command->contents);
        } catch (InvalidArgumentException|OverflowException) {
            return new CommandRejected($command->session, 'invalid_item');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $player->inventory->replaceMainContents($command->contents);
        $player->markDirty();
        $this->pluginEvents?->inventoryChanged($player, $before);

        return $this->pluginInventoryProjection($player, $before);
    }

    private function removePluginInventoryStack(RemovePluginInventoryStack $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$this->pluginInventoryStackIsAdmitted($command->stack)) {
            return new CommandRejected($command->session, 'unsupported_item');
        }
        $before = clone $player->inventory;
        $proposed = clone $player->inventory;
        if (!$proposed->removeMatching($command->stack)) {
            return new CommandRejected($command->session, 'insufficient_items');
        }
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if (!$player->inventory->removeMatching($command->stack)) {
            throw new \LogicException('Validated inventory removal could not be committed.');
        }
        $player->markDirty();
        $this->pluginEvents?->inventoryChanged($player, $before);

        return $this->pluginInventoryProjection($player, $before);
    }

    private function pluginEquipmentSlot(SetPluginEquipmentSlot $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$this->pluginInventoryStackIsAdmitted($command->stack)) {
            return new CommandRejected($command->session, 'unsupported_item');
        }
        $previous = match ($command->slot) {
            ApiEquipmentSlot::HEAD => $player->inventory->armorStack(ArmorSlot::Head),
            ApiEquipmentSlot::CHEST => $player->inventory->armorStack(ArmorSlot::Chest),
            ApiEquipmentSlot::LEGS => $player->inventory->armorStack(ArmorSlot::Legs),
            ApiEquipmentSlot::FEET => $player->inventory->armorStack(ArmorSlot::Feet),
            ApiEquipmentSlot::OFF_HAND => $player->inventory->offhandStack(),
            ApiEquipmentSlot::MAIN_HAND => null,
        };
        $replacement = $command->stack;
        if ($this->pluginEvents !== null) {
            $event = $this->pluginEvents->equipmentChange($player, $command->slot, $previous, $replacement);
            if ($event === null) {
                return new CommandRejected($command->session, 'plugin_cancelled');
            }
            try {
                $replacement = $event->item() === null ? null : $this->inventoryStackFromApi($event->item());
                if (!$this->pluginInventoryStackIsAdmitted($replacement)) {
                    return new CommandRejected($command->session, 'plugin_result');
                }
            } catch (InvalidArgumentException|OverflowException) {
                return new CommandRejected($command->session, 'plugin_result');
            }
        }
        try {
            self::replaceEquipment($player->inventory, $command->slot, $replacement);
        } catch (InvalidArgumentException|OverflowException) {
            return new CommandRejected($command->session, 'invalid_item');
        }
        $player->markDirty();
        $this->pluginEvents?->equipmentChanged($player, $command->slot, $previous, $replacement);

        $container = $command->slot === ApiEquipmentSlot::OFF_HAND
            ? InventoryContainer::Offhand
            : InventoryContainer::Armor;
        $slot = match ($command->slot) {
            ApiEquipmentSlot::HEAD => ArmorSlot::Head->value,
            ApiEquipmentSlot::CHEST => ArmorSlot::Chest->value,
            ApiEquipmentSlot::LEGS => ArmorSlot::Legs->value,
            ApiEquipmentSlot::FEET => ArmorSlot::Feet->value,
            ApiEquipmentSlot::OFF_HAND => 0,
            ApiEquipmentSlot::MAIN_HAND => throw new \LogicException('Main hand is not an equipment container.'),
        };

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            [new InventorySlotReference($container, $slot, 0)],
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function pluginArmorContents(SetPluginArmorContents $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        foreach ($command->contents as $stack) {
            if (!$this->pluginInventoryStackIsAdmitted($stack)) {
                return new CommandRejected($command->session, 'unsupported_item');
            }
        }
        $proposed = clone $player->inventory;
        try {
            $proposed->replaceArmorContents($command->contents);
        } catch (InvalidArgumentException|OverflowException) {
            return new CommandRejected($command->session, 'invalid_item');
        }
        /** @var list<array{ApiEquipmentSlot, ?InventoryStack, ?InventoryStack}> $changes */
        $changes = [];
        foreach (self::equipmentChanges($player->inventory, $proposed) as [$slot, $previous, $next]) {
            if ($slot === ApiEquipmentSlot::OFF_HAND) {
                continue;
            }
            if ($this->pluginEvents !== null) {
                $event = $this->pluginEvents->equipmentChange($player, $slot, $previous, $next);
                if ($event === null) {
                    return new CommandRejected($command->session, 'plugin_cancelled');
                }
                try {
                    $next = $event->item() === null ? null : $this->inventoryStackFromApi($event->item());
                    if (!$this->pluginInventoryStackIsAdmitted($next)) {
                        return new CommandRejected($command->session, 'plugin_result');
                    }
                    self::replaceEquipment($proposed, $slot, $next);
                } catch (InvalidArgumentException|OverflowException) {
                    return new CommandRejected($command->session, 'plugin_result');
                }
            }
            $changes[] = [$slot, $previous, $next];
        }
        try {
            $player->inventory->replaceArmorContents($proposed->armorSlots());
        } catch (InvalidArgumentException|OverflowException) {
            throw new \LogicException('Validated armor contents could not be committed.');
        }
        if ($changes !== []) {
            $player->markDirty();
        }
        foreach ($changes as [$slot, $previous, $next]) {
            $this->pluginEvents?->equipmentChanged($player, $slot, $previous, $next);
        }
        $affected = array_map(
            static fn(array $change): InventorySlotReference => new InventorySlotReference(
                InventoryContainer::Armor,
                match ($change[0]) {
                    ApiEquipmentSlot::HEAD => ArmorSlot::Head->value,
                    ApiEquipmentSlot::CHEST => ArmorSlot::Chest->value,
                    ApiEquipmentSlot::LEGS => ArmorSlot::Legs->value,
                    ApiEquipmentSlot::FEET => ArmorSlot::Feet->value,
                    ApiEquipmentSlot::MAIN_HAND, ApiEquipmentSlot::OFF_HAND => throw new \LogicException(
                        'Armor contents produced a non-armor slot.',
                    ),
                },
                0,
            ),
            $changes,
        );

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            $affected,
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function pluginInventoryProjection(Player $player, PlayerInventory $before): InventoryStackRequestProcessed
    {
        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            self::changedMainInventorySlots($before, $player->inventory),
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            !self::sameInventoryStack($before->selectedStack(), $player->inventory->selectedStack()),
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    private function pluginInventoryStackIsAdmitted(?InventoryStack $stack): bool
    {
        if ($stack === null) {
            return true;
        }
        if ($this->itemCatalog !== null) {
            return $this->itemCatalog->has($stack->identifier)
                && $stack->count <= $this->itemCatalog->type($stack->identifier)->maximumStackSize;
        }

        return SupportedInventoryItem::supports($stack->identifier)
            && $stack->count <= SupportedInventoryItem::maximumStackSize($stack->identifier);
    }

    private function blockIdentifier(int $state): string
    {
        if ($this->blockStateRegistry !== null) {
            return $this->blockStateRegistry->state(new InternalBlockStateId($state))->identifier();
        }
        if ($this->blockPalette === null) {
            return 'minecraft:air';
        }

        return match ($state) {
            $this->blockPalette->air->value => 'minecraft:air',
            $this->blockPalette->bedrock->value => 'minecraft:bedrock',
            $this->blockPalette->dirt->value => 'minecraft:dirt',
            $this->blockPalette->grassBlock->value => 'minecraft:grass_block',
            default => 'minecraft:air',
        };
    }

    private function fluidState(InternalBlockStateId $state): ?FluidState
    {
        if ($this->blockStateRegistry === null) {
            return null;
        }

        return FluidState::fromCanonical($this->blockStateRegistry->state($state));
    }

    private function isReplaceablePlacementState(InternalBlockStateId $state): bool
    {
        return $state->value === $this->blockPalette?->air->value || $this->fluidState($state) !== null;
    }

    private function itemTouchesLava(DroppedItemEntity $entity): bool
    {
        if ($this->blockWorld === null || $this->blockStateRegistry === null) {
            return false;
        }
        $blockY = (int) floor($entity->position->y);
        if ($blockY < \Bedriox\Server\World\Chunk::MIN_Y || $blockY > \Bedriox\Server\World\Chunk::MAX_Y) {
            return false;
        }
        $state = $this->blockWorld->loadedBlockStateAt(
            (int) floor($entity->position->x),
            $blockY,
            (int) floor($entity->position->z),
        );
        if ($state === null) {
            return false;
        }
        $fluid = $this->fluidState($state);

        return $fluid?->type === FluidType::LAVA
            && $blockY + $fluid->height() > $entity->position->y;
    }

    /**
     * Creates a bounded vanilla-style orb split. The entire admission is rejected when capacity is insufficient.
     *
     * @return list<ExperienceOrbSpawned>
     */
    public function spawnExperienceOrbs(int $totalExperience, Position $position): array
    {
        $values = ExperienceOrbRegistry::splitValues($totalExperience);
        if (count($values) > $this->experienceOrbs->remainingCapacity()) {
            return [];
        }
        $events = [];
        $recipients = $this->players->recipients();
        foreach ($values as $value) {
            $value = $this->pluginEvents?->experienceOrbSpawn($position, $value) ?? ($this->pluginEvents === null ? $value : null);
            if ($value === null) {
                continue;
            }
            $entity = $this->experienceOrbs->spawn(
                $value,
                $position,
                new ExperienceOrbMotion(0.0, 0.1, 0.0),
                pickupDelayTicks: 10,
            );
            $events[] = new ExperienceOrbSpawned($entity, $recipients);
            $this->pluginEvents?->experienceOrbSpawned($entity->runtimeEntityId, $entity->position, $entity->value);
        }

        return $events;
    }

    /** @return list<WorldEvent> */
    private function advanceExperienceOrbs(): array
    {
        if ($this->experienceOrbs->count() === 0) {
            return [];
        }
        $targets = [];
        foreach ($this->players->players() as $player) {
            $targets[] = new ExperienceOrbTarget(
                $player->sessionId,
                $player->runtimeActorId,
                new Position(
                    $player->movement->position->x,
                    $player->movement->position->y + 0.9,
                    $player->movement->position->z,
                ),
                $player->vitals->isAlive(),
                $player->gameMode() === GameMode::SPECTATOR,
            );
        }
        $result = $this->experienceOrbs->tick($targets, $this->experienceOrbCollisions);
        $recipients = $this->players->recipients();
        $events = [];
        $pickedUp = [];
        foreach ($result->pickups as $pickup) {
            $pickedUp[$pickup->runtimeEntityId] = true;
            $player = $this->players->player($pickup->collectorSessionId);
            if ($player === null) {
                continue;
            }
            $remainingExperience = $this->applyMending($player, $pickup->awardedExperience);
            $previous = $player->experience->snapshot();
            $requestedTotal = min(0x7fffffff, $previous->totalPoints + $remainingExperience);
            $requested = $this->pluginEvents === null
                ? new \Bedriox\Api\Player\ExperienceSnapshot($requestedTotal)
                : $this->pluginEvents->experienceChange($player, $requestedTotal, \Bedriox\Api\Player\ExperienceChangeCause::ORB);
            if ($requested === null) {
                $replacement = $this->experienceOrbs->spawn(
                    $pickup->awardedExperience,
                    $player->movement->position,
                    pickupDelayTicks: 10,
                );
                $events[] = new ExperienceOrbSpawned($replacement, $recipients);
                continue;
            }
            $player->experience->setTotalPoints($requested->totalPoints);
            $this->pluginEvents?->experienceChanged($player, $previous, \Bedriox\Api\Player\ExperienceChangeCause::ORB);
            $events[] = new ExperienceOrbPickedUp(
                $pickup->runtimeEntityId,
                $pickup->collectorRuntimeActorId,
                $pickup->collectorSessionId,
                $pickup->awardedExperience,
                $pickup->removed,
                $recipients,
            );
            $events[] = new PlayerExperienceChanged(
                $player->snapshot(),
                $previous,
                \Bedriox\Api\Player\ExperienceChangeCause::ORB,
            );
        }
        foreach ($result->updated as $entity) {
            if (!isset($pickedUp[$entity->runtimeEntityId])) {
                $events[] = new ExperienceOrbMoved($entity, $this->tick, $recipients);
            }
        }
        foreach ($result->removed as $entity) {
            if (!isset($pickedUp[$entity->runtimeEntityId])) {
                $events[] = new ExperienceOrbRemoved($entity->runtimeEntityId, $recipients);
            }
        }

        return $events;
    }

    private function applyMending(Player $player, int $experience): int
    {
        while ($experience > 0) {
            /** @var list<array{ApiEquipmentSlot, InventoryStack, ArmorSlot|int|null}> $candidates */
            $candidates = [];
            $held = $player->inventory->selectedStack();
            if ($held !== null && $held->damage > 0
                && EnchantmentEffects::level($held->nbt, VanillaEnchantments::MENDING) > 0) {
                $candidates[] = [ApiEquipmentSlot::MAIN_HAND, $held, $player->inventory->selectedHotbarSlot()];
            }
            $offhand = $player->inventory->offhandStack();
            if ($offhand !== null && $offhand->damage > 0
                && EnchantmentEffects::level($offhand->nbt, VanillaEnchantments::MENDING) > 0) {
                $candidates[] = [ApiEquipmentSlot::OFF_HAND, $offhand, null];
            }
            foreach (ArmorSlot::cases() as $armorSlot) {
                $armor = $player->inventory->armorStack($armorSlot);
                if ($armor !== null && $armor->damage > 0
                    && EnchantmentEffects::level($armor->nbt, VanillaEnchantments::MENDING) > 0) {
                    $candidates[] = [self::apiEquipmentSlot($armorSlot), $armor, $armorSlot];
                }
            }
            if ($candidates === []) {
                break;
            }
            [$slot, $item, $storageSlot] = $candidates[$this->dropRandom->integer(0, count($candidates) - 1)];
            $repaired = min($item->damage, $experience * 2);
            $spent = (int) ceil($repaired / 2);
            $event = $this->pluginEvents?->itemMend($player, $item, $slot, $repaired, $spent);
            if ($this->pluginEvents !== null && $event === null) {
                break;
            }
            $repaired = min($item->damage, $event?->repairAmount() ?? $repaired);
            $spent = min($experience, $event?->experienceCost() ?? $spent);
            $replacement = $item->withDamage($item->damage - $repaired);
            if ($slot === ApiEquipmentSlot::MAIN_HAND && is_int($storageSlot)) {
                $player->inventory->replaceSlot($storageSlot, $replacement);
                $this->deferredEvents[] = new HeldItemChanged(
                    $player->sessionId,
                    $player->runtimeActorId,
                    $storageSlot,
                    $replacement,
                    $this->players->recipients($player->sessionId),
                    ownerSlotCorrection: true,
                );
            } elseif ($slot === ApiEquipmentSlot::OFF_HAND) {
                $player->inventory->replaceOffhand($replacement);
                $this->deferMendedEquipmentProjection($player, InventoryContainer::Offhand, 0);
            } elseif ($storageSlot instanceof ArmorSlot) {
                $player->inventory->replaceArmorSlot($storageSlot, $replacement);
                $this->deferMendedEquipmentProjection(
                    $player,
                    InventoryContainer::Armor,
                    $storageSlot->value,
                );
            }
            $player->markDirty();
            $this->pluginEvents?->itemMended($player, $item, $replacement, $slot, $repaired, $spent);
            $experience -= $spent;
        }

        return $experience;
    }

    private function deferMendedEquipmentProjection(
        Player $player,
        InventoryContainer $container,
        int $slot,
    ): void {
        $this->deferredEvents[] = new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            [new InventorySlotReference($container, $slot, 0)],
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $player->inventory->selectedStack(),
            false,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
    }

    /** @return list<WorldEvent> */
    private function advanceItemEntities(): array
    {
        if ($this->itemEntities->count() === 0) {
            return [];
        }
        $recipients = $this->players->recipients();
        $events = [];
        $previousPositions = [];
        $previousMotions = [];
        foreach ($this->itemEntities->all() as $entity) {
            $previousPositions[$entity->runtimeEntityId] = $entity->position;
            $previousMotions[$entity->runtimeEntityId] = $entity->motion;
        }
        $result = $this->itemEntities->tick();
        $movedEntities = [];
        foreach ($result->updated as $entity) {
            $previous = $previousPositions[$entity->runtimeEntityId] ?? null;
            if ($this->itemCollisionResolver !== null && $previous !== null) {
                $before = $entity->withPositionAndMotion($previous, $entity->motion);
                $entity = $this->itemCollisionResolver->resolve($before, $entity);
                $this->itemEntities->replace($entity);
            }
            if ($this->itemTouchesLava($entity)) {
                $this->itemEntities->remove($entity->runtimeEntityId);
                unset($this->itemPublishedMotions[$entity->runtimeEntityId]);
                $this->pendingItemDespawns[] = $entity->runtimeEntityId;
                continue;
            }
            if ($previous === null || $previous->x !== $entity->position->x
                || $previous->y !== $entity->position->y || $previous->z !== $entity->position->z) {
                $movedEntities[] = $entity;
            }
        }
        $movedCount = count($movedEntities);
        if ($movedCount > 0) {
            $start = $this->itemMovementCursor % $movedCount;
            $limit = min(self::MAXIMUM_ITEM_MOVEMENT_EVENTS_PER_TICK, $movedCount);
            for ($offset = 0; $offset < $limit; ++$offset) {
                $entity = $movedEntities[($start + $offset) % $movedCount];
                $runtimeId = $entity->runtimeEntityId;
                $lastMotion = $this->itemPublishedMotions[$runtimeId]
                    ?? $previousMotions[$runtimeId]
                    ?? $entity->motion;
                $dx = $entity->motion->x - $lastMotion->x;
                $dy = $entity->motion->y - $lastMotion->y;
                $dz = $entity->motion->z - $lastMotion->z;
                $stopped = $entity->motion->x === 0.0 && $entity->motion->y === 0.0
                    && $entity->motion->z === 0.0
                    && ($lastMotion->x !== 0.0 || $lastMotion->y !== 0.0 || $lastMotion->z !== 0.0);
                $motionChanged = ($dx * $dx) + ($dy * $dy) + ($dz * $dz) > 0.0025 || $stopped;
                if ($motionChanged) {
                    $this->itemPublishedMotions[$runtimeId] = $entity->motion;
                } elseif (!isset($this->itemPublishedMotions[$runtimeId])) {
                    $this->itemPublishedMotions[$runtimeId] = $lastMotion;
                }
                $events[] = new ItemEntityMoved($entity, $this->tick, $recipients, $motionChanged);
            }
            $this->itemMovementCursor = ($start + $limit) % $movedCount;
        }
        foreach ($result->despawned as $entity) {
            unset($this->itemPublishedMotions[$entity->runtimeEntityId]);
            $this->pendingItemDespawns[] = $entity->runtimeEntityId;
        }
        for ($count = 0; $count < self::MAXIMUM_ITEM_DESPAWN_EVENTS_PER_TICK
            && $this->pendingItemDespawns !== []; ++$count) {
            $runtimeId = array_shift($this->pendingItemDespawns);
            $events[] = new ItemEntityDespawned($runtimeId, $recipients);
        }
        foreach ($this->players->players() as $player) {
            if (!$player->vitals->isAlive() || $player->gameMode() === GameMode::SPECTATOR) {
                continue;
            }
            foreach ($this->itemEntities->nearbyPickupCandidates($player->movement->position, 1.5, 16) as $entity) {
                $allowedCount = $entity->stack->count;
                if ($this->pluginEvents !== null) {
                    $allowedCount = $this->pluginEvents->pickupItem($player, $entity->stack);
                    if ($allowedCount === null) {
                        continue;
                    }
                }
                $candidate = $entity->stack->withCountAndNetworkId(
                    $allowedCount,
                    $entity->stack->stackNetworkId,
                );
                $beforeCount = $candidate->count;
                $remainder = $player->inventory->add($candidate);
                $accepted = $beforeCount - ($remainder->count ?? 0);
                if ($accepted === 0) {
                    continue;
                }
                $pickup = $this->itemEntities->pickup($entity->runtimeEntityId, $accepted);
                if ($pickup === null) {
                    continue;
                }
                unset($this->itemPublishedMotions[$entity->runtimeEntityId]);
                $replacement = null;
                if ($pickup->remaining !== null) {
                    $this->itemEntities->remove($entity->runtimeEntityId);
                    $remainingLifetime = $entity->despawnAfterTicks === null
                        ? null
                        : max(1, $entity->despawnAfterTicks - $entity->ageTicks);
                    $replacement = $this->itemEntities->spawn(
                        $pickup->remaining,
                        $entity->position,
                        $entity->motion,
                        despawnAfterTicks: $remainingLifetime,
                    );
                }
                $player->markDirty();
                $this->pluginEvents?->pickedUpItem($player, $pickup->pickedUp);
                $events[] = new ItemEntityPickedUp(
                    $entity->runtimeEntityId,
                    $player->runtimeActorId,
                    $pickup->pickedUp,
                    $player->sessionId,
                    true,
                    $player->inventory->slots(),
                    $recipients,
                );
                if ($replacement !== null) {
                    $events[] = new ItemEntitySpawned($replacement, $recipients);
                }
            }
        }

        return $events;
    }

    private static function adjacentBlock(BlockPosition $position, int $face): ?BlockPosition
    {
        $coordinates = match ($face) {
            0 => [$position->x, $position->y - 1, $position->z],
            1 => [$position->x, $position->y + 1, $position->z],
            2 => [$position->x, $position->y, $position->z - 1],
            3 => [$position->x, $position->y, $position->z + 1],
            4 => [$position->x - 1, $position->y, $position->z],
            5 => [$position->x + 1, $position->y, $position->z],
            default => null,
        };
        if ($coordinates === null) {
            return null;
        }
        [$x, $y, $z] = $coordinates;
        if ($x < -30_000_000 || $x > 30_000_000 || $z < -30_000_000 || $z > 30_000_000
            || $y < \Bedriox\Server\World\Chunk::MIN_Y || $y > \Bedriox\Server\World\Chunk::MAX_Y) {
            return null;
        }

        return new BlockPosition($x, $y, $z);
    }

}
