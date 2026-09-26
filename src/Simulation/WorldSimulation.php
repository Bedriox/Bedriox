<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Api\Crafting\CraftingGrid as ApiCraftingGrid;
use Bedriox\Api\Crafting\CraftingRecipe as ApiCraftingRecipe;
use Bedriox\Api\Crafting\RecipeIngredient as ApiRecipeIngredient;
use Bedriox\Api\Crafting\ShapedRecipe as ApiShapedRecipe;
use Bedriox\Api\Crafting\ShapelessRecipe as ApiShapelessRecipe;
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause as ApiEntityDamageCause;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Entity\EntityDamageByEntityEvent;
use Bedriox\Api\Event\Inventory\InventoryCloseReason as ApiInventoryCloseReason;
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
use Bedriox\Api\TranslatableMessage;
use Bedriox\Data\BlockPropertyRegistry;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Data\EntityTypeRegistry;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiMeleeIntent;
use Bedriox\Server\Entity\Ai\AiPlayerSnapshot;
use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;
use Bedriox\Server\Entity\Ai\IndexedAiWorldView;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\EntityPhysicsResolver;
use Bedriox\Server\Entity\EntityRegistry;
use Bedriox\Server\Entity\EntityWorldRuntime;
use Bedriox\Server\Entity\Equipment\EntityEquipmentTransition;
use Bedriox\Server\Entity\Item\DroppedItemCollisionResolver;
use Bedriox\Server\Entity\Item\ItemEntityMotion;
use Bedriox\Server\Entity\Item\ItemEntityRegistry;
use Bedriox\Server\Entity\Loot\EntityLootResolver;
use Bedriox\Server\Entity\Loot\EquippedLootItem;
use Bedriox\Server\Entity\Loot\GameplayLootItemRegistry;
use Bedriox\Server\Entity\Loot\LootContext;
use Bedriox\Server\Entity\Persistence\EntityPersistenceFlushResult;
use Bedriox\Server\Entity\Persistence\EntityPersistenceManager;
use Bedriox\Server\Entity\Persistence\EntityPersistenceStore;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnOutcome;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Entity\Spawn\EntitySpawnService;
use Bedriox\Server\Entity\Spawn\Natural\NaturalSpawnPlayer;
use Bedriox\Server\Entity\Spawn\Natural\WorldNaturalSpawnRuntime;
use Bedriox\Server\Entity\WorldEntityEnvironment;
use Bedriox\Server\Gameplay\Block\BlockBreakContext;
use Bedriox\Server\Gameplay\Block\BlockBreakRules;
use Bedriox\Server\Gameplay\Block\BlockCatalog;
use Bedriox\Server\Gameplay\Block\BlockDropRules;
use Bedriox\Server\Gameplay\Block\BlockPlacementStateResolver;
use Bedriox\Server\Gameplay\Block\BlockType;
use Bedriox\Server\Gameplay\Block\DropRandom;
use Bedriox\Server\Gameplay\Block\SystemDropRandom;
use Bedriox\Server\Gameplay\Crafting\ComplexCraftingRecipeEvaluator;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Crafting\CraftingGrid;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipe;
use Bedriox\Server\Gameplay\Crafting\CraftingRecipeMatch;
use Bedriox\Server\Gameplay\Crafting\RecipeIngredient;
use Bedriox\Server\Gameplay\Crafting\RecipeOutput;
use Bedriox\Server\Gameplay\Crafting\ShapedRecipe;
use Bedriox\Server\Gameplay\Crafting\ShapelessRecipe;
use Bedriox\Server\Gameplay\Item\ArmorSlot;
use Bedriox\Server\Gameplay\Item\ItemBehaviorRegistry;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Item\ItemUseSession;
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
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\AttackPlayer;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\ChangeGameMode;
use Bedriox\Server\Simulation\Command\CloseContainer;
use Bedriox\Server\Simulation\Command\CloseCraftingGrid;
use Bedriox\Server\Simulation\Command\DamageEntity;
use Bedriox\Server\Simulation\Command\DamagePlayer;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\DropItem;
use Bedriox\Server\Simulation\Command\GiveItem;
use Bedriox\Server\Simulation\Command\InteractEntity;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\ReleaseItem;
use Bedriox\Server\Simulation\Command\RespawnPlayer;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SendPluginMessage;
use Bedriox\Server\Simulation\Command\SetPluginBlock;
use Bedriox\Server\Simulation\Command\SetPluginInventorySlot;
use Bedriox\Server\Simulation\Command\SwingArm;
use Bedriox\Server\Simulation\Command\SyncInventory;
use Bedriox\Server\Simulation\Command\SyncInventorySlots;
use Bedriox\Server\Simulation\Command\TeleportPlayer;
use Bedriox\Server\Simulation\Command\UseItem;
use Bedriox\Server\Simulation\Command\WorldCommand;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockEntityChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\ContainerClosed;
use Bedriox\Server\Simulation\Event\ContainerContentsChanged;
use Bedriox\Server\Simulation\Event\ContainerOpened;
use Bedriox\Server\Simulation\Event\ContainerViewerProjection;
use Bedriox\Server\Simulation\Event\CraftingTableOpened;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\EntityActorAttackStarted;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorEquipmentChanged;
use Bedriox\Server\Simulation\Event\EntityActorHealthChanged;
use Bedriox\Server\Simulation\Event\EntityActorMetadataChanged;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\EntityInteracted;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InstantItemUsed;
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
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerGameModeChanged;
use Bedriox\Server\Simulation\Event\PlayerHealed;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
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
use Bedriox\Server\World\World;
use InvalidArgumentException;
use OverflowException;
use SplQueue;

final class WorldSimulation
{
    private const int DAYLIGHT_FIRE_DURATION_TICKS = 160;
    private const float FIRE_TICK_DAMAGE = 1.0;

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

    private readonly ?WorldNaturalSpawnRuntime $naturalSpawns;

    /** @var array<int, int> Runtime actor ID to removal tick after its death animation. */
    private array $entityDeathRemovalTicks = [];

    /** @var array<int, int> Runtime actor ID to last immune tick. */
    private array $entityInvulnerableUntilTicks = [];

    /** @var array<int, \Bedriox\Api\Event\Entity\EntityDamageEvent> */
    private array $entityLastDamageEvents = [];
    private int $itemMovementCursor = 0;

    /** @var array<int, ItemEntityMotion> Last motion published for each live item actor. */
    private array $itemPublishedMotions = [];

    /** @var list<int> */
    private array $pendingItemDespawns = [];

    /** @var list<WorldEvent> */
    private array $deferredEvents = [];

    /** @var array<string, ItemUseSession> One transient action per connected session. */
    private array $activeItemUses = [];

    /** @var array<string, array<string, int>> Session, canonical identifier, expiry tick. */
    private array $itemCooldowns = [];

    /** @var array<string, int> Last successful completion tick per connected session. */
    private array $lastItemUseCompletionTicks = [];

    private readonly ItemBehaviorRegistry $itemBehaviors;

    private readonly ?WorldContainerStore $worldContainers;

    private readonly ShulkerBoxItemNbtCodec $shulkerItems;

    /** @var array<string, PlayerContainerSession> Session map key to its one authorized dynamic window. */
    private array $openContainers = [];

    /** @var array<string, int> Session map key to the next candidate dynamic window ID. */
    private array $nextContainerWindowIds = [];

    /** @var array<string, VirtualContainer> Plugin-owned virtual inventories. */
    private array $virtualContainers = [];

    private int $nextVirtualContainerId = 1;

    private readonly ?ComplexCraftingRecipeEvaluator $complexCraftingRecipes;

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
    ) {
        $this->commands = new SplQueue();
        $this->lifecycleCommands = new SplQueue();
        $this->movementOrder = new SplQueue();
        $this->validator = new SimulationCommandFactory($this->limits);
        $this->players = new PlayerRegistry($this->limits->maximumPlayers);
        $this->dropRandom = $dropRandom ?? new SystemDropRandom();
        $this->itemEntities = $itemEntities ?? new ItemEntityRegistry(firstEntityId: 1_000_000_000);
        $this->entityLoot = $itemCatalog === null
            ? null
            : EntityLootResolver::vanilla(new GameplayLootItemRegistry($itemCatalog));
        $this->itemBehaviors = $itemBehaviors ?? ItemBehaviorRegistry::vanilla();
        $this->worldContainers = $blockWorld === null ? null : new WorldContainerStore($blockWorld);
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
                $collisionQuery === null ? null : new EntityPhysicsResolver($collisionQuery),
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
            )
            : null;
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
        );
    }

    /**
     * Saves a deterministic bounded set of dirty online players and queued failed snapshots.
     *
     * @return array{saved: int, remaining: int}
     */
    public function autosavePlayers(int $budget): array
    {
        if ($this->playerPersistence === null || $budget < 1) {
            return ['saved' => 0, 'remaining' => 0];
        }
        $saved = $this->playerPersistence->retryPending($budget);
        foreach ($this->players->players() as $player) {
            if ($saved >= $budget) {
                break;
            }
            if ($player->isDirty() && $this->playerPersistence->save($player)) {
                ++$saved;
            }
        }

        $remaining = $this->playerPersistence->pendingCount();
        foreach ($this->players->players() as $player) {
            $remaining += (int) $player->isDirty();
        }

        return ['saved' => $saved, 'remaining' => $remaining];
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
        ++$this->tick;
        $this->blockWorld?->advanceTime();
        [$processed, $events] = $this->processLifecycleCommands($this->limits->maximumCommandsPerTick);

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

        array_push($events, ...$this->advancePendingRespawns());
        array_push($events, ...$this->advanceItemUseSessions());
        array_push($events, ...$this->advanceNutrition());
        array_push($events, ...$this->advanceBlockBreakParticles());
        array_push($events, ...$this->advanceItemEntities());
        array_push($events, ...$this->advanceNaturalEntities());
        array_push($events, ...$this->advanceGeneralEntities());
        array_push($events, ...$this->drainDeferredEvents());

        return new SimulationTick($this->tick, $processed, $events);
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
            $this->blockWorld->metadata->name,
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

    public function dirtyEntityChunkCount(): int
    {
        return $this->entityPersistence?->dirtyChunkCount() ?? 0;
    }

    public function flushEntityPersistence(): EntityPersistenceFlushResult
    {
        return $this->entityPersistence?->flushShutdown()
            ?? new EntityPersistenceFlushResult(0, 0, []);
    }

    public function entityRuntime(): EntityWorldRuntime
    {
        return $this->entityRuntime;
    }

    private function prepareLivingEntity(AbstractEntity $entity, bool $initialSpawn): void
    {
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
        $entity->configureControllerTransformHandler(function (
            string $worldName,
            Position $position,
            float $yaw,
            float $pitch,
        ) use ($entity): void {
            $activeWorld = $this->blockWorld?->metadata->name ?? $entity->getWorldName();
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
        if ($entity->spawnOrigin() !== SpawnCause::NATURAL
            || $entity->getType()->identifier() !== 'minecraft:zombie'
            || $entity->equipmentState()->getContents() !== []) {
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
                    $player->worldName,
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

    /** @return list<WorldEvent> */
    private function advanceGeneralEntities(): array
    {
        $events = [];
        $this->entityAiPlayers = array_map(
            fn(Player $player): AiPlayerSnapshot => new AiPlayerSnapshot(
                $player->identity->uuid,
                $this->blockWorld?->metadata->name ?? 'world',
                $player->movement->position,
                $player->vitals->isAlive() && $player->gameMode()->takesDamage(),
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
        array_push($events, ...$this->advanceEntityFire());
        $tick = $this->entityRuntime->tick(
            $this->tick,
            $this->entityAiWorld,
            $this->entityAiEnabled,
        );
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
        foreach ($this->announcedEntities as $runtimeId => $entity) {
            $health = $entity->getHealth();
            $previous = $this->publishedEntityHealth[$runtimeId] ?? $health;
            if ($health === $previous) {
                continue;
            }
            $this->publishedEntityHealth[$runtimeId] = $health;
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
            $intent = $entity->aiRuntime()->takeMeleeIntent($this->tick);
            if ($intent !== null) {
                array_push($events, ...$this->applyEntityMeleeIntent($entity, $intent));
            }
        }
        foreach ($tick->died as $entity) {
            $lastDamage = $this->entityLastDamageEvents[$entity->getRuntimeId()] ?? null;
            $drops = $this->prepareEntityDeathDrops($entity, $lastDamage);
            if ($this->pluginEvents !== null) {
                $drops = $this->pluginEvents->entityDied($entity, $lastDamage, $drops);
            }
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

        $this->entityPersistence?->synchronize(256);

        return $events;
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
            if ($wasOnFire && $this->entityEnvironment->isTouchingWater($entity)) {
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
        }

        return $events;
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
        $reducedDamage = $this->armorReducedDamage($target, $baseDamage, DamageCause::Attack);
        $damage = $this->pluginEvents?->damage($target, DamageCause::Attack, $reducedDamage)
            ?? ($this->pluginEvents === null ? $reducedDamage : null);
        if ($damage === null || $damage <= 0.0) {
            return [];
        }
        $applied = min($damage, $target->vitals->health);
        $target->vitals->health -= $applied;
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
        [$motionX, $motionY, $motionZ] = self::composeKnockback(
            $movement->velocityX,
            $movement->verticalVelocity,
            $movement->velocityZ,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE * (1.0 - $target->inventory->knockbackResistance()),
            $movement->verticalState === VerticalState::GROUNDED,
        );
        $movement->velocityX = $motionX;
        $movement->verticalVelocity = $motionY;
        $movement->velocityZ = $motionZ;
        if ($motionY > 0.0) {
            $movement->verticalState = VerticalState::AIRBORNE;
        }
        $movement->jumpAuthorizedUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $target->markDirty();
        $this->pluginEvents?->damaged($target, DamageCause::Attack, $applied);

        $recipients = $this->players->recipients();
        $events = [
            new PlayerDamaged(
                $target->snapshot(),
                $applied,
                DamageCause::Attack,
                $recipients,
                $equipmentChanged,
            ),
            new PlayerKnockedBack(
                $target->sessionId,
                $target->snapshot(),
                $motionX,
                $motionY,
                $motionZ,
                $movement->clientTick,
                $recipients,
            ),
            new EntityActorAttackStarted($attacker, $recipients),
        ];
        if (!$target->vitals->isAlive()) {
            $target->movement->fallDistance = 0.0;
            $events[] = $this->deathEvent($target, DamageCause::Attack, $damage);
        }

        return $events;
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
                $position->y + ($attacker->definition()->height * 0.85),
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

    public function enqueuePluginBlock(string $plugin, BlockPosition $position, string $identifier): bool
    {
        return $this->enqueue($this->validator->pluginBlock($plugin, $position, $identifier));
    }

    public function enqueuePluginInventorySlot(string $identity, int $slot, ?InventoryStack $stack): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->pluginInventorySlot($player->sessionId, $slot, $stack));
    }

    public function enqueueGameMode(string $identity, GameMode $gameMode): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null
            && $this->enqueue($this->validator->changeGameMode($player->sessionId, $gameMode));
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
            $command instanceof SetPluginInventorySlot => $this->pluginInventorySlot($command),
            $command instanceof DamageEntity => $this->damageEntity($command),
            $command instanceof DamagePlayer => $this->damage($command),
            $command instanceof RespawnPlayer => $this->respawn($command),
            $command instanceof AcknowledgeRespawn => $this->acknowledgeRespawn($command),
            $command instanceof UseItem => $this->useItem($command),
            $command instanceof ReleaseItem => $this->releaseItem($command),
            default => null,
        };

        $this->reconcileActiveItemUse($this->players->player($command->sessionId()));

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
        $overflow = max(0, $command->amount - $player->inventory->addableQuantity($prototype));
        $requiredEntities = (int) ceil($overflow / $type->maximumStackSize);
        if ($requiredEntities > self::MAXIMUM_ITEM_ENTITIES_SPAWNED_PER_COMMAND
            || $requiredEntities > $this->itemEntities->remainingCapacity()) {
            return new CommandRejected($command->session, 'item_entity_capacity');
        }
        $remaining = $command->amount;
        while ($remaining > 0) {
            $count = min($remaining, $type->maximumStackSize);
            $overflow = $player->inventory->add(new InventoryStack(
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
                $entity = $this->itemEntities->spawn(
                    $overflow,
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
        }
        $player->markDirty();
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
        foreach ($this->announcedEntities as $entity) {
            if ($entity->isAlive()) {
                $this->deferredEvents[] = new EntityActorSpawned(
                    $entity,
                    [$player->sessionId],
                    !$this->entityAiEnabled || ($entity instanceof AbstractMobEntity && !$entity->isAiEnabled()),
                );
            }
        }
        if ($runtimeActorId >= $this->nextRuntimeActorId && $runtimeActorId < PHP_INT_MAX) {
            $this->nextRuntimeActorId = $runtimeActorId + 1;
        }
        $this->pluginEvents?->joined($player);

        return new PlayerJoined($player->snapshot(), $peers, $this->players->recipients());
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
        $budget = $this->limits->maximumMovementPerTick * $elapsed;
        if ($distance > $budget - $movement->distanceThisTick) {
            return new MovementCorrected($player->snapshot(), 'movement_rate', clientTick: $command->clientTick);
        }

        $wasGrounded = $movement->verticalState === VerticalState::GROUNDED;
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
            $resolved = $this->collisionResolver->resolve($movement->position, $requested, $wasGrounded);
            $position = $resolved->position;
            $grounded = $this->collisionResolver->isGrounded($position);
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
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowMove($player, $position)) {
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
        if (!$flying && $sprinting && $player->gameMode()->consumesItems() && $horizontalDistance > 0.0) {
            $this->applyMovementExhaustion(
                $player,
                self::SPRINTING_EXHAUSTION_PER_BLOCK * $horizontalDistance,
            );
        }

        $snapshot = $player->snapshot();
        $this->pluginEvents?->moved($player);
        if ($flying || !$player->gameMode()->takesDamage()) {
            $movement->fallDistance = 0.0;
        } elseif ($verticalDistance < $movement->fallDistance) {
            $movement->fallDistance -= $verticalDistance;
        } else {
            $movement->fallDistance = 0.0;
        }
        if ($grounded && $movement->fallDistance > 0.0) {
            $damage = ceil($movement->fallDistance - 3.0);
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
        $openContainer = $this->openContainers[self::sessionKey($player->sessionId)] ?? null;
        if ($openContainer instanceof PlayerContainerSession && $openContainer->position !== null
            && !$this->blockIsReachable($snapshot, $openContainer->position)) {
            $closed = $this->closeContainer($player, ApiInventoryCloseReason::OUT_OF_RANGE, true);
            if ($closed !== null) {
                $this->deferredEvents[] = $closed;
            }
        }

        return new PlayerMoved($snapshot, $this->players->recipients($player->sessionId), $postureChanged);
    }

    private function applyMovementExhaustion(Player $player, float $amount): void
    {
        $previousNutrition = PluginGameplayEventBridge::nutrition($player);
        $stagedVitals = clone $player->vitals;
        $stagedVitals->exhaust($amount);
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
                ApiFoodLevelChangeCause::EXHAUSTION,
            );
            if ($proposedNutrition === null) {
                return;
            }
        }
        if ($proposedNutrition == $previousNutrition) {
            return;
        }
        $player->vitals->setNutrition(
            $proposedNutrition->foodLevel,
            $proposedNutrition->saturationLevel,
            $proposedNutrition->exhaustionLevel,
        );
        $player->markDirty();
        $currentNutrition = PluginGameplayEventBridge::nutrition($player);
        $this->pluginEvents?->nutritionChanged(
            $player,
            $previousNutrition,
            $currentNutrition,
            ApiFoodLevelChangeCause::EXHAUSTION,
        );
        $this->deferredEvents[] = new NutritionChanged(
            $player->snapshot(),
            $previousNutrition->foodLevel,
            $previousNutrition->saturationLevel,
            $previousNutrition->exhaustionLevel,
            NutritionChangeReason::EXHAUSTION,
            [$player->sessionId],
        );
    }

    private function damage(DamagePlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if (!$player->vitals->isAlive()) {
            return new CommandRejected($command->session, 'player_dead');
        }
        $isKill = $command->cause === DamageCause::Kill;
        if (!$isKill && !$player->gameMode()->takesDamage()) {
            return new CommandRejected($command->session, 'gamemode_invulnerable');
        }
        if (!$isKill && $this->tick <= $player->vitals->invulnerableUntilTick) {
            return new CommandRejected($command->session, 'damage_cooldown');
        }
        $baseDamage = $command->amount;
        $reducedDamage = $isKill ? $baseDamage : $this->armorReducedDamage($player, $baseDamage, $command->cause);
        $damage = $this->pluginEvents === null
            ? $reducedDamage
            : $this->pluginEvents->damage($player, $command->cause, $reducedDamage);
        if ($damage === null) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $applied = min($damage, $player->vitals->health);
        $player->vitals->health -= $applied;
        if (!$isKill) {
            $player->vitals->invulnerableUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        }
        $equipmentChanged = !$isKill && $player->vitals->isAlive()
            && $this->damageArmor($player, $baseDamage, $command->cause);
        $player->markDirty();
        $this->pluginEvents?->damaged($player, $command->cause, $applied);
        if (!$player->vitals->isAlive()) {
            $player->movement->fallDistance = 0.0;
            $this->deferredEvents[] = $this->deathEvent($player, $command->cause, $damage);
        }

        return new PlayerDamaged(
            $player->snapshot(),
            $applied,
            $command->cause,
            $this->players->recipients(),
            $equipmentChanged,
        );
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

        $baseDamage = $this->meleeDamage($attacker);
        $reducedDamage = $this->armorReducedDamage($target, $baseDamage, DamageCause::Attack);
        $damage = $this->pluginEvents?->attack($attacker, $target, $reducedDamage)
            ?? ($this->pluginEvents === null ? $reducedDamage : null);
        if ($damage === null || $damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }

        $applied = min($damage, $target->vitals->health);
        $target->vitals->health -= $applied;
        $target->vitals->invulnerableUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $equipmentChanged = $target->vitals->isAlive()
            && $this->damageArmor($target, $baseDamage, DamageCause::Attack);
        [$directionX, $directionZ] = $this->knockbackDirection($attacker, $target);
        $movement = $target->movement;
        $wasGrounded = $movement->verticalState === VerticalState::GROUNDED;
        $knockbackMultiplier = 1.0 - $target->inventory->knockbackResistance();
        [$motionX, $motionY, $motionZ] = self::composeKnockback(
            $movement->velocityX,
            $movement->verticalVelocity,
            $movement->velocityZ,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE * $knockbackMultiplier,
            $wasGrounded,
        );
        $sprintingAttack = $attacker->movement->sprinting;
        if ($sprintingAttack) {
            [$motionX, $motionY, $motionZ] = self::composeKnockback(
                $motionX,
                $motionY,
                $motionZ,
                $directionX,
                $directionZ,
                CombatRules::KNOCKBACK_FORCE * CombatRules::SPRINT_KNOCKBACK_STRENGTH * $knockbackMultiplier,
                false,
            );
        }
        $movement->velocityX = $motionX;
        $movement->verticalVelocity = $motionY;
        $movement->velocityZ = $motionZ;
        if ($motionY > 0.0) {
            $movement->verticalState = VerticalState::AIRBORNE;
        }
        $target->movement->jumpAuthorizedUntilTick = $this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS;
        $target->markDirty();
        $this->damageHeldTool($attacker, $this->heldItemType($attacker), attack: true);

        $this->pluginEvents?->damaged($target, DamageCause::Attack, $applied);
        $this->pluginEvents?->attacked($attacker, $target, $applied);
        $this->deferredEvents[] = new PlayerKnockedBack(
            $attacker->sessionId,
            $target->snapshot(),
            $motionX,
            $motionY,
            $motionZ,
            $movement->clientTick,
            $this->players->recipients(),
        );
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

        $baseDamage = $this->meleeDamage($attacker);
        $reducedDamage = $this->entityArmorReducedDamage($target, $baseDamage);
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
        [$motionX, $motionY, $motionZ] = self::composeKnockback(
            $motion->x,
            $motion->y,
            $motion->z,
            $directionX,
            $directionZ,
            CombatRules::KNOCKBACK_FORCE,
            $target->isOnGround(),
        );
        $target->setMotion(new EntityMotion($motionX, $motionY, $motionZ));
        if ($target instanceof AbstractMobEntity) {
            $target->suppressAiMovementUntil($this->tick + CombatRules::DAMAGE_IMMUNITY_TICKS);
        }
        $this->damageHeldTool($attacker, $this->heldItemType($attacker), attack: true);
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

        return new EntityInteracted(
            $command->session,
            $target->getRuntimeId(),
            $command->interaction,
        );
    }

    private function generalEntityIsReachable(Player $attacker, AbstractLivingEntity $target): bool
    {
        $from = $attacker->movement->position;
        $to = $target->internalPosition();

        return hypot(
            hypot($to->x - $from->x, $to->z - $from->z),
            ($to->y + ($target->definition()->height / 2.0)) - ($from->y + 1.62),
        ) <= CombatRules::MAXIMUM_ENTITY_REACH;
    }

    private function entityIsReachable(Player $attacker, Player $target): bool
    {
        $from = $attacker->movement->position;
        $to = $target->movement->position;
        $eyeX = $from->x;
        $eyeY = $from->y + 1.62;
        $eyeZ = $from->z;
        if (hypot(hypot($to->x - $eyeX, $to->z - $eyeZ), $to->y - $eyeY) > CombatRules::MAXIMUM_ENTITY_REACH) {
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

    /** @return array{float, float, float} */
    private static function composeKnockback(
        float $motionX,
        float $motionY,
        float $motionZ,
        float $directionX,
        float $directionZ,
        float $force,
        bool $grounded,
    ): array {
        return [
            ($motionX / 2.0) + ($directionX * $force),
            $grounded ? min(CombatRules::KNOCKBACK_VERTICAL_LIMIT, ($motionY / 2.0) + $force) : $motionY,
            ($motionZ / 2.0) + ($directionZ * $force),
        ];
    }

    private function deathEvent(
        Player $player,
        DamageCause $cause,
        float $damage,
        ?Player $killer = null,
    ): PlayerDied {
        $closed = $this->closeContainer($player, ApiInventoryCloseReason::DEATH, true);
        if ($closed !== null) {
            $this->deferredEvents[] = $closed;
        }
        $this->evacuateCraftingGrid($player, $this->players->recipients());
        $player->movement->velocityX = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->velocityZ = 0.0;
        $message = match ($cause) {
            DamageCause::Attack => new TranslatableMessage(
                'death.attack.player',
                [$player->identity->displayName, $killer?->identity->displayName ?? $player->identity->displayName],
            ),
            DamageCause::Fall => new TranslatableMessage(
                $damage > 2.0 ? 'death.fell.accident.generic' : 'death.attack.fall',
                [$player->identity->displayName],
            ),
            DamageCause::Kill,
            DamageCause::Plugin => new TranslatableMessage('death.attack.generic', [$player->identity->displayName]),
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
                    flying: $command->flying,
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
                $command instanceof SetPluginBlock => $this->validator->pluginBlock(
                    $command->plugin,
                    $command->position,
                    $command->identifier,
                ),
                $command instanceof SetPluginInventorySlot => $this->validator->pluginInventorySlot(
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
        $queuedCommands = $this->commands->count();
        while ($queuedCommands-- > 0) {
            $queued = $this->commands->dequeue();
            if ($queued->sessionId() === $command->session) {
                $queuedBytes = $queued->estimatedBytes();
                $this->queuedCommandBytes -= $queuedBytes;
                $this->queuedBytes -= $queuedBytes;
            } else {
                $this->commands->enqueue($queued);
            }
        }
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
            $breakRate = $player->gameMode()->instantlyBreaksBlocks() || $blockType === null
                ? ($player->gameMode()->instantlyBreaksBlocks() ? 65_535 : self::EMPTY_HAND_GRASS_BREAK_RATE)
                : BlockBreakRules::networkBreakRate(
                    $blockType,
                    $heldType,
                    new BlockBreakContext(airborne: $player->movement->verticalState === VerticalState::AIRBORNE),
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
        if ($this->pluginEvents !== null && !$this->pluginEvents->allowBlockBreak($player, $position, $identifier)) {
            return new BlockChanged($command->session, $position, $state, [$command->session], $stopsActiveBreak);
        }
        $heldType = $this->heldItemType($player);
        $survivalBreak = $player->gameMode() === GameMode::SURVIVAL
            && $blockType !== null && $this->itemCatalog !== null;
        $drops = $survivalBreak
            ? BlockDropRules::drops($blockType, $heldType, $this->dropRandom)
            : [];
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
            $this->blockWorld->setBlockState($position->x, $position->y, $position->z, $this->blockPalette->air);
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
            foreach ($drops as $drop) {
                $type = $this->itemCatalog->type($drop->identifier);
                $placed = $type->placedBlockState === null
                    ? null
                    : $this->blockStateRegistry->internalId($type->placedBlockState);
                $entity = $this->itemEntities->spawn(
                    new InventoryStack($drop->identifier, $drop->count, 1, $placed, nbt: $shulkerNbt),
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
        $this->pluginEvents?->blockBroken($player, $position, $identifier);

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

            return $this->consumeHeldItem($player, $active);
        }
        if ($held === null) {
            return new CommandRejected($command->session, 'empty_hand');
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
        $reason = $command->hotbarSlot === $player->inventory->selectedHotbarSlot()
            ? ItemUseCancellationReason::RELEASED
            : ItemUseCancellationReason::HELD_ITEM_CHANGED;

        return $this->cancelItemUse($player, $reason);
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
            if ($player->vitals->food < self::NATURAL_REGENERATION_FOOD_THRESHOLD
                || $player->vitals->health >= \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH) {
                continue;
            }
            $healed = min(
                self::NATURAL_REGENERATION_HEALTH,
                \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH - $player->vitals->health,
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
                    \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH - $player->vitals->health,
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

    private function armorReducedDamage(Player $player, float $damage, DamageCause $cause): float
    {
        if ($cause === DamageCause::Fall) {
            return $damage;
        }

        return max(0.0, $damage * (1.0 - ($player->inventory->defensePoints() * 0.04)));
    }

    private function entityArmorReducedDamage(AbstractLivingEntity $entity, float $damage): float
    {
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

        return max(0.0, $damage * (1.0 - (min(20, $defense) * 0.04)));
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
            $damage = $item->damage + $wear;
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
            $wear = $this->pluginEvents?->itemDamage(
                $player,
                $stack,
                ApiItemDamageCause::DAMAGE_ABSORPTION,
                $apiSlot,
                $defaultWear,
            ) ?? ($this->pluginEvents === null ? $defaultWear : null);
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
        $reason = $command->rejectionReason;
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
        $result = $reason === null
            ? $proposedPlayer->applyOpenedContainerStackRequest(
                $command->requestId,
                $command->actions,
                $proposedProjection,
            )
            : new InventoryStackRequestResult(false, reason: $reason);
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
                $player->markDirty();
                if ($playerInventoryChanged) {
                    $this->pluginEvents?->inventoryChanged($player, $beforePlayer);
                }
                if ($transaction !== null) {
                    $this->pluginEvents?->containerTransactionCommitted($player, $transaction);
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

    private function refreshContainerProjection(Player $player, PlayerContainerSession $session): void
    {
        $session->projection = $player->inventory->projectOpenedContainer(
            $session->inventory->identifier(),
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

        return new ApiInventoryTransaction(
            'container:' . $command->requestId . ':' . strtolower($player->identity->uuid),
            ApiInventoryTransactionCause::PLAYER,
            $before,
            $after,
            $actions,
        );
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

    /**
     * @return list<array{ApiEquipmentSlot, ?InventoryStack, ?InventoryStack}>
     */
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
        $placedState = $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z);
        $key = self::sessionKey($command->session);
        $activeBreak = $this->breakingBlocks[$key] ?? null;
        unset($this->breakingBlocks[$key]);
        $held = $player->inventory->selectedStack();
        $heldType = $held !== null && $this->itemCatalog?->has($held->identifier) === true
            ? $this->itemCatalog->type($held->identifier)
            : null;
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
            $placedBlockState = $heldType?->placedBlockState !== null && $this->blockPlacementStates !== null
                ? $this->blockPlacementStates->resolve(
                    $heldType->placedBlockState,
                    $command->face,
                    $player->movement->yaw,
                )
                : $held?->placedBlockState;
        } catch (InvalidArgumentException) {
            $placedBlockState = null;
            $placementStateValid = false;
        }
        $placedIdentifier = $heldType?->placedBlockState?->identifier() ?? $held?->identifier;
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
            $placedState->value !== $this->blockPalette->air->value => 'occupied',
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
            $this->blockWorld->setBlockState(
                $placedPosition->x,
                $placedPosition->y,
                $placedPosition->z,
                $placedBlockState,
            );
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
        $placedIdentifier = $heldType?->placedBlockState?->identifier() ?? $held->identifier;
        if ($storageEntity !== null) {
            $this->installStorageBlockEntity($player, $storageEntity, $placedIdentifier);
        }
        $this->refreshPlayerGroundStates();
        $remaining = $player->gameMode()->consumesItems()
            ? $player->inventory->decrementSelectedOne()
            : $held;
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
            : new \Bedriox\Api\Entity\VanillaEntityIdentifier($definition->identifier());
        if ($failure === null && $type === null) {
            $failure = 'unsupported_entity';
        }
        if ($failure === null) {
            $outcome = $this->spawnEntity(new EntitySpawnRequest(
                $type,
                SpawnCause::SPAWN_EGG,
                $this->blockWorld?->metadata->name ?? 'world',
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
        $this->blockWorld->setBlockState($position->x, $position->y, $position->z, $updated);

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
            $this->blockWorld->setBlockState(
                $command->position->x,
                $command->position->y,
                $command->position->z,
                $state,
            );
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

    private function pluginInventorySlot(SetPluginInventorySlot $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        $selectedBefore = $player->inventory->selectedStack();
        $player->inventory->replaceSlot($command->slot, $command->stack);
        $player->markDirty();
        $selectedAfter = $player->inventory->selectedStack();
        $selectedChanged = $command->slot === $player->inventory->selectedHotbarSlot();

        return new InventoryStackRequestProcessed(
            $player->sessionId,
            0,
            true,
            [new InventorySlotReference(InventoryContainer::Main, $command->slot, 0)],
            $player->inventory->slots(),
            $player->inventory->cursorStack(),
            $player->inventory->selectedHotbarSlot(),
            $selectedAfter,
            $selectedChanged && $selectedBefore !== $selectedAfter,
            $player->runtimeActorId,
            $this->players->recipients($player->sessionId),
            responseMode: InventoryResponseMode::LegacySlotSync,
            armorInventory: $player->inventory->armorSlots(),
            offhandStack: $player->inventory->offhandStack(),
            craftingInventory: $player->inventory->craftingSlots(),
        );
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
