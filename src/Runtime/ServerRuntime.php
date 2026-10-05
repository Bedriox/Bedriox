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

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\CustomEntityType;
use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\VanillaEntityIdentifier;
use Bedriox\Api\Entity\VanillaEntityType;
use Bedriox\Api\Event\Player\PlayerKickCause;
use Bedriox\Api\Event\Player\PlayerQuitCause;
use Bedriox\Api\Event\World\WeatherChangeCause;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Api\Inventory\ItemStack as ApiItemStack;
use Bedriox\Api\Player\ExperienceChangeCause;
use Bedriox\Api\Player\GameMode;
use Bedriox\Api\Player\Player as ApiPlayer;
use Bedriox\Api\TextFormat;
use Bedriox\Api\World\PortalType;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Api\World\WeatherState;
use Bedriox\Api\World\WeatherType;
use Bedriox\Api\World\World as ApiWorld;
use Bedriox\Api\World\WorldDimension;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\DimensionId;
use Bedriox\Protocol\Packet\DisconnectPacket;
use Bedriox\Protocol\Packet\DisconnectReason;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\PlayerListRemovePacket;
use Bedriox\Protocol\Packet\SetDifficultyPacket;
use Bedriox\Protocol\Packet\SetSpawnPositionPacket;
use Bedriox\Protocol\Packet\SetTimePacket;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionCloseReason;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\Server\Access\BanManager;
use Bedriox\Server\Access\WhitelistManager;
use Bedriox\Server\Command\CommandFeedback;
use Bedriox\Server\Entity\AbstractLivingEntity;
use Bedriox\Server\Entity\Ai\AiSchedulerMetrics;
use Bedriox\Server\Entity\EntityRuntimeMetrics;
use Bedriox\Server\Entity\Item\DroppedItemEntity;
use Bedriox\Server\Entity\Spawn\EntitySpawnRequest;
use Bedriox\Server\Gameplay\Crafting\CraftingCatalog;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Bedriox\Server\Gameplay\Potion\AreaEffectCloud;
use Bedriox\Server\Gameplay\Projectile\Projectile;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\CrashPlayer;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\GarbageCollector;
use Bedriox\Server\Observability\Memory\MemoryManagementDecision;
use Bedriox\Server\Observability\Memory\MemoryManager;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\PerformanceSubsystem;
use Bedriox\Server\Observability\PlayerLifecycleLogger;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Persistence\PersistenceQueueSnapshot;
use Bedriox\Server\Persistence\PersistenceSubmission;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Command\ServerPlayerCommandSender;
use Bedriox\Server\Plugin\PluginActionBuffer;
use Bedriox\Server\Simulation\Event\AreaEffectCloudRemoved;
use Bedriox\Server\Simulation\Event\AreaEffectCloudSpawned;
use Bedriox\Server\Simulation\Event\AreaEffectCloudUpdated;
use Bedriox\Server\Simulation\Event\ArmSwung;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\EndPortalTransferRequested;
use Bedriox\Server\Simulation\Event\EntityActorDamaged;
use Bedriox\Server\Simulation\Event\EntityActorDied;
use Bedriox\Server\Simulation\Event\EntityActorMoved;
use Bedriox\Server\Simulation\Event\EntityActorRemoved;
use Bedriox\Server\Simulation\Event\EntityActorSpawned;
use Bedriox\Server\Simulation\Event\EntityInteracted;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemEntityDespawned;
use Bedriox\Server\Simulation\Event\ItemEntityMoved;
use Bedriox\Server\Simulation\Event\ItemEntityPickedUp;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\NutritionChanged;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerGameModeChanged;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerKnockedBack;
use Bedriox\Server\Simulation\Event\PlayerMotionChanged;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\PortalTransferRequested;
use Bedriox\Server\Simulation\Event\PotionSplashImpacted;
use Bedriox\Server\Simulation\Event\ProjectileMoved;
use Bedriox\Server\Simulation\Event\ProjectileRemoved;
use Bedriox\Server\Simulation\Event\ProjectileSpawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\Position;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Transport\ConnectedTransport;
use Bedriox\Server\Transport\NetworkCompressionPolicy;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Chunk\PreparedChunkCacheSnapshot;
use Bedriox\Server\World\Block\BlockStateRegistry;
use Bedriox\Server\World\ChunkRepositorySnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use Bedriox\Server\World\World;
use Bedriox\Server\World\WorldTimeRules;
use Closure;
use InvalidArgumentException;
use OverflowException;
use RuntimeException;
use SplObjectStorage;
use SplQueue;
use Throwable;

/** Bounded single-threaded composition root for transport, protocol, and simulation. */
final class ServerRuntime implements RuntimeDriver, RuntimeFailureSource, RuntimeIdleAdvisor
{
    private const int MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGES = 256;
    private const int MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGE_BYTES = 4_096;
    private const int DEFERRED_WORLD_PACKETS_PER_TICK = 4_096;
    private const int DEFERRED_WORLD_PACKETS_PER_SESSION_PER_TICK = 64;
    private const int ACTOR_VISIBILITY_PAIRS_PER_TICK = 16;
    private const int ACTOR_VISIBILITY_BUDGET_NANOSECONDS = 5_000_000;
    private const int PLAYER_ADMISSIONS_PER_TICK = 2;
    private const int PLAY_SESSION_MAINTENANCE_INTERVAL_NANOSECONDS = 50_000_000;
    private const int CHUNK_STREAMING_BUDGET_NANOSECONDS = 8_000_000;
    private const int RUNTIME_STAGE_TRACE_THRESHOLD_NANOSECONDS = 10_000_000;
    private const int WORLD_TRANSFER_TIMEOUT_NANOSECONDS = 10_000_000_000;
    /** Maximum actor-recipient fan-out is kept bounded while simulation remains at 20 TPS. */
    private const int PEER_MOVEMENT_BROADCAST_INTERVAL_TICKS = 2;

    /** @var array<string, RuntimeSession> endpoint key => session */
    private array $sessions = [];

    /** @var array<string, string> runtime session ID => endpoint key */
    private array $sessionEndpoints = [];

    /** @var array<string, RuntimeSession> runtime session ID => session */
    private array $sessionsById = [];

    private bool $closed = false;
    private int $nextRuntimeEntityId = 1;
    private int $serverTick = 0;
    private readonly SimulationCommandFactory $commands;
    private readonly RuntimeDiagnostics $diagnostics;
    private readonly PlayerActorVisibilityRegistry $actorVisibility;
    private readonly PlayerConnectionDirectory $playerConnections;
    private ?Throwable $failure = null;
    private ?string $involvedSessionId = null;
    /** @var array<string, true> */
    private array $autosaveActive = [];
    private bool $autosaveEnabled = true;
    private GameMode $defaultGameMode;
    /** @var array<string, true> */
    private array $entityAutosaveActive = [];
    /** @var array<string, true> */
    private array $playerAutosaveActive = [];
    private ?MemoryManagementDecision $lastMemoryDecision = null;
    private ?GarbageCollectionReport $lastGarbageCollection = null;
    private ?ChunkUnloadResult $lastChunkUnload = null;
    private int $totalChunksUnloaded = 0;
    private int $totalPreparedBytesTrimmed = 0;
    private int $commandSchemaRevision = 0;
    private int $onlinePlayerCommandRevision = 0;
    /** @var array<string, int> */
    private array $deliveredOnlinePlayerCommandRevisions = [];
    private int $chunkStreamingCursor = 0;
    private int $chunkAdmissionStreamingCursor = 0;
    /** @var array<string, int> */
    private array $movementCorrectionTraceCounts = [];
    private int $movementCorrectionTraceStartedNanoseconds = 0;
    private int $movementProjectionTracePackets = 0;
    private int $movementProjectionTraceBatches = 0;
    private int $movementProjectionTraceRecipientDeliveries = 0;
    private int $movementProjectionTraceDroppedPackets = 0;
    private int $movementProjectionTraceDroppedBatches = 0;
    private int $movementProjectionTraceSuppressedRecipients = 0;
    private int $movementProjectionTraceStartedNanoseconds = 0;
    /** @var array<string, int> Last tick whose authoritative position was projected to peers. */
    private array $lastPeerMovementBroadcastTicks = [];
    /** @var array<string, int> Stable per-session phase seed used to spread peer fan-out across ticks. */
    private array $peerMovementBroadcastPhaseSeeds = [];
    /** @var array<int, int> Last tick whose non-player actor position was projected to viewers. */
    private array $lastEntityMovementBroadcastTicks = [];
    /** @var array<string, DeferredWorldPacketQueue> Runtime session ID => ordered authoritative packets. */
    private array $deferredWorldPackets = [];
    /** @var array<string, list<PacketFrame>> Newest authoritative movement batch awaiting output capacity. */
    private array $deferredAuthoritativeMovementFrames = [];
    /** @var SplQueue<array{string, string}> Ordered viewer/actor pairs awaiting reconciliation. */
    private readonly SplQueue $pendingActorVisibilityPairs;
    /** @var array<string, true> */
    private array $pendingActorVisibilityPairKeys = [];
    private int $deferredWorldPacketCursor = 0;
    private int $performanceTraceStartedNanoseconds = 0;
    private int $crashContextPublishedNanoseconds = 0;
    private bool $crashContextDirty = false;
    private int $nextPlaySessionMaintenanceNanoseconds = 0;
    private int $admissionCommandsQueued = 0;
    private ?string $processingWorldId = null;
    /** @var array<string, PendingPlayerWorldTransfer> Runtime session ID => staged transfer. */
    private array $pendingPlayerWorldTransfers = [];
    /** @var array<string, array{type: PortalType, from: ApiPosition, destination: ApiPosition}> Runtime session ID => portal event views. */
    private array $pendingPortalTravelNotifications = [];
    /**
     * @var array<string, array{
     *     count: int,
     *     total_nanoseconds: int,
     *     maximum_nanoseconds: int,
     *     tick: int|null,
     *     fields: array<string, int|string|bool|null>
     * }>
     */
    private array $slowRuntimeStages = [];
    /** @var array<string, true> */
    private array $joiningLogs = [];
    /** @var array<string, true> */
    private array $joinedLogs = [];
    /** @var array<string, true> */
    private array $failedJoinLogs = [];

    /** @var array<string, array{SessionInfo, int}> */
    private array $pendingTransportCloses = [];
    private readonly SimulationClock $closeClock;

    /** @var array<string, array<int, DroppedItemEntity>> */
    private array $itemActors = [];

    /** @var array<string, array<int, array<string, true>>> */
    private array $itemActorViewers = [];

    /** @var array<string, array<int, AbstractLivingEntity>> */
    private array $entityActors = [];

    /** @var array<string, array<int, bool>> */
    private array $entityActorNoAi = [];

    /** @var array<string, array<int, array<string, true>>> */
    private array $entityActorViewers = [];

    /** @var array<string, array<int, Projectile>> */
    private array $projectileActors = [];

    /** @var array<string, array<int, array<string, true>>> */
    private array $projectileViewers = [];

    /** @var array<string, array<int, AreaEffectCloud>> */
    private array $areaEffectCloudActors = [];

    /** @var array<string, array<int, array<string, true>>> */
    private array $areaEffectCloudViewers = [];

    public function __construct(
        private readonly ConnectedTransport $transport,
        private readonly LoginChannelFactory $loginChannels,
        private readonly PlayChannelFactory $playChannels,
        private readonly WorldSimulation $world,
        private readonly FixedRateWorldLoop $worldLoop,
        private readonly WorldEventPacketEncoder $eventEncoder,
        private readonly RuntimeLimits $limits = new RuntimeLimits(),
        ?SimulationCommandFactory $commands = null,
        ?RuntimeDiagnostics $diagnostics = null,
        private readonly ?CrashContextPublisher $crashContext = null,
        private readonly ?World $persistentWorld = null,
        private readonly int $autosaveIntervalTicks = 6_000,
        private readonly int $autosaveChunkBudget = 8,
        private readonly ?PlayerPersistenceManager $playerPersistence = null,
        private readonly int $playerAutosaveIntervalTicks = 6_000,
        private readonly int $playerAutosaveBudget = 8,
        private readonly ?CommandRegistry $commandRegistry = null,
        private readonly ?PermissionStore $permissionStore = null,
        ?PlayerConnectionDirectory $playerConnections = null,
        private readonly ?BedrockInventoryPacketProjector $inventoryProjector = null,
        private readonly ?PluginGameplayEventBridge $pluginEvents = null,
        private readonly ?Closure $simulationTickBoundary = null,
        private readonly ?PerformanceMonitor $performance = null,
        ?SimulationClock $closeClock = null,
        private readonly ?PreparedChunkCache $preparedChunks = null,
        private readonly ?MemoryManager $memoryManager = null,
        private readonly ?GarbageCollector $garbageCollector = null,
        private readonly int $chunkUnloadPerTick = 96,
        private readonly ?CraftingCatalog $craftingCatalog = null,
        private readonly int $chunksGeneratePerTick = 4,
        private readonly int $chunksSendPerTick = 8,
        private readonly ?WorldRuntimeManager $worldRuntimes = null,
        private readonly ?WorldOperationQueue $worldOperations = null,
        private readonly ?ItemCatalog $itemCatalog = null,
        private readonly ?BlockStateRegistry $blockStateRegistry = null,
        private readonly ?PluginActionBuffer $pluginActions = null,
        private readonly ?WhitelistManager $whitelist = null,
        private readonly ?BanManager $bans = null,
        private readonly ?PlayerLifecycleLogger $playerLifecycleLogger = null,
    ) {
        $this->defaultGameMode = $this->playerPersistence?->defaultGameMode() ?? GameMode::SURVIVAL;
        if ($this->autosaveIntervalTicks < 1 || $this->autosaveChunkBudget < 1
            || $this->playerAutosaveIntervalTicks < 1 || $this->playerAutosaveBudget < 1
            || $this->chunkUnloadPerTick < 1 || $this->chunkUnloadPerTick > 1_024
            || $this->chunksGeneratePerTick < 1 || $this->chunksGeneratePerTick > 64
            || $this->chunksSendPerTick < 1 || $this->chunksSendPerTick > 64) {
            throw new InvalidArgumentException('Autosave interval and chunk budget must be positive.');
        }
        $this->commands = $commands ?? new SimulationCommandFactory();
        $this->diagnostics = $diagnostics ?? RuntimeDiagnostics::disabled();
        $this->actorVisibility = new PlayerActorVisibilityRegistry($this->limits->maximumSessions);
        $this->pendingActorVisibilityPairs = new SplQueue();
        $this->playerConnections = $playerConnections ?? new PlayerConnectionDirectory();
        $this->closeClock = $closeClock ?? new SystemSimulationClock();
        $this->commandSchemaRevision = $this->commandRegistry?->schemaRevision() ?? 0;
    }

    public function __destruct()
    {
        $this->close();
    }

    /** @return list<\Bedriox\Api\Player\Player> */
    public function onlinePlayers(): array
    {
        $players = [];
        foreach ($this->simulations() as $simulation) {
            array_push($players, ...$simulation->pluginPlayers());
        }

        return array_map($this->playerConnections->attach(...), $players);
    }

    public function enforceWhitelist(): void
    {
        if ($this->whitelist?->isEnabled() !== true) {
            return;
        }
        foreach ($this->sessions as $key => $session) {
            if (!$session->joined || $session->play === null) {
                continue;
            }
            $login = $session->play->login();
            if (($this->permissionStore?->isOperator($login->identity) ?? false)
                || $this->whitelist->contains($login->displayName, $login->identity)) {
                continue;
            }
            $this->kickPlayer($key, $session, 'You are not whitelisted on this server.', null, null, PlayerKickCause::WHITELIST);
        }
    }

    public function remoteAddressForPlayer(string $name): ?string
    {
        foreach ($this->sessions as $session) {
            if ($session->play !== null && strcasecmp($session->play->login()->displayName, $name) === 0) {
                return $session->transport->remoteAddress;
            }
        }
        return null;
    }

    public function kickAddress(string $address, string $reason, ?string $actor = null): int
    {
        $kicked = 0;
        foreach ($this->sessions as $key => $session) {
            if ($session->transport->remoteAddress !== $address || $session->play === null) {
                continue;
            }
            if ($this->kickPlayer(
                $key,
                $session,
                $reason,
                null,
                'You are banned from this server.' . "\n" . $reason,
                PlayerKickCause::BAN,
                $actor,
            )) {
                ++$kicked;
            }
        }
        return $kicked;
    }

    /** @return list<\Bedriox\Api\Entity\Entity> */
    public function entities(): array
    {
        $entities = [];
        foreach ($this->simulations() as $simulation) {
            array_push($entities, ...$simulation->entityRuntime()->registry()->all());
        }

        return $entities;
    }

    public function killTarget(ApiPlayer|ApiEntity $target): bool
    {
        foreach ($this->simulations() as $simulation) {
            $accepted = $target instanceof ApiPlayer
                ? $simulation->enqueueKillPlayer($target->uuid)
                : $simulation->enqueueKillEntity($target->getRuntimeId(), $target->getUniqueId());
            if ($accepted) {
                return true;
            }
        }

        return false;
    }

    public function changePlayerGameMode(string $uuid, GameMode $gameMode): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueueGameMode($uuid, $gameMode) ?? false;
    }

    public function defaultGameMode(): GameMode
    {
        return $this->defaultGameMode;
    }

    public function setDefaultGameMode(GameMode $gameMode): bool
    {
        if ($this->defaultGameMode === $gameMode) {
            return false;
        }
        $this->defaultGameMode = $gameMode;
        $this->playerPersistence?->setDefaultGameMode($gameMode);
        if ($this->playChannels instanceof BedrockPlayChannelFactory) {
            $this->playChannels->setDefaultGameMode($gameMode);
        }

        return true;
    }

    public function setPlayerSpawnPoint(string $uuid, ApiPosition $position): bool
    {
        $simulation = $this->simulationForIdentity($uuid);
        if ($simulation === null) {
            return false;
        }
        $player = $simulation->authoritativePlayer($uuid);
        if ($player === null || $position->world === null || $position->world->id() !== $player->worldName()) {
            return false;
        }

        return $simulation->setPlayerSpawnPoint(
            $uuid,
            new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
        );
    }

    public function autosaveEnabled(): bool
    {
        return $this->autosaveEnabled;
    }

    public function setAutosaveEnabled(bool $enabled): bool
    {
        if ($this->autosaveEnabled === $enabled) {
            return false;
        }
        $this->autosaveEnabled = $enabled;
        if (!$enabled) {
            $this->autosaveActive = [];
            $this->entityAutosaveActive = [];
            $this->playerAutosaveActive = [];
        }

        return true;
    }

    public function worldDifficulty(?\Bedriox\Api\World\World $world = null): ?\Bedriox\Api\World\WorldDifficulty
    {
        $runtime = $world === null ? $this->worldRuntimes?->default() : $this->worldRuntimes?->get($world->id());
        if ($runtime === null || ($world !== null && !$runtime->handle->isSameLoad($world))) {
            return null;
        }

        return match ($runtime->opened->world->difficulty()) {
            0 => \Bedriox\Api\World\WorldDifficulty::PEACEFUL,
            1 => \Bedriox\Api\World\WorldDifficulty::EASY,
            2 => \Bedriox\Api\World\WorldDifficulty::NORMAL,
            3 => \Bedriox\Api\World\WorldDifficulty::HARD,
            default => throw new \LogicException('Loaded world difficulty is invalid.'),
        };
    }

    public function setWorldDifficulty(
        ?\Bedriox\Api\World\World $world,
        \Bedriox\Api\World\WorldDifficulty $difficulty,
    ): bool {
        $runtime = $world === null ? $this->worldRuntimes?->default() : $this->worldRuntimes?->get($world->id());
        if ($runtime === null || ($world !== null && !$runtime->handle->isSameLoad($world))) {
            return false;
        }
        $value = match ($difficulty) {
            \Bedriox\Api\World\WorldDifficulty::PEACEFUL => 0,
            \Bedriox\Api\World\WorldDifficulty::EASY => 1,
            \Bedriox\Api\World\WorldDifficulty::NORMAL => 2,
            \Bedriox\Api\World\WorldDifficulty::HARD => 3,
        };
        if ($runtime->opened->world->difficulty() === $value) {
            return true;
        }
        $runtime->opened->world->setDifficulty($value);
        $runtime->opened->world->scheduleWorldDataSave();
        $packet = new SetDifficultyPacket($value);
        foreach ($this->sessions as $key => $session) {
            if ($session->joined && $session->play !== null && $session->worldId === $runtime->handle->id()
                && !$this->queueWorldPacket($session, $packet)) {
                $this->disconnect($key, 'world_difficulty_backlog_exhausted');
            }
        }

        return true;
    }

    public function setWorldSpawn(\Bedriox\Api\World\World $world, \Bedriox\Api\World\BlockPosition $position): bool
    {
        $runtime = $this->worldRuntimes?->get($world->id(), $position->dimension);
        if ($runtime === null || !$runtime->handle->isSameLoad($world)) {
            return false;
        }
        $runtime->opened->world->setSpawn(new \Bedriox\Server\World\SpawnPosition($position->x, $position->y, $position->z));
        $runtime->opened->world->scheduleWorldDataSave();
        $packet = new SetSpawnPositionPacket(
            0,
            $position->x,
            $position->y,
            $position->z,
            self::protocolDimension($position->dimension)->value,
            $position->x,
            $position->y,
            $position->z,
        );
        foreach ($this->sessions as $key => $session) {
            if ($session->joined && $session->play !== null && $session->worldId === $runtime->handle->id()
                && $session->dimension === $position->dimension
                && !$this->queueWorldPacket($session, $packet)) {
                $this->disconnect($key, 'world_spawn_backlog_exhausted');
            }
        }

        return true;
    }

    public function givePlayerItem(string $uuid, string $identifier, int $amount): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueueGiveItem($uuid, $identifier, $amount) ?? false;
    }

    public function givePlayerStack(string $uuid, ApiItemStack $stack): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueueGiveItem(
            $uuid,
            $stack->identifier,
            $stack->count,
            $stack->damage,
            $stack->nbt,
            $stack->auxValue,
        ) ?? false;
    }

    public function setPlayerInventorySlot(string $uuid, int $slot, ?ApiItemStack $stack): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginInventorySlot(
            $uuid,
            $slot,
            $stack === null ? null : $this->apiInventoryStack($stack),
        ) ?? false;
    }

    /** @param list<ApiItemStack|null> $contents */
    public function setPlayerInventoryContents(string $uuid, array $contents): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginInventoryContents(
            $uuid,
            array_map(fn(?ApiItemStack $stack): ?InventoryStack => $stack === null ? null : $this->apiInventoryStack($stack), $contents),
        ) ?? false;
    }

    public function removePlayerInventoryItem(string $uuid, ApiItemStack $stack): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginInventoryRemoval(
            $uuid,
            $this->apiInventoryStack($stack),
        ) ?? false;
    }

    public function setPlayerSelectedHotbarSlot(string $uuid, int $slot): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginSelectedHotbarSlot($uuid, $slot) ?? false;
    }

    public function setPlayerEquipmentItem(string $uuid, EquipmentSlot $slot, ?ApiItemStack $stack): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginEquipmentSlot(
            $uuid,
            $slot,
            $stack === null ? null : $this->apiInventoryStack($stack),
        ) ?? false;
    }

    /** @param array<string, ApiItemStack|null> $contents */
    public function setPlayerArmorContents(string $uuid, array $contents): bool
    {
        $ordered = [];
        foreach ([EquipmentSlot::HEAD, EquipmentSlot::CHEST, EquipmentSlot::LEGS, EquipmentSlot::FEET] as $slot) {
            $stack = $contents[$slot->value] ?? null;
            $ordered[] = $stack === null ? null : $this->apiInventoryStack($stack);
        }

        return $this->simulationForIdentity($uuid)?->enqueuePluginArmorContents($uuid, $ordered) ?? false;
    }

    public function maximumPlayerStackSize(ApiItemStack $stack): int
    {
        $catalog = $this->itemCatalog
            ?? throw new RuntimeException('The authoritative item catalog is unavailable.');

        return $catalog->type($stack->identifier)->maximumStackSize;
    }

    public function damagePlayer(string $uuid, float $amount): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginDamage($uuid, $amount) ?? false;
    }

    public function mountedVehicle(string $uuid): ?ApiEntity
    {
        return $this->simulationForIdentity($uuid)?->mountedVehicle($uuid);
    }

    public function mountPlayer(string $uuid, ApiEntity $vehicle, MountSeat $seat): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueueMount($uuid, $vehicle, $seat) ?? false;
    }

    public function dismountPlayer(string $uuid): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueueDismount($uuid) ?? false;
    }

    public function addPlayerEffect(string $uuid, EffectInstance $effect, EffectCause $cause): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginEffect($uuid, $effect, $cause) ?? false;
    }

    public function removePlayerEffect(string $uuid, EffectType $type, EffectCause $cause): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginEffectRemoval($uuid, $type, $cause) ?? false;
    }

    public function clearPlayerEffects(string $uuid, EffectCause $cause): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePluginEffectClear($uuid, $cause) ?? false;
    }

    public function setPlayerExperience(string $uuid, int $totalPoints, ExperienceChangeCause $cause): bool
    {
        return $this->simulationForIdentity($uuid)?->enqueuePlayerExperience($uuid, $totalPoints, $cause) ?? false;
    }

    public function teleportPlayer(
        string $uuid,
        \Bedriox\Api\World\Position $position,
        ?float $yaw = null,
        ?float $pitch = null,
    ): bool {
        $sourceSimulation = $this->simulationForIdentity($uuid);
        if ($sourceSimulation === null) {
            return false;
        }
        $player = $sourceSimulation->authoritativePlayer($uuid);
        if ($player === null) {
            return false;
        }
        $session = $this->sessionById($player->sessionId);
        $sourceRuntime = $session === null ? null : $this->runtimeForSession($session);
        if ($this->worldRuntimes === null || $session?->play === null || $sourceRuntime === null) {
            if ($position->world !== null || $position->dimension !== null) {
                return false;
            }

            return $sourceSimulation->enqueueTeleport(
                $uuid,
                new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
                $position->yaw ?? $yaw,
                $position->pitch ?? $pitch,
            );
        }
        $targetHandle = $position->world ?? $sourceRuntime->handle;
        $targetDimension = $position->dimension ?? $session->dimension;
        $targetRuntime = $this->worldRuntimes->get($targetHandle->id(), $targetDimension);
        if ($targetRuntime === null || !$targetRuntime->handle->isSameLoad($targetHandle)) {
            return false;
        }
        if ($sourceRuntime === $targetRuntime) {
            return $sourceSimulation->enqueueTeleport(
                $uuid,
                new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
                $position->yaw ?? $yaw,
                $position->pitch ?? $pitch,
            );
        }
        if (!$targetRuntime->simulation->canAcceptTransferredPlayer($player)) {
            return false;
        }

        $from = $player->movement->position;
        $fromYaw = $player->movement->yaw;
        $fromPitch = $player->movement->pitch;
        $decision = $sourceSimulation->authorizeTransferTeleport(
            $player,
            new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
            $position->yaw ?? $yaw ?? $player->movement->yaw,
            $position->pitch ?? $pitch ?? $player->movement->pitch,
        );
        if ($decision === null) {
            return false;
        }
        if (isset($this->pendingPlayerWorldTransfers[$player->sessionId])
            || !$session->play->beginWorldSwitch(
                $targetRuntime->opened->world,
                $targetRuntime->preparedChunks,
                $decision->destination->x,
                $decision->destination->y,
                $decision->destination->z,
                $targetRuntime->opened->world->time(),
                $targetRuntime->opened->world->difficulty(),
                $session->dimension === $targetDimension ? null : self::protocolDimension($targetDimension),
            )) {
            return false;
        }
        $this->pendingPlayerWorldTransfers[$player->sessionId] = new PendingPlayerWorldTransfer(
            $uuid,
            $player->sessionId,
            $sourceRuntime->handle->id(),
            $session->dimension,
            $targetRuntime->handle->id(),
            $targetDimension,
            $decision,
            $from,
            $fromYaw,
            $fromPitch,
            hrtime(true),
        );

        return true;
    }

    private function processPendingPlayerWorldTransfers(): void
    {
        $now = hrtime(true);
        foreach ($this->pendingPlayerWorldTransfers as $sessionId => $transfer) {
            $session = $this->sessionById($sessionId);
            $source = $this->worldRuntimes?->get($transfer->sourceWorldId, $transfer->sourceDimension);
            $target = $this->worldRuntimes?->get($transfer->targetWorldId, $transfer->targetDimension);
            if ($session?->play === null || $source === null || $target === null
                || $session->worldId !== $transfer->sourceWorldId
                || $session->dimension !== $transfer->sourceDimension
                || $source->simulation->authoritativePlayer($transfer->identity) === null) {
                $session?->play?->abortWorldSwitch();
                $source?->simulation->rejectPortalTransfer($sessionId);
                unset($this->pendingPlayerWorldTransfers[$sessionId]);
                unset($this->pendingPortalTravelNotifications[$sessionId]);
                continue;
            }
            if ($now - $transfer->startedNanoseconds >= self::WORLD_TRANSFER_TIMEOUT_NANOSECONDS) {
                $session->play->abortWorldSwitch();
                $source->simulation->rejectPortalTransfer($sessionId);
                unset($this->pendingPlayerWorldTransfers[$sessionId]);
                unset($this->pendingPortalTravelNotifications[$sessionId]);
                $this->diagnostics->record('world.player_transfer_timed_out', [
                    'source_world' => $transfer->sourceWorldId,
                    'target_world' => $transfer->targetWorldId,
                ]);
                continue;
            }
            try {
                if (!$session->play->worldSwitchReady()) {
                    continue;
                }
                $completed = $this->completePlayerWorldTransfer($session, $source, $target, $transfer);
            } catch (Throwable $exception) {
                $this->diagnostics->record('world.player_transfer_failed', [
                    'source_world' => $transfer->sourceWorldId,
                    'target_world' => $transfer->targetWorldId,
                    'exception' => $exception::class,
                ]);
                $completed = false;
            }
            unset($this->pendingPlayerWorldTransfers[$sessionId]);
            if (!$completed) {
                $source->simulation->rejectPortalTransfer($sessionId);
                unset($this->pendingPortalTravelNotifications[$sessionId]);
                $session->play->abortWorldSwitch();
                $key = $this->sessionEndpoints[$sessionId] ?? null;
                if ($key !== null) {
                    $this->disconnect($key, 'world_transfer_failed');
                }
            }
        }
    }

    private function completePlayerWorldTransfer(
        RuntimeSession $session,
        ManagedWorldRuntime $source,
        ManagedWorldRuntime $target,
        PendingPlayerWorldTransfer $transfer,
    ): bool {
        $player = $source->simulation->authoritativePlayer($transfer->identity);
        if ($player === null || !$target->simulation->canAcceptTransferredPlayer($player)) {
            return false;
        }
        $departure = $source->simulation->detachPlayerForTransfer($session->id);
        if ($departure === null) {
            return false;
        }
        try {
            $session->worldId = $target->handle->id();
            $session->dimension = $transfer->targetDimension;
            $arrival = $target->simulation->attachTransferredPlayer(
                $departure->player,
                $session->worldId,
                $transfer->decision->destination,
                $transfer->decision->yaw,
                $transfer->decision->pitch,
            );
        } catch (Throwable) {
            $session->worldId = $transfer->sourceWorldId;
            $session->dimension = $transfer->sourceDimension;
            $source->simulation->attachTransferredPlayer(
                $departure->player,
                $transfer->sourceWorldId,
                $transfer->from,
                $transfer->fromYaw,
                $transfer->fromPitch,
            );

            return false;
        }

        $directedCount = 0;
        $transferCommitted = false;
        try {
            if ($departure->previousPeers !== [] && !$session->play?->queuePacket(new PlayerListRemovePacket(array_map(
                static fn(\Bedriox\Server\Simulation\PlayerSnapshot $peer): string => $peer->identity,
                $departure->previousPeers,
            )))) {
                return false;
            }
            $this->processingWorldId = self::runtimePollKey($transfer->sourceWorldId, $transfer->sourceDimension);
            foreach ($departure->events as $event) {
                if ($event instanceof PlayerDisconnected) {
                    foreach ($this->actorVisibility->remove($event->sessionId) as $visibilityEvent) {
                        if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                            return false;
                        }
                    }
                }
                if (!$this->dispatchWorldEvent($event, $directedCount)) {
                    return false;
                }
            }
            if (!$session->play?->commitWorldSwitch($transfer->decision->yaw, $transfer->decision->pitch)) {
                return false;
            }

            $this->processingWorldId = $this->runtimePollKeyForSession($session);
            foreach ($arrival->events as $event) {
                if ($event instanceof PlayerJoined) {
                    $this->actorVisibility->upsert($event->player);
                }
                $events = match (true) {
                    $event instanceof ItemEntitySpawned,
                    $event instanceof ItemEntityMoved,
                    $event instanceof ItemEntityPickedUp,
                    $event instanceof ItemEntityDespawned => $this->reconcileItemEvent($event),
                    $event instanceof EntityActorSpawned,
                    $event instanceof EntityActorMoved,
                    $event instanceof EntityActorDamaged,
                    $event instanceof EntityActorDied,
                    $event instanceof EntityActorRemoved => $this->reconcileEntityActorEvent($event),
                    $event instanceof ProjectileSpawned,
                    $event instanceof ProjectileMoved,
                    $event instanceof ProjectileRemoved => $this->reconcileProjectileEvent($event),
                    $event instanceof AreaEffectCloudSpawned,
                    $event instanceof AreaEffectCloudUpdated,
                    $event instanceof AreaEffectCloudRemoved => $this->reconcileAreaEffectCloudEvent($event),
                    default => [$event],
                };
                foreach ($events as $projected) {
                    if (!$this->dispatchWorldEvent($projected, $directedCount)) {
                        return false;
                    }
                }
            }
            $this->enqueueActorVisibilityReconciliation($session->id, true, true);
            $target->simulation->publishTransferredTeleport($arrival->player, $transfer->from);
            $portalTravel = $this->pendingPortalTravelNotifications[$session->id] ?? null;
            unset($this->pendingPortalTravelNotifications[$session->id]);
            if ($portalTravel !== null) {
                $this->pluginEvents?->portalTravelled(
                    $arrival->player,
                    $portalTravel['type'],
                    $portalTravel['from'],
                    $portalTravel['destination'],
                );
                $target->simulation->beginPortalArrivalCooldown($session->id);
            }
            $this->crashContextDirty = true;
            $transferCommitted = true;

            return true;
        } finally {
            $this->processingWorldId = null;
            if (!$transferCommitted) {
                $this->rollbackPlayerWorldTransfer($session, $source, $target, $transfer);
            }
        }
    }

    private function rollbackPlayerWorldTransfer(
        RuntimeSession $session,
        ManagedWorldRuntime $source,
        ManagedWorldRuntime $target,
        PendingPlayerWorldTransfer $transfer,
    ): void {
        try {
            $rollback = $target->simulation->detachPlayerForTransfer($session->id);
            $session->worldId = $transfer->sourceWorldId;
            $session->dimension = $transfer->sourceDimension;
            if ($rollback !== null && $source->simulation->authoritativePlayer($transfer->identity) === null) {
                $source->simulation->attachTransferredPlayer(
                    $rollback->player,
                    $transfer->sourceWorldId,
                    $transfer->from,
                    $transfer->fromYaw,
                    $transfer->fromPitch,
                );
            }
        } catch (Throwable $exception) {
            $this->diagnostics->record('world.player_transfer_rollback_failed', [
                'source_world' => $transfer->sourceWorldId,
                'target_world' => $transfer->targetWorldId,
                'exception' => $exception::class,
            ]);
        }
    }

    public function currentWorldTime(): ?int
    {
        return $this->persistentWorld?->time();
    }

    public function currentWeather(?ApiWorld $world = null): ?WeatherState
    {
        return $this->weatherRuntime($world)?->opened->world->weather()->weather;
    }

    public function setWeather(
        ?ApiWorld $world,
        WeatherType $type,
        ?int $durationSeconds = null,
    ): ?WeatherState {
        $runtime = $this->weatherRuntime($world);
        if ($runtime === null) {
            return null;
        }
        if ($durationSeconds === null) {
            $state = $runtime->opened->world->weather();
            $sample = unpack('Vvalue', substr(hash(
                'sha256',
                $runtime->opened->world->metadata->seed . ':' . $state->transitionSequence . ':command-weather',
                true,
            ), 0, 4));
            $durationSeconds = 300 + ((is_array($sample) && is_int($sample['value'] ?? null)
                ? $sample['value']
                : 0) % 601);
        }
        $weather = WeatherState::fromCommandDuration($type, $durationSeconds);
        if (!$runtime->simulation->setWeather($weather, WeatherChangeCause::COMMAND)) {
            return null;
        }

        return $runtime->opened->world->weather()->weather;
    }

    private function weatherRuntime(?ApiWorld $world): ?ManagedWorldRuntime
    {
        if ($this->worldRuntimes === null) {
            return null;
        }
        if ($world === null) {
            return $this->worldRuntimes->default();
        }
        $runtime = $this->worldRuntimes->get($world->id());

        return $runtime !== null && $runtime->handle->isSameLoad($world) ? $runtime : null;
    }

    public function setWorldTime(int $time): ?int
    {
        if ($this->persistentWorld === null) {
            return null;
        }
        $this->persistentWorld->setTime($time);
        $this->synchronizeWorldTime();

        return $this->persistentWorld->time();
    }

    public function addWorldTime(int $amount): ?int
    {
        if ($this->persistentWorld === null) {
            return null;
        }
        $this->persistentWorld->addTime($amount);
        $this->synchronizeWorldTime();

        return $this->persistentWorld->time();
    }

    public function setWorldTimeRunning(bool $running): ?int
    {
        if ($this->persistentWorld === null) {
            return null;
        }
        if ($running) {
            $this->persistentWorld->startTime();
        } else {
            $this->persistentWorld->stopTime();
        }
        $this->synchronizeWorldTime();

        return $this->persistentWorld->time();
    }

    public function summonEntity(string $identifier, ApiPosition $position, ?ApiPlayer $source = null): bool
    {
        $type = VanillaEntityType::tryFrom($identifier)
            ?? (str_starts_with($identifier, 'minecraft:')
                ? new VanillaEntityIdentifier($identifier)
                : new CustomEntityType($identifier));
        $outcome = $this->world->spawnEntity(new EntitySpawnRequest(
            $type,
            SpawnCause::COMMAND,
            $this->persistentWorld?->metadata->name ?? 'world',
            new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
            $source === null ? 0.0 : $source->yaw,
            0.0,
        ));

        return $outcome->succeeded();
    }

    public function spawnPluginEntity(
        CustomEntityType $type,
        ApiPosition $position,
        float $yaw = 0.0,
        float $pitch = 0.0,
    ): bool {
        return $this->world->spawnEntity(new EntitySpawnRequest(
            $type,
            SpawnCause::PLUGIN,
            $this->persistentWorld?->metadata->name ?? 'world',
            new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
            $yaw,
            $pitch,
        ))->succeeded();
    }

    /** Refreshes one connected player's command authority after a persisted permission change. */
    public function refreshPlayerAuthority(string $uuid, bool $includeAbilities): void
    {
        if ($this->commandRegistry === null || $this->permissionStore === null) {
            return;
        }
        $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
        $player = $this->world->pluginPlayer($uuid);
        $gameMode = $player === null ? GameMode::SURVIVAL : $player->getGameMode();
        foreach ($this->sessions as $key => $session) {
            if ($session->play === null || strcasecmp($session->play->login()->identity, $uuid) !== 0) {
                continue;
            }
            if ($includeAbilities && !$session->play->queuePacket(
                $projector->abilities(
                    $uuid,
                    $session->runtimeEntityId->toSignedBits(),
                    $gameMode,
                ),
            )) {
                $this->disconnect($key, 'authority_abilities_update_failed');

                return;
            }
            if (!$session->play->queuePacket($projector->availableCommands($uuid, $this->onlinePlayers()))) {
                $this->disconnect($key, 'authority_commands_update_failed');
            }

            return;
        }
    }

    private function queueOnlinePlayerCommandUpdate(): void
    {
        ++$this->onlinePlayerCommandRevision;
    }

    private function queueCommandMetadataUpdates(): void
    {
        if ($this->commandRegistry === null) {
            return;
        }
        $updates = $this->commandRegistry->drainSoftEnumUpdates();
        if ($this->permissionStore === null) {
            $this->commandSchemaRevision = $this->commandRegistry->schemaRevision();

            return;
        }
        $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
        $schemaRevision = $this->commandRegistry->schemaRevision();
        $schemaChanged = $schemaRevision !== $this->commandSchemaRevision;
        $onlinePlayersPacket = null;
        foreach ($this->sessions as $key => $session) {
            if (!$session->joined || $session->play === null) {
                continue;
            }
            if ($schemaChanged) {
                if ($session->play->queuePacket($projector->availableCommands(
                    $session->play->login()->identity,
                    $this->onlinePlayers(),
                ))) {
                    $this->deliveredOnlinePlayerCommandRevisions[$key] = $this->onlinePlayerCommandRevision;
                } else {
                    $this->diagnostics->record('runtime.command_update_rejected.protocol_trace', [
                        'kind' => 'schema',
                        'phase' => strtolower($session->phase->name),
                    ]);
                }
                continue;
            }
            foreach ($updates as $update) {
                if (!$session->play->queuePacket($projector->softEnumUpdate($update->name, $update->values))) {
                    $this->diagnostics->record('runtime.command_update_rejected.protocol_trace', [
                        'kind' => 'soft_enum',
                        'phase' => strtolower($session->phase->name),
                    ]);
                    break;
                }
            }
            if (($this->deliveredOnlinePlayerCommandRevisions[$key] ?? -1) < $this->onlinePlayerCommandRevision) {
                $onlinePlayersPacket ??= $projector->onlinePlayerUpdate($this->onlinePlayers());
                if ($this->queueWorldPacket($session, $onlinePlayersPacket)) {
                    $this->deliveredOnlinePlayerCommandRevisions[$key] = $this->onlinePlayerCommandRevision;
                } else {
                    $this->diagnostics->record('runtime.command_update_rejected.protocol_trace', [
                        'kind' => 'online_players',
                        'phase' => strtolower($session->phase->name),
                    ]);
                }
            }
        }
        $this->commandSchemaRevision = $schemaRevision;
    }

    /** Queues one clean replacement snapshot per joined session and catalog revision. */
    private function queueCraftingCatalogUpdates(): void
    {
        if ($this->craftingCatalog === null) {
            return;
        }
        $revision = $this->craftingCatalog->revision();
        $packet = null;
        foreach (array_keys($this->sessions) as $key) {
            $session = $this->sessions[$key];
            if (!$session->joined || $session->play === null || $session->craftingCatalogRevision === $revision) {
                continue;
            }
            $packet ??= $this->craftingCatalog->protocolPacket();
            if (!$this->queueWorldPacket($session, $packet)) {
                $this->disconnect($key, 'crafting_catalog_backlog_exhausted');
                continue;
            }
            $session->craftingCatalogRevision = $revision;
        }
    }

    /** Runs one non-blocking, explicitly bounded network and simulation iteration. */
    public function poll(): bool
    {
        if ($this->closed) {
            return false;
        }
        $pollStarted = hrtime(true);
        $completedTicks = 0;
        $this->performance?->beginTick($pollStarted);
        try {
            if ($this->worldOperations !== null) {
                $worldOperationsStarted = hrtime(true);
                $processedWorldOperations = $this->worldOperations->poll();
                $this->recordSlowRuntimeStage('world_operations', $worldOperationsStarted, fields: [
                    'processed' => $processedWorldOperations,
                    'pending' => $this->worldOperations->pendingCount(),
                ]);
            }
            if ($this->worldRuntimes === null) {
                $this->persistentWorld?->requestRetainSpawnChunk();
            } else {
                foreach ($this->worldRuntimes->loadedDimensions() as $runtime) {
                    $runtime->opened->world->requestRetainSpawnChunk();
                }
            }
            $transportStartedNanoseconds = hrtime(true);
            $transportTiming = $this->performance?->startSubsystem(PerformanceSubsystem::TRANSPORT);
            $rakNetPollStartedNanoseconds = hrtime(true);
            $this->transport->poll($this->limits->maximumDatagramsPerPoll);
            $this->recordSlowRuntimeStage('raknet_poll', $rakNetPollStartedNanoseconds);
            $events = $this->transport->drainSessionEvents();
            $payloads = $this->transport->drainReceivedPayloads();
            if (count($events) > $this->limits->maximumSessionEventsPerPoll
                || count($payloads) > $this->limits->maximumPayloadsPerPoll) {
                return $this->failRuntime('poll_input_limit');
            }
            foreach ($events as $event) {
                if ($event instanceof SessionOpenedEvent) {
                    $this->open($event->session);
                } else {
                    $this->diagnostics->record('runtime.transport_session_closed', [
                        'reason' => $event->reason->value,
                        'transport_failure' => $event->transportFailure?->value,
                        'transport_failure_detail' => $event->transportFailureDetail,
                    ]);
                    [$quitCause, $quitReason] = self::transportQuitReason($event->reason);
                    $this->closeEndpoint($event->session, $quitCause, $quitReason);
                }
            }
            $this->expirePendingTransportCloses();
            $payloadAcceptStartedNanoseconds = hrtime(true);
            foreach ($payloads as $payload) {
                $this->performance?->recordNetworkReceived(strlen($payload->payload));
                $this->involvedSessionId = $this->sessions[self::rawEndpointKey($payload->remoteAddress, $payload->remotePort)]->id ?? null;
                $this->accept($payload);
                $this->involvedSessionId = null;
            }
            $this->recordSlowRuntimeStage('payload_accept', $payloadAcceptStartedNanoseconds, fields: [
                'payloads' => count($payloads),
            ]);
            $transportTiming?->end();
            $this->recordSlowRuntimeStage('transport', $transportStartedNanoseconds);
            $sessionsStartedNanoseconds = hrtime(true);
            $sessionTiming = $this->performance?->startSubsystem(PerformanceSubsystem::SESSIONS);
            $maintainPlaySessions = count($this->sessions) <= 32
                || $pollStarted >= $this->nextPlaySessionMaintenanceNanoseconds;
            if ($maintainPlaySessions) {
                $this->nextPlaySessionMaintenanceNanoseconds = $pollStarted > PHP_INT_MAX - self::PLAY_SESSION_MAINTENANCE_INTERVAL_NANOSECONDS
                    ? PHP_INT_MAX
                    : $pollStarted + self::PLAY_SESSION_MAINTENANCE_INTERVAL_NANOSECONDS;
            }
            foreach (array_keys($this->sessions) as $key) {
                $session = $this->sessions[$key];
                if ($session->phase === SessionPhase::LOGIN) {
                    $stateBefore = $session->login?->state();
                    try {
                        $alive = $session->login?->tick() ?? false;
                    } catch (Throwable $exception) {
                        $this->diagnostics->record('login.tick_failed', ['exception' => $exception::class]);
                        $alive = false;
                    }
                    if (!$alive) {
                        $failure = $session->login?->failure();
                        $this->diagnostics->record('login.session_closed', [
                            'source' => 'tick',
                            'state_before' => $stateBefore !== null ? strtolower($stateBefore->name) : 'missing',
                            'failure' => $failure !== null ? $failure->value : 'unknown',
                            'protocol' => $session->login?->observedProtocolVersion(),
                            'detail' => $session->login?->failureDetail(),
                        ]);
                        $this->disconnect($key, 'login_tick_failed');
                        continue;
                    }
                    $this->flush($key, $session);
                    $this->promoteIfReady($key, $session);
                } elseif ($maintainPlaySessions && $session->play !== null) {
                    if (!$session->play->tick()) {
                        $this->disconnect($key, 'play_tick_failed');
                    } elseif (!$this->tryQueueAdmission($key, $session)) {
                        continue;
                    }
                }
            }
            $this->processPendingPlayerWorldTransfers();
            $sessionTiming?->end();
            $this->recordSlowRuntimeStage('sessions', $sessionsStartedNanoseconds);
            $directedCount = 0;
            $worldLoopStartedNanoseconds = hrtime(true);
            $worldTiming = $this->performance?->startSubsystem(PerformanceSubsystem::WORLD);
            $advanceServerTick = function (): void {
                $pluginTiming = $this->performance?->startSubsystem(PerformanceSubsystem::PLUGINS);
                try {
                    ++$this->serverTick;
                    ($this->simulationTickBoundary)?->__invoke($this->serverTick);
                } finally {
                    $pluginTiming?->end();
                }
            };
            if ($this->worldRuntimes === null) {
                $ticks = [];
                $this->worldLoop->pollCadence(function () use (&$ticks, $advanceServerTick): void {
                    $advanceServerTick();
                    $ticks[] = $this->world->tick();
                });
                $worldTicks = ['world' => $ticks];
            } else {
                $worldTicks = $this->worldRuntimes->poll($advanceServerTick);
            }
            $completedTicks = count($worldTicks[$this->worldRuntimes?->default()->handle->id() ?? 'world'] ?? []);
            $worldTiming?->end();
            $this->recordSlowRuntimeStage(
                'world_loop',
                $worldLoopStartedNanoseconds,
                fields: $this->world->lastTickStageMicroseconds(),
            );
            $remainingChunkAutosaveBudget = $this->autosaveChunkBudget;
            $remainingEntityAutosaveBudget = $this->autosaveChunkBudget;
            $remainingPlayerAutosaveBudget = $this->playerAutosaveBudget;
            $globalChunkStreamingBudget = new ChunkStreamingBudget(
                $this->chunksGeneratePerTick,
                $this->chunksSendPerTick,
                $this->chunksSendPerTick,
                deadlineNanoseconds: self::saturatingAdd(
                    $worldLoopStartedNanoseconds,
                    self::CHUNK_STREAMING_BUDGET_NANOSECONDS,
                ),
            );
            $this->pruneWorldMaintenanceState();
            $outboundTiming = $this->performance?->startSubsystem(PerformanceSubsystem::NETWORK_OUTBOUND);
            foreach ($worldTicks as $worldId => $ticks) {
                $managedRuntime = $this->worldRuntimes === null ? null : $this->worldRuntimes->runtimeForPollKey($worldId);
                $activeSimulation = $managedRuntime === null ? $this->world : $managedRuntime->simulation;
                $activePersistentWorld = $managedRuntime === null ? $this->persistentWorld : $managedRuntime->opened->world;
                $this->processingWorldId = $worldId;
                foreach ($ticks as $tick) {
                    $this->admissionCommandsQueued = 0;
                    $tickStartedNanoseconds = hrtime(true);
                    $this->drainDeferredWorldPackets();
                    /** @var array<string, list<PacketFrame>> $pendingMovementPackets */
                    $pendingMovementPackets = [];
                    /** @var array<string, list<PacketFrame>> $authoritativeMovementPackets */
                    $authoritativeMovementPackets = $this->deferredAuthoritativeMovementFrames;
                    $this->deferredAuthoritativeMovementFrames = [];
                    /** @var array<string, list<array{frame: PacketFrame, packet: Packet}>> $pendingSharedAuthoritativePackets */
                    $pendingSharedAuthoritativePackets = [];
                    /** @var array<string, bool> $movementRecipientAvailability */
                    $movementRecipientAvailability = [];
                    /** @var SplObjectStorage<Packet, PacketFrame> $movementFrameCache */
                    $movementFrameCache = new SplObjectStorage();
                    $chunkStageStartedNanoseconds = hrtime(true);
                    $chunkTiming = $this->performance?->startSubsystem(PerformanceSubsystem::CHUNKS);
                    $activePersistentWorld?->pollAsynchronousCompletions(8, 16);
                    $admissionStreamingKeys = [];
                    $spawnedStreamingKeys = [];
                    foreach ($this->sessions as $key => $streamingSession) {
                        if ($streamingSession->play === null || $this->runtimePollKeyForSession($streamingSession) !== $worldId) {
                            continue;
                        }
                        if ($this->hasDeferredWorldPackets($streamingSession->id)) {
                            continue;
                        }
                        if ($streamingSession->phase === SessionPhase::SPAWNED) {
                            $spawnedStreamingKeys[] = $key;
                        } elseif ($streamingSession->play->requiresSpawnTerrain()) {
                            $admissionStreamingKeys[] = $key;
                        }
                    }
                    $admissionStreamingKeys = self::rotateStreamingKeys(
                        $admissionStreamingKeys,
                        $this->chunkAdmissionStreamingCursor,
                    );
                    $spawnedStreamingKeys = self::rotateStreamingKeys(
                        $spawnedStreamingKeys,
                        $this->chunkStreamingCursor,
                    );
                    if ($admissionStreamingKeys === []) {
                        $steadyGeneration = min($this->chunksGeneratePerTick, 4);
                        $steadyTransfer = min($this->chunksSendPerTick, 8);
                        $this->streamChunks($spawnedStreamingKeys, $globalChunkStreamingBudget->slice(
                            $steadyGeneration,
                            $steadyTransfer,
                            $steadyTransfer,
                        ));
                    } else {
                        $admissionGeneration = max(1, intdiv($this->chunksGeneratePerTick * 3 + 3, 4));
                        $admissionTransfer = max(1, intdiv($this->chunksSendPerTick * 3 + 3, 4));
                        $sharedBudget = $globalChunkStreamingBudget->slice(
                            $this->chunksGeneratePerTick,
                            $this->chunksSendPerTick,
                            $this->chunksSendPerTick,
                        );
                        $this->streamChunks($admissionStreamingKeys, $sharedBudget->slice(
                            $admissionGeneration,
                            $admissionTransfer,
                            $admissionTransfer,
                        ));
                        $this->streamChunks($spawnedStreamingKeys, $sharedBudget->slice(
                            $this->chunksGeneratePerTick - $admissionGeneration,
                            $this->chunksSendPerTick - $admissionTransfer,
                            $this->chunksSendPerTick - $admissionTransfer,
                        ));
                    }
                    $chunkTiming?->end();
                    $this->recordSlowRuntimeStage('chunk_streaming', $chunkStageStartedNanoseconds, $tick->number);
                    $visibilityStartedNanoseconds = hrtime(true);
                    foreach ($this->sessions as $session) {
                        if ($session->play === null || $this->runtimePollKeyForSession($session) !== $worldId) {
                            continue;
                        }
                        $changedChunkKeys = $session->play->takeChunkVisibilityChanges();
                        if ($changedChunkKeys === []) {
                            continue;
                        }
                        $changedChunkSet = array_fill_keys($changedChunkKeys, true);
                        $this->enqueueActorVisibilityReconciliation(
                            $session->id,
                            false,
                            true,
                            $changedChunkSet,
                        );
                        foreach ($this->reconcileItemsForViewer($session->id, $changedChunkSet) as $visibilityEvent) {
                            if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                return false;
                            }
                        }
                        foreach ($this->reconcileEntityActorsForViewer($session->id, $changedChunkSet) as $visibilityEvent) {
                            if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                return false;
                            }
                        }
                    }
                    if (!$this->drainActorVisibilityReconciliations($directedCount)) {
                        return false;
                    }
                    $this->recordSlowRuntimeStage('visibility', $visibilityStartedNanoseconds, $tick->number);
                    $eventsStartedNanoseconds = hrtime(true);
                    foreach ($tick->events as $event) {
                        if ($this->isMovementFlushBoundary($event)
                            && !$this->flushMovementPackets(
                                $pendingMovementPackets,
                                $movementRecipientAvailability,
                                $authoritativeMovementPackets,
                                $pendingSharedAuthoritativePackets,
                            )) {
                            return false;
                        }
                        if (!$this->reconcileAdmission($event)) {
                            continue;
                        }
                        if ($event instanceof PortalTransferRequested) {
                            if (!$this->beginPortalTransfer($event)) {
                                $session = $this->sessionById($event->sessionId);
                                if ($session !== null) {
                                    $this->simulationForSession($session)?->rejectPortalTransfer($event->sessionId);
                                }
                                $this->diagnostics->record('world.portal_transfer_deferred', [
                                    'source_dimension' => $event->destination->sourceDimension->value,
                                    'target_dimension' => $event->destination->targetDimension->value,
                                ]);
                            }
                            continue;
                        }
                        if ($event instanceof EndPortalTransferRequested) {
                            if (!$this->beginEndPortalTransfer($event)) {
                                $session = $this->sessionById($event->sessionId);
                                if ($session !== null) {
                                    $this->simulationForSession($session)?->rejectPortalTransfer($event->sessionId);
                                }
                                $this->diagnostics->record('world.end_portal_transfer_deferred', [
                                    'source_dimension' => $event->sourceDimension->value,
                                    'target_dimension' => $event->targetDimension->value,
                                ]);
                            }
                            continue;
                        }
                        if ($event instanceof BlockPlacementCorrected) {
                            $this->diagnostics->record('world.protocol_trace', [
                                'kind' => 'block_placement_corrected',
                                'reason' => $event->reason,
                                'item' => $event->heldStack?->identifier,
                                'aux_value' => $event->heldStack?->auxValue,
                            ]);
                        }
                        if ($event instanceof MovementCorrected) {
                            $this->recordMovementCorrectionTrace($event);
                        }
                        if (($event instanceof PlayerJoined || $event instanceof PlayerMoved || $event instanceof PlayerRespawned)
                            && !$this->updateAuthoritativeChunkView($event->player)) {
                            continue;
                        }
                        if ($event instanceof MovementCorrected
                            && ($event->reason === 'plugin_teleport' || $event->peerSessionIds !== [])
                            && !$this->updateAuthoritativeChunkView($event->authoritativePlayer)) {
                            continue;
                        }
                        if ($event instanceof BlockBreakStarted
                            || $event instanceof BlockPunch
                            || $event instanceof BlockBreakStopped
                            || $event instanceof BlockChanged
                            || $event instanceof BlockPlaced) {
                            $event = $this->filterBlockRecipients($event);
                        }
                        if ($event instanceof PotionSplashImpacted) {
                            $event = new PotionSplashImpacted(
                                $event->position,
                                $event->potionType,
                                array_values(array_filter(
                                    array_values(array_unique($event->recipientSessionIds)),
                                    fn(string $recipient): bool => $this->transientActorViewerCanSee(
                                        $recipient,
                                        $this->processingWorldId ?? 'world',
                                        $event->position,
                                    ),
                                )),
                            );
                        }
                        if ($event instanceof ChatBroadcast
                            && $this->eventEncoder instanceof ChatBroadcastPacketEncoder) {
                            if (!$this->collectSharedChatPacket(
                                $event,
                                $pendingSharedAuthoritativePackets,
                                $movementFrameCache,
                                $directedCount,
                            )) {
                                return false;
                            }
                            continue;
                        }
                        if ($event instanceof ItemEntitySpawned || $event instanceof ItemEntityMoved
                            || $event instanceof ItemEntityPickedUp || $event instanceof ItemEntityDespawned) {
                            foreach ($this->reconcileItemEvent($event) as $itemEvent) {
                                if (!$this->dispatchWorldEvent($itemEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof ProjectileSpawned || $event instanceof ProjectileMoved
                            || $event instanceof ProjectileRemoved) {
                            foreach ($this->reconcileProjectileEvent($event) as $projectileEvent) {
                                if (!$this->dispatchWorldEvent($projectileEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof AreaEffectCloudSpawned || $event instanceof AreaEffectCloudUpdated
                            || $event instanceof AreaEffectCloudRemoved) {
                            foreach ($this->reconcileAreaEffectCloudEvent($event) as $cloudEvent) {
                                if (!$this->dispatchWorldEvent($cloudEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof EntityActorSpawned || $event instanceof EntityActorMoved
                            || $event instanceof EntityActorDamaged || $event instanceof EntityActorDied
                            || $event instanceof EntityActorRemoved) {
                            foreach ($this->reconcileEntityActorEvent($event) as $entityEvent) {
                                if ($entityEvent instanceof EntityActorMoved) {
                                    if (!$this->shouldBroadcastEntityMovement($entityEvent, $tick->number)) {
                                        continue;
                                    }
                                    if (!$this->collectMovementPackets(
                                        $entityEvent,
                                        $pendingMovementPackets,
                                        $movementFrameCache,
                                        $movementRecipientAvailability,
                                        $directedCount,
                                    )) {
                                        return false;
                                    }
                                    continue;
                                }
                                if ($entityEvent instanceof EntityActorDied || $entityEvent instanceof EntityActorRemoved) {
                                    unset($this->lastEntityMovementBroadcastTicks[$entityEvent->entity->getRuntimeId()]);
                                }
                                if (!$this->flushMovementPackets(
                                    $pendingMovementPackets,
                                    $movementRecipientAvailability,
                                    $authoritativeMovementPackets,
                                    $pendingSharedAuthoritativePackets,
                                )
                                    || !$this->dispatchWorldEvent($entityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof PlayerJoined) {
                            $this->actorVisibility->upsert($event->player);
                            if (!$this->dispatchWorldEvent($event, $directedCount)) {
                                return false;
                            }
                            $this->enqueueActorVisibilityReconciliation($event->player->sessionId, true, true);
                            $this->queueOnlinePlayerCommandUpdate();
                            continue;
                        }
                        if ($event instanceof PlayerGameModeChanged) {
                            $this->actorVisibility->upsert($event->player);
                            $session = $this->sessionById($event->player->sessionId);
                            if (!$this->dispatchWorldEvent($event, $directedCount)) {
                                return false;
                            }
                            if ($session?->play !== null && $this->commandRegistry !== null && $this->permissionStore !== null) {
                                $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
                                if (!$session->play->queuePacket($projector->abilities(
                                    $event->player->identity,
                                    $event->player->runtimeActorId,
                                    $event->gameMode,
                                ))) {
                                    return false;
                                }
                            }
                            if ($session?->play !== null
                                && (!$session->play->queuePacket(new UpdateAdventureSettingsPacket())
                                    || ($this->inventoryProjector !== null
                                        && !$session->play->queuePacket($this->inventoryProjector->creativeContent()))
                                    || !$session->play->queuePacket(new SystemTextPacket(
                                        'Your game mode has been changed to ' . $event->gameMode->value . '.',
                                    )))) {
                                return false;
                            }
                            foreach ($this->reconcileActorVisibility($event->player->sessionId) as $visibilityEvent) {
                                if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof PlayerMoved) {
                            $actorVisibilityChanged = $this->actorVisibility->upsert($event->player);
                            $session = $this->sessionById($event->player->sessionId);
                            $changedChunkKeys = $session?->play?->takeChunkVisibilityChanges() ?? [];
                            if ($changedChunkKeys !== []) {
                                $changedChunkSet = array_fill_keys($changedChunkKeys, true);
                                $this->enqueueActorVisibilityReconciliation(
                                    $event->player->sessionId,
                                    false,
                                    true,
                                    $changedChunkSet,
                                );
                                foreach ($this->reconcileItemsForViewer(
                                    $event->player->sessionId,
                                    $changedChunkSet,
                                ) as $itemVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($itemVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                                foreach ($this->reconcileEntityActorsForViewer(
                                    $event->player->sessionId,
                                    $changedChunkSet,
                                ) as $entityVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($entityVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                                foreach ($this->reconcileTransientActorsForViewer(
                                    $event->player->sessionId,
                                    $changedChunkSet,
                                ) as $potionVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($potionVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                            }
                            $newlyVisibleRecipients = [];
                            if ($actorVisibilityChanged) {
                                foreach ($this->reconcileMovedActorVisibility($event->player->sessionId) as $visibilityEvent) {
                                    if ($visibilityEvent instanceof PlayerBecameVisible) {
                                        $newlyVisibleRecipients[$visibilityEvent->recipientSessionId] = true;
                                    }
                                    if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                            }
                            if ($this->shouldBroadcastPeerMovement($event, $tick->number)) {
                                $visibleRecipients = $this->actorVisibility->visibleRecipients(
                                    $event->player->sessionId,
                                    $event->recipientSessionIds,
                                );
                                $visibleRecipients = array_values(array_filter(
                                    $visibleRecipients,
                                    static fn(string $recipient): bool => !isset($newlyVisibleRecipients[$recipient]),
                                ));
                                if (!$this->collectMovementPackets(new PlayerMoved(
                                    $event->player,
                                    $visibleRecipients,
                                    $event->postureChanged,
                                ), $pendingMovementPackets, $movementFrameCache, $movementRecipientAvailability, $directedCount)) {
                                    return false;
                                }
                            }
                            continue;
                        }
                        if ($event instanceof MovementCorrected
                            && $event->peerSessionIds !== []
                            && $this->eventEncoder instanceof PlayerMovementPacketEncoder) {
                            $actorVisibilityChanged = $this->actorVisibility->upsert($event->authoritativePlayer);
                            $session = $this->sessionById($event->authoritativePlayer->sessionId);
                            $changedChunkKeys = $session?->play?->takeChunkVisibilityChanges() ?? [];
                            $viewerVisibilityChanged = $changedChunkKeys !== [];
                            if ($viewerVisibilityChanged) {
                                $changedChunkSet = array_fill_keys($changedChunkKeys, true);
                                $this->enqueueActorVisibilityReconciliation(
                                    $event->authoritativePlayer->sessionId,
                                    false,
                                    true,
                                    $changedChunkSet,
                                );
                                foreach ($this->reconcileItemsForViewer(
                                    $event->authoritativePlayer->sessionId,
                                    $changedChunkSet,
                                ) as $itemVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($itemVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                                foreach ($this->reconcileEntityActorsForViewer(
                                    $event->authoritativePlayer->sessionId,
                                    $changedChunkSet,
                                ) as $entityVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($entityVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                                foreach ($this->reconcileTransientActorsForViewer(
                                    $event->authoritativePlayer->sessionId,
                                    $changedChunkSet,
                                ) as $potionVisibilityEvent) {
                                    if (!$this->dispatchWorldEvent($potionVisibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                            }
                            $newlyVisibleRecipients = [];
                            if ($actorVisibilityChanged) {
                                foreach ($this->reconcileMovedActorVisibility(
                                    $event->authoritativePlayer->sessionId,
                                ) as $visibilityEvent) {
                                    if ($visibilityEvent instanceof PlayerBecameVisible
                                        && $visibilityEvent->player->sessionId === $event->authoritativePlayer->sessionId) {
                                        $newlyVisibleRecipients[$visibilityEvent->recipientSessionId] = true;
                                    }
                                    if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                        return false;
                                    }
                                }
                            }
                            $visibleRecipients = $this->actorVisibility->visibleRecipients(
                                $event->authoritativePlayer->sessionId,
                                $event->peerSessionIds,
                            );
                            $visibleRecipients = array_values(array_filter(
                                $visibleRecipients,
                                static fn(string $recipient): bool => !isset($newlyVisibleRecipients[$recipient]),
                            ));
                            if (!$this->collectAuthoritativeMovementPacket(new MovementCorrected(
                                $event->authoritativePlayer,
                                $event->reason,
                                [],
                                $event->postureChanged,
                                $event->clientTick,
                            ), $authoritativeMovementPackets, $movementFrameCache, $directedCount)) {
                                return false;
                            }
                            $peerMovement = new PlayerMoved(
                                $event->authoritativePlayer,
                                $visibleRecipients,
                                $event->postureChanged,
                            );
                            if ($visibleRecipients !== []
                                && $this->shouldBroadcastPeerMovement($peerMovement, $tick->number)
                                && !$this->collectMovementPackets(
                                    $peerMovement,
                                    $pendingMovementPackets,
                                    $movementFrameCache,
                                    $movementRecipientAvailability,
                                    $directedCount,
                                )) {
                                return false;
                            }
                            continue;
                        }
                        if ($event instanceof MovementCorrected) {
                            if (!$this->collectAuthoritativeMovementPacket(
                                $event,
                                $authoritativeMovementPackets,
                                $movementFrameCache,
                                $directedCount,
                            )) {
                                return false;
                            }
                            continue;
                        }
                        if ($event instanceof NutritionChanged) {
                            if (!$this->dispatchWorldEvent($event, $directedCount)) {
                                return false;
                            }
                            continue;
                        }
                        if ($event instanceof PlayerRespawned) {
                            $previousViewers = $this->actorVisibility->viewersOf($event->player->sessionId);
                            $this->actorVisibility->upsert($event->player);
                            foreach ($this->reconcileActorVisibility($event->player->sessionId) as $visibilityEvent) {
                                if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            foreach ($this->actorVisibility->refreshActor(
                                $event->player,
                                array_values(array_intersect(
                                    $previousViewers,
                                    $this->actorVisibility->viewersOf($event->player->sessionId),
                                )),
                            ) as $visibilityEvent) {
                                if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                            $event = new PlayerRespawned(
                                $event->player,
                                array_values(array_unique([
                                    $event->player->sessionId,
                                    ...array_intersect(
                                        $event->recipientSessionIds,
                                        $this->actorVisibility->viewersOf($event->player->sessionId),
                                    ),
                                ])),
                                $event->inventory,
                                $event->selectedHotbarSlot,
                                $event->selectedStack,
                            );
                        } elseif ($event instanceof PlayerDamaged) {
                            $event = new PlayerDamaged(
                                $event->player,
                                $event->damage,
                                $event->cause,
                                array_values(array_unique([
                                    $event->player->sessionId,
                                    ...array_intersect(
                                        $event->recipientSessionIds,
                                        $this->actorVisibility->viewersOf($event->player->sessionId),
                                    ),
                                ])),
                            );
                        } elseif ($event instanceof PlayerKnockedBack) {
                            $event = new PlayerKnockedBack(
                                $event->ownerSessionId,
                                $event->player,
                                $event->motionX,
                                $event->motionY,
                                $event->motionZ,
                                $event->clientTick,
                                array_values(array_unique([
                                    $event->player->sessionId,
                                    ...array_intersect(
                                        $event->recipientSessionIds,
                                        $this->actorVisibility->viewersOf($event->player->sessionId),
                                    ),
                                ])),
                            );
                        } elseif ($event instanceof PlayerMotionChanged) {
                            $event = new PlayerMotionChanged(
                                $event->ownerSessionId,
                                $event->player,
                                $event->motionX,
                                $event->motionY,
                                $event->motionZ,
                                $event->clientTick,
                                $event->postureChanged,
                                array_values(array_unique([
                                    $event->player->sessionId,
                                    ...array_intersect(
                                        $event->recipientSessionIds,
                                        $this->actorVisibility->viewersOf($event->player->sessionId),
                                    ),
                                ])),
                            );
                        } elseif ($event instanceof PlayerDied) {
                            $event = new PlayerDied(
                                $event->player,
                                $event->cause,
                                $event->killer,
                                $event->deathMessage,
                                $event->deathScreenMessage,
                                array_values(array_unique([
                                    $event->player->sessionId,
                                    ...array_intersect(
                                        $event->animationRecipientSessionIds,
                                        $this->actorVisibility->viewersOf($event->player->sessionId),
                                    ),
                                ])),
                                $event->messageRecipientSessionIds,
                            );
                        }
                        if ($event instanceof PlayerDisconnected) {
                            foreach ($this->actorVisibility->remove($event->sessionId) as $visibilityEvent) {
                                if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                        } elseif ($event instanceof EmotePerformed) {
                            $event = new EmotePerformed(
                                $event->senderSessionId,
                                $event->emoteId,
                                array_values(array_intersect(
                                    $event->recipientSessionIds,
                                    $this->actorVisibility->viewersOf($event->senderSessionId),
                                )),
                            );
                        } elseif ($event instanceof ArmSwung) {
                            $event = new ArmSwung(
                                $event->ownerSessionId,
                                $event->runtimeActorId,
                                $event->source,
                                array_values(array_intersect(
                                    $event->recipientSessionIds,
                                    $this->actorVisibility->viewersOf($event->ownerSessionId),
                                )),
                            );
                        } elseif ($event instanceof HeldItemChanged) {
                            $event = new HeldItemChanged(
                                $event->ownerSessionId,
                                $event->runtimeActorId,
                                $event->hotbarSlot,
                                $event->stack,
                                array_values(array_intersect(
                                    $event->recipientSessionIds,
                                    $this->actorVisibility->viewersOf($event->ownerSessionId),
                                )),
                                $event->ownerSlotCorrection,
                            );
                        } elseif ($event instanceof InventoryStackRequestProcessed) {
                            $this->diagnostics->record('world.inventory_request.protocol_trace', [
                                'request_id' => $event->requestId,
                                'success' => $event->success,
                                'reason' => $event->reason,
                                'affected_slots' => count($event->affectedSlots),
                            ]);
                            $event = new InventoryStackRequestProcessed(
                                $event->ownerSessionId,
                                $event->requestId,
                                $event->success,
                                $event->affectedSlots,
                                $event->mainInventory,
                                $event->cursorStack,
                                $event->selectedHotbarSlot,
                                $event->selectedStack,
                                $event->selectedStackChanged,
                                $event->runtimeActorId,
                                array_values(array_intersect(
                                    $event->peerSessionIds,
                                    $this->actorVisibility->viewersOf($event->ownerSessionId),
                                )),
                                $event->reason,
                                $event->responseMode,
                                $event->fullSync,
                                $event->armorInventory,
                                $event->offhandStack,
                                $event->craftingInventory,
                            );
                        }
                        if (!$this->dispatchWorldEvent($event, $directedCount)) {
                            return false;
                        }
                        if ($event instanceof PlayerDisconnected) {
                            $this->queueOnlinePlayerCommandUpdate();
                        }
                    }
                    $this->recordSlowRuntimeStage('event_projection', $eventsStartedNanoseconds, $tick->number, [
                        'events' => count($tick->events),
                        'directed_packets' => $directedCount,
                    ]);
                    if (!$this->flushMovementPackets(
                        $pendingMovementPackets,
                        $movementRecipientAvailability,
                        $authoritativeMovementPackets,
                        $pendingSharedAuthoritativePackets,
                    )) {
                        return false;
                    }
                    if ($activePersistentWorld !== null
                        && $tick->number % WorldTimeRules::SYNCHRONIZATION_INTERVAL_TICKS === 0) {
                        $this->synchronizeWorldTime($worldId, $activePersistentWorld);
                    }
                    $persistenceStartedNanoseconds = hrtime(true);
                    $persistenceTiming = $this->performance?->startSubsystem(PerformanceSubsystem::PERSISTENCE);
                    if ($activePersistentWorld !== null) {
                        foreach ($activePersistentWorld->pollWorldDataSaves() as $completion) {
                            if (!$completion->successful) {
                                $this->diagnostics->record('world.metadata_persistence_failed', [
                                    'revision' => $completion->revision,
                                    'code' => $completion->failureCode,
                                ]);
                            }
                        }
                    }
                    if ($this->autosaveEnabled && $activePersistentWorld !== null
                        && $tick->number % $this->autosaveIntervalTicks === 0) {
                        $metadataSubmission = $activePersistentWorld->scheduleWorldDataSave();
                        if ($metadataSubmission === PersistenceSubmission::SATURATED) {
                            $this->diagnostics->record('world.metadata_persistence_saturated', [
                                'tick' => $tick->number,
                            ]);
                        }
                        $this->autosaveActive[$worldId] = true;
                        if ($activeSimulation->beginEntityAutosave() > 0) {
                            $this->entityAutosaveActive[$worldId] = true;
                        } else {
                            unset($this->entityAutosaveActive[$worldId]);
                        }
                    }
                    if ($activePersistentWorld !== null
                        && isset($this->entityAutosaveActive[$worldId])
                        && $remainingEntityAutosaveBudget > 0) {
                        $result = $activeSimulation->autosaveEntities($remainingEntityAutosaveBudget);
                        $remainingEntityAutosaveBudget -= min(
                            $remainingEntityAutosaveBudget,
                            $result->attemptedChunks,
                        );
                        $remaining = $activeSimulation->pendingEntityAutosaveChunkCount();
                        if ($remaining === 0) {
                            unset($this->entityAutosaveActive[$worldId]);
                        }
                        $this->diagnostics->record('world.entities_autosaved', [
                            'saved_chunks' => $result->savedChunks,
                            'failed_chunks' => $result->failedChunksCount(),
                            'remaining_generation_chunks' => $remaining,
                            'dirty_chunks' => $activeSimulation->dirtyEntityChunkCount(),
                        ]);
                        foreach ($result->failureDetails() as $failure) {
                            $this->diagnostics->record('world.entity_persistence_failure', [
                                'operation' => $failure['operation'],
                                'chunk_x' => $failure['chunk']->x,
                                'chunk_z' => $failure['chunk']->z,
                                'exception' => $failure['exception'],
                                'detail' => $failure['detail'],
                            ]);
                        }
                    }
                    foreach ($activeSimulation->drainEntityOwnershipTransferFailures() as $failure) {
                        $this->diagnostics->record('world.entity_ownership_transfer_failed', [
                            'uuid' => $failure['uuid'],
                            'source_x' => $failure['source']->x,
                            'source_z' => $failure['source']->z,
                            'destination_x' => $failure['destination']->x,
                            'destination_z' => $failure['destination']->z,
                            'code' => $failure['code'],
                            'detail' => $failure['detail'],
                        ]);
                    }
                    if ($activePersistentWorld !== null
                        && isset($this->autosaveActive[$worldId])
                        && $remainingChunkAutosaveBudget > 0) {
                        $saved = $activePersistentWorld->autosave($remainingChunkAutosaveBudget);
                        $remainingChunkAutosaveBudget -= min($remainingChunkAutosaveBudget, $saved);
                        $remaining = $activePersistentWorld->dirtyChunkCount();
                        if ($remaining === 0) {
                            unset($this->autosaveActive[$worldId]);
                        }
                        $this->diagnostics->record('world.autosaved', [
                            'saved_chunks' => $saved,
                            'remaining_dirty_chunks' => $remaining,
                        ]);
                    }
                    if ($this->autosaveEnabled && $this->playerPersistence !== null
                        && $tick->number % $this->playerAutosaveIntervalTicks === 0) {
                        if ($activeSimulation->beginPlayerAutosave() > 0
                            || $this->playerPersistence->pendingCount() > 0) {
                            $this->playerAutosaveActive[$worldId] = true;
                        } else {
                            unset($this->playerAutosaveActive[$worldId]);
                        }
                    }
                    if ($this->playerPersistence !== null
                        && isset($this->playerAutosaveActive[$worldId])
                        && $remainingPlayerAutosaveBudget > 0) {
                        $result = $activeSimulation->autosavePlayers($remainingPlayerAutosaveBudget);
                        $remainingPlayerAutosaveBudget -= min(
                            $remainingPlayerAutosaveBudget,
                            $result['saved'] + $result['submitted'],
                        );
                        if ($result['remaining'] === 0) {
                            unset($this->playerAutosaveActive[$worldId]);
                        }
                        $this->diagnostics->record('players.autosaved', [
                            'saved_players' => $result['saved'],
                            'submitted_players' => $result['submitted'],
                            'remaining_dirty_players' => $result['remaining'],
                        ]);
                    }
                    if ($this->worldRuntimes === null || $managedRuntime === $this->worldRuntimes->default()) {
                        $this->runMemoryMaintenance($this->serverTick);
                    }
                    $persistenceTiming?->end();
                    $this->recordSlowRuntimeStage('persistence', $persistenceStartedNanoseconds, $tick->number);
                    $flushStartedNanoseconds = hrtime(true);
                    $this->flushConnectedSessions();
                    $this->recordSlowRuntimeStage('session_flush', $flushStartedNanoseconds, $tick->number);
                    $this->recordSlowRuntimeStage('tick_projection_total', $tickStartedNanoseconds, $tick->number, [
                        'events' => count($tick->events),
                        'directed_packets' => $directedCount,
                    ]);
                }
            }
            $this->processingWorldId = null;
            $metadataStartedNanoseconds = hrtime(true);
            $this->queueCommandMetadataUpdates();
            $this->queueCraftingCatalogUpdates();
            $this->recordSlowRuntimeStage('metadata_updates', $metadataStartedNanoseconds);
            $finalFlushStartedNanoseconds = hrtime(true);
            $this->flushConnectedSessions();
            $this->recordSlowRuntimeStage('final_session_flush', $finalFlushStartedNanoseconds);
            $outboundTiming?->end();

            $this->recordSlowRuntimeStage('poll_total', $pollStarted, fields: [
                'ticks' => $completedTicks,
                'transport_events' => count($events),
                'payloads' => count($payloads),
            ]);

            if ($completedTicks > 0) {
                $completedAt = hrtime(true);
                $this->performance?->completeTicks($completedTicks, $completedAt);
                $this->recordPerformanceTrace($completedAt);
            } else {
                $this->performance?->cancelTick();
            }

            if ($this->crashContextDirty
                || $this->crashContextPublishedNanoseconds === 0
                || $pollStarted - $this->crashContextPublishedNanoseconds >= 1_000_000_000) {
                $this->publishCrashContext();
                $this->crashContextPublishedNanoseconds = $pollStarted;
            }

            return !$this->closed;
        } catch (Throwable $exception) {
            return $this->failRuntime('poll_failed', $exception);
        }
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    public function shouldIdleAfterPoll(): bool
    {
        return ($this->worldRuntimes?->nanosecondsUntilNextTick()
            ?? $this->worldLoop->nanosecondsUntilNextTick()) > 0;
    }

    private function recordPerformanceTrace(int $nowNanoseconds): void
    {
        if ($this->performance === null) {
            return;
        }
        if ($this->performanceTraceStartedNanoseconds === 0) {
            $this->performanceTraceStartedNanoseconds = $nowNanoseconds;
            return;
        }
        if ($nowNanoseconds - $this->performanceTraceStartedNanoseconds < 1_000_000_000) {
            return;
        }
        $this->flushSlowRuntimeStageTrace();
        $snapshot = $this->performance->snapshot(
            onlinePlayers: $this->sessionCount(),
            maximumPlayers: $this->limits->maximumSessions,
            loadedChunks: $this->loadedChunkCount(),
            dirtyChunks: $this->dirtyChunkCount(),
            entityCount: $this->entityCount(),
        );
        $subsystems = $snapshot->averageSubsystemMilliseconds;
        $spawnedSessions = 0;
        foreach ($this->sessions as $session) {
            if ($session->phase === SessionPhase::SPAWNED) {
                ++$spawnedSessions;
            }
        }
        $this->diagnostics->record('runtime.performance_summary.protocol_trace', [
            'sessions' => $snapshot->onlinePlayers,
            'spawned' => $spawnedSessions,
            'tps_milli' => (int) round($snapshot->currentTps * 1_000),
            'mspt_micros' => (int) round($snapshot->currentMspt * 1_000),
            'poll_micros' => (int) round($snapshot->averagePollMilliseconds * 1_000),
            'transport_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::TRANSPORT),
            'sessions_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::SESSIONS),
            'plugins_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::PLUGINS),
            'world_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::WORLD),
            'chunks_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::CHUNKS),
            'persistence_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::PERSISTENCE),
            'workers_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::WORKERS),
            'outbound_us' => self::subsystemMicroseconds($subsystems, PerformanceSubsystem::NETWORK_OUTBOUND),
            'unclassified_us' => (int) round($snapshot->averageUnclassifiedMilliseconds * 1_000),
            'memory_bytes' => $snapshot->memoryBytes,
            'loaded_chunks' => $snapshot->loadedChunks,
            'entities' => $snapshot->entityCount,
            'net_tx_bps' => (int) round($snapshot->networkSendBytesPerSecond ?? 0.0),
        ]);
        $this->performanceTraceStartedNanoseconds = $nowNanoseconds;
    }

    /** @param array<string, int|string|bool|null> $fields */
    private function recordSlowRuntimeStage(
        string $stage,
        int $startedNanoseconds,
        ?int $tick = null,
        array $fields = [],
    ): void {
        $elapsedNanoseconds = hrtime(true) - $startedNanoseconds;
        if (!is_int($elapsedNanoseconds)) {
            throw new RuntimeException('Bedriox requires a 64-bit monotonic clock.');
        }
        if ($elapsedNanoseconds < self::RUNTIME_STAGE_TRACE_THRESHOLD_NANOSECONDS) {
            return;
        }
        $entry = $this->slowRuntimeStages[$stage] ?? [
            'count' => 0,
            'total_nanoseconds' => 0,
            'maximum_nanoseconds' => 0,
            'tick' => null,
            'fields' => [],
        ];
        ++$entry['count'];
        $entry['total_nanoseconds'] = self::saturatingAdd($entry['total_nanoseconds'], $elapsedNanoseconds);
        if ($elapsedNanoseconds > $entry['maximum_nanoseconds']) {
            $entry['maximum_nanoseconds'] = $elapsedNanoseconds;
            $entry['tick'] = $tick;
            $entry['fields'] = $fields;
        }
        $this->slowRuntimeStages[$stage] = $entry;
    }

    /** Emits bounded aggregate telemetry instead of synchronously logging every overloaded poll. */
    private function flushSlowRuntimeStageTrace(): void
    {
        foreach ($this->slowRuntimeStages as $stage => $entry) {
            $this->diagnostics->record('runtime.slow_stage_summary.protocol_trace', [
                'stage' => $stage,
                'occurrences' => $entry['count'],
                'average_us' => intdiv($entry['total_nanoseconds'], max(1, $entry['count']) * 1_000),
                'maximum_us' => intdiv($entry['maximum_nanoseconds'], 1_000),
                'sessions' => count($this->sessions),
                'last_tick' => $entry['tick'],
            ] + $entry['fields']);
        }
        $this->slowRuntimeStages = [];
    }

    /** @param array<string, float> $subsystems */
    private static function subsystemMicroseconds(array $subsystems, string $name): int
    {
        return (int) round(($subsystems[$name] ?? 0.0) * 1_000);
    }

    /**
     * @param list<string> $keys
     * @return list<string>
     */
    private static function rotateStreamingKeys(array $keys, int &$cursor): array
    {
        $count = count($keys);
        if ($count === 0) {
            $cursor = 0;

            return [];
        }
        $offset = $cursor % $count;
        $cursor = ($offset + 1) % $count;
        if ($offset === 0) {
            return $keys;
        }

        return [...array_slice($keys, $offset), ...array_slice($keys, 0, $offset)];
    }

    /** @param list<string> $keys */
    private function streamChunks(array $keys, ChunkStreamingBudget $budget): void
    {
        foreach ($keys as $key) {
            if (!$budget->hasCapacity()) {
                break;
            }
            $play = $this->sessions[$key]->play ?? null;
            if ($play !== null && !$play->worldTick($budget->slice(1, 1, 1))) {
                $this->disconnect($key, 'world_stream_tick_failed');
            }
        }
    }

    public function entityCount(): int
    {
        $players = 0;
        foreach ($this->simulations() as $simulation) {
            $players += count($simulation->snapshot()->players);
        }

        return $players
            + array_sum(array_map(count(...), $this->itemActors))
            + array_sum(array_map(count(...), $this->entityActors));
    }

    public function worldCount(): int
    {
        return $this->worldRuntimes?->count() ?? ($this->persistentWorld === null ? 0 : 1);
    }

    public function loadedChunkCount(): int
    {
        return $this->worldRuntimes?->loadedChunkCount()
            ?? $this->persistentWorld?->loadedChunkCount()
            ?? 0;
    }

    public function dirtyChunkCount(): int
    {
        return $this->worldRuntimes?->dirtyChunkCount()
            ?? $this->persistentWorld?->dirtyChunkCount()
            ?? 0;
    }

    public function generatingChunkCount(): int
    {
        return $this->worldRuntimes?->generatingChunkCount()
            ?? $this->persistentWorld?->generatingChunkCount()
            ?? 0;
    }

    public function chunkRepositorySnapshot(): ?ChunkRepositorySnapshot
    {
        return $this->worldRuntimes?->chunkRepositorySnapshot()
            ?? $this->persistentWorld?->chunkRepositorySnapshot();
    }

    public function worldPersistenceQueueSnapshot(): ?PersistenceQueueSnapshot
    {
        return $this->worldRuntimes?->persistenceQueueSnapshot()
            ?? $this->persistentWorld?->persistenceQueueSnapshot();
    }

    public function entityAiMetrics(): ?AiSchedulerMetrics
    {
        return $this->world->entityAiMetrics();
    }

    public function entityRuntimeMetrics(): ?EntityRuntimeMetrics
    {
        return $this->world->entityRuntimeMetrics();
    }

    public function chunkStreamingSnapshot(): ChunkStreamingSnapshot
    {
        $snapshot = new ChunkStreamingSnapshot(0, 0, 0, 0, 0, 0, 0);
        foreach ($this->sessions as $session) {
            if ($session->play !== null) {
                $snapshot = $snapshot->plus($session->play->chunkStreamingSnapshot());
            }
        }

        return $snapshot;
    }

    public function preparedChunkCacheSnapshot(): ?PreparedChunkCacheSnapshot
    {
        if ($this->worldRuntimes === null) {
            return $this->preparedChunks?->snapshot();
        }
        $totals = [0, 0, 0, 0, 0, 0, 0, 0, 0];
        $available = false;
        foreach ($this->worldRuntimes->loadedDimensions() as $runtime) {
            $snapshot = $runtime->preparedChunks?->snapshot();
            if ($snapshot === null) {
                continue;
            }
            $available = true;
            $totals[0] += $snapshot->entries;
            $totals[1] += $snapshot->bytes;
            $totals[2] += $snapshot->pending;
            $totals[3] += $snapshot->pendingBytes;
            $totals[4] += $snapshot->hits;
            $totals[5] += $snapshot->misses;
            $totals[6] += $snapshot->evictions;
            $totals[7] += $snapshot->invalidations;
            $totals[8] += $snapshot->failures;
        }

        return $available ? new PreparedChunkCacheSnapshot(...$totals) : null;
    }

    public function lastMemoryManagementDecision(): ?MemoryManagementDecision
    {
        return $this->lastMemoryDecision;
    }

    public function lastGarbageCollectionReport(): ?GarbageCollectionReport
    {
        return $this->lastGarbageCollection;
    }

    public function lastChunkUnloadResult(): ?ChunkUnloadResult
    {
        return $this->lastChunkUnload;
    }

    public function totalChunksUnloaded(): int
    {
        return $this->totalChunksUnloaded;
    }

    public function totalPreparedBytesTrimmed(): int
    {
        return $this->totalPreparedBytesTrimmed;
    }

    public function garbageCollectorRuns(): int
    {
        return $this->garbageCollector?->runs() ?? 0;
    }

    public function garbageCollectorThreshold(): int
    {
        return $this->garbageCollector?->threshold() ?? GarbageCollector::DEFAULT_THRESHOLD;
    }

    public function pendingChunkUnloadCount(): int
    {
        return $this->persistentWorld?->pendingChunkUnloadCount() ?? 0;
    }

    public function forceGarbageCollection(): ?GarbageCollectionReport
    {
        return $this->lastGarbageCollection = $this->garbageCollector?->collectNow();
    }

    public function runChunkUnloadMaintenance(): ?ChunkUnloadResult
    {
        if ($this->persistentWorld === null) {
            return null;
        }
        $result = $this->persistentWorld->processChunkUnloads($this->chunkUnloadPerTick, 5_000);
        $this->recordChunkUnloadResult($result);

        return $result;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    private function runMemoryMaintenance(int $tickNumber): void
    {
        $unloadBudget = $this->chunkUnloadPerTick;
        $decision = $this->memoryManager?->evaluate();
        if ($decision !== null) {
            $this->lastMemoryDecision = $decision;
            if ($decision->accelerateChunkUnloading) {
                $unloadBudget = max($unloadBudget, $decision->maximumChunkUnloads);
            }
            if ($decision->trimDisposableCaches && $decision->pressure !== $decision->previousPressure) {
                $trimmed = $this->preparedChunks?->trim($decision->pressure->value >= MemoryPressure::HIGH->value) ?? 0;
                $this->totalPreparedBytesTrimmed = self::saturatingAdd($this->totalPreparedBytesTrimmed, $trimmed);
            }
        }

        if ($this->persistentWorld !== null) {
            $result = $this->persistentWorld->processChunkUnloads($unloadBudget, 2_000);
            $this->recordChunkUnloadResult($result);
        }

        if ($this->garbageCollector === null) {
            return;
        }
        $forceCollection = $decision?->collectCycles === true && (
            $decision->pressure !== $decision->previousPressure
            || ($decision->pressure === MemoryPressure::CRITICAL && $tickNumber % 5 === 0)
            || ($decision->pressure === MemoryPressure::HIGH && $tickNumber % 20 === 0)
            || ($decision->pressure === MemoryPressure::ELEVATED && $tickNumber % 100 === 0)
        );
        $this->lastGarbageCollection = $forceCollection
            ? $this->garbageCollector->collectNow($decision->releaseAllocatorCaches)
            : $this->garbageCollector->maybeCollect();
    }

    private function recordChunkUnloadResult(ChunkUnloadResult $result): void
    {
        $this->lastChunkUnload = $result;
        $this->totalChunksUnloaded = self::saturatingAdd($this->totalChunksUnloaded, $result->evicted);
        if ($result->examined > 0 || $result->persistenceSaturated) {
            $this->diagnostics->record('world.chunk_unload_maintenance', [
                'examined' => $result->examined,
                'evicted' => $result->evicted,
                'save_submissions' => $result->saveSubmissions,
                'persistence_saturated' => $result->persistenceSaturated,
                'remaining_queued' => $result->remainingQueued,
            ]);
        }
    }

    private function synchronizeWorldTime(?string $worldId = null, ?World $world = null): void
    {
        $world ??= $this->persistentWorld;
        if ($world === null) {
            return;
        }
        $packet = new SetTimePacket($world->time());
        foreach (array_keys($this->sessions) as $key) {
            $session = $this->sessions[$key];
            if (!$session->joined || $session->play === null
                || ($worldId !== null && $this->runtimePollKeyForSession($session) !== $worldId)) {
                continue;
            }
            if (!$this->queueWorldPacket($session, $packet)) {
                $this->disconnect($key, 'world_time_backlog_exhausted');
            }
        }
    }

    private static function saturatingAdd(int $left, int $right): int
    {
        return $right > PHP_INT_MAX - $left ? PHP_INT_MAX : $left + $right;
    }

    public function failure(): ?Throwable
    {
        return $this->failure;
    }

    /** Idempotently drains player lifecycle and durable world state before closing transport. */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->simulations() as $simulation) {
            $simulation->beginShutdown();
        }
        foreach (array_keys($this->sessions) as $key) {
            $this->removeRuntimeSession($key, PlayerQuitCause::SERVER_SHUTDOWN, 'Server shutting down');
        }
        $this->pendingTransportCloses = [];
        $this->drainShutdownLifecycle();
        if ($this->worldRuntimes === null) {
            $this->preparedChunks?->close();
        }
        if ($this->playerPersistence !== null) {
            try {
                $this->playerPersistence->retryPending($this->limits->maximumSessions);
            } catch (Throwable $exception) {
                $this->recordShutdownFailure('runtime.player_store_retry_failed', $exception);
            }
            try {
                $durable = $this->playerPersistence->close();
            } catch (Throwable $exception) {
                $durable = false;
                $this->recordShutdownFailure('runtime.player_store_close_failed', $exception);
            }
            if (!$durable || $this->playerPersistence->pendingCount() > 0) {
                $this->recordShutdownFailure(
                    'runtime.player_save_failed',
                    new RuntimeException('One or more player profiles could not be saved during shutdown.'),
                );
            }
        }
        if ($this->worldRuntimes !== null) {
            try {
                $this->worldRuntimes->closeAll();
            } catch (Throwable $exception) {
                $this->recordShutdownFailure('runtime.world_close_failed', $exception);
            }
        } elseif ($this->persistentWorld !== null) {
            try {
                $result = $this->world->flushEntityPersistence();
                if ($result->failedChunksCount() > 0) {
                    throw new RuntimeException('One or more entity chunks could not be saved during shutdown.');
                }
            } catch (Throwable $exception) {
                $this->recordShutdownFailure('runtime.entity_save_failed', $exception);
            }
            try {
                $this->persistentWorld->close();
            } catch (Throwable $exception) {
                $this->recordShutdownFailure('runtime.world_close_failed', $exception);
            }
        }
        try {
            $this->transport->close();
        } catch (Throwable $exception) {
            $this->diagnostics->record('runtime.transport_close_failed', ['exception' => $exception::class]);
            // Runtime state is already closed; shutdown remains idempotent.
        }
    }

    private function open(SessionInfo $info): void
    {
        $key = self::endpointKey($info);
        unset($this->pendingTransportCloses[$key]);
        if (isset($this->sessions[$key]) || count($this->sessions) >= $this->limits->maximumSessions) {
            $this->removeTransportSession($info);

            return;
        }
        if ($this->nextRuntimeEntityId === PHP_INT_MAX) {
            $this->removeTransportSession($info);

            return;
        }
        $id = sprintf('%d@%s:%d', $info->clientGuid, $info->remoteAddress, $info->remotePort);
        try {
            $login = $this->loginChannels->create($info);
        } catch (Throwable $exception) {
            $this->diagnostics->record('login.channel_creation_failed', ['exception' => $exception::class]);
            $this->removeTransportSession($info);

            return;
        }
        $session = new RuntimeSession(
            $info,
            $id,
            UnsignedLong::fromInt($this->nextRuntimeEntityId++),
            $login,
            $this->worldRuntimes?->default()->handle->id() ?? 'world',
        );
        $this->sessions[$key] = $session;
        $this->sessionEndpoints[$id] = $key;
        $this->sessionsById[$id] = $session;
    }

    private function accept(ReceivedPayload $payload): void
    {
        $key = self::rawEndpointKey($payload->remoteAddress, $payload->remotePort);
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession
            || $payload->reliability !== Reliability::ReliableOrdered
            || $payload->orderingChannel !== 0) {
            if ($session instanceof RuntimeSession) {
                $this->disconnect($key, 'invalid_transport_payload_metadata');
            }

            return;
        }
        $event = new ConnectedPayloadEvent($payload->payload, $payload->reliability, $payload->orderingChannel);
        if ($session->phase === SessionPhase::LOGIN && $session->login !== null) {
            $stateBefore = $session->login->state();
            try {
                $accepted = $session->login->accept($event);
            } catch (Throwable $exception) {
                $this->diagnostics->record('login.input_failed', ['exception' => $exception::class]);
                $accepted = false;
            }
            if (!$accepted) {
                $failure = $session->login->failure();
                $this->diagnostics->record('login.session_closed', [
                    'state_before' => strtolower($stateBefore->name),
                    'failure' => $failure !== null ? $failure->value : 'unknown',
                    'protocol' => $session->login->observedProtocolVersion(),
                    'detail' => $session->login->failureDetail(),
                ]);
                $this->disconnect($key, 'login_input_failed');

                return;
            }
            $this->flush($key, $session);
            $this->promoteIfReady($key, $session);

            return;
        }
        if (($session->phase === SessionPhase::INITIALIZING
                || $session->phase === SessionPhase::ADMISSION_PENDING
                || $session->phase === SessionPhase::SPAWNED)
            && $session->play !== null) {
            try {
                $accepted = $session->play->accept($event);
            } catch (Throwable $exception) {
                $this->diagnostics->record('play.input_failed', ['exception' => $exception::class]);
                $accepted = false;
            }
            if (!$accepted) {
                $this->disconnect($key, 'play_input_rejected');

                return;
            }
            if (!$this->tryQueueAdmission($key, $session)) {
                return;
            }
            if ($session->phase === SessionPhase::INITIALIZING) {
                // The client is fully initialized, but admission is deliberately
                // rate-limited. Do not retain stale gameplay commands or treat
                // normal post-initialization traffic as a protocol violation.
                $session->play->drainCommands();
                $this->flush($key, $session);

                return;
            }
            $commands = $session->play->drainCommands();
            $simulation = $this->simulationForSession($session);
            if ($simulation === null) {
                $this->disconnect($key, 'world_runtime_missing');

                return;
            }
            foreach ($commands as $command) {
                if (!$simulation->enqueue($command)) {
                    $this->disconnect($key, 'world_command_queue_exhausted');

                    return;
                }
            }
            if (!$this->dispatchPlayerCommands($session)) {
                $this->disconnect($key, 'player_command_dispatch_failed');
                return;
            }
            $this->flush($key, $session);

            return;
        }
        $this->disconnect($key, 'payload_for_unknown_phase');
    }

    private function dispatchPlayerCommands(RuntimeSession $session): bool
    {
        if ($session->play === null) {
            return true;
        }
        foreach ($session->play->drainPlayerCommands() as $request) {
            $player = null;
            try {
                $messageCount = 0;
                $outputTruncated = false;
                $player = $session->phase === SessionPhase::SPAWNED
                    ? $this->simulationForSession($session)?->pluginPlayer($session->play->login()->identity)
                    : null;
                if ($player !== null) {
                    $player = $this->playerConnections->attach($player);
                }
                if ($this->commandRegistry !== null && $player !== null) {
                    $sender = new ServerPlayerCommandSender(
                        $player,
                        static function (string $message) use (&$messageCount, &$outputTruncated, $player): void {
                            if ($message === ''
                                || strlen($message) > self::MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGE_BYTES
                                || preg_match('//u', $message) !== 1
                                || str_contains($message, "\0")) {
                                throw new InvalidArgumentException('Command output must be valid, bounded text.');
                            }
                            if ($messageCount >= self::MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGES - 1) {
                                $outputTruncated = true;

                                return;
                            }
                            if (!$player->sendMessage($message)) {
                                if (!$player->isConnected()) {
                                    return;
                                }
                                throw new \RuntimeException('Player command output could not be queued.');
                            }
                            ++$messageCount;
                        },
                        fn(string $permission): bool => $this->permissionStore?->hasPermission($player->uuid, $permission) ?? false,
                    );
                    $result = $this->commandRegistry->dispatch($sender, $request->command);
                    if (!$player->isConnected()) {
                        continue;
                    }
                    if ($messageCount === 0 && $result->message() !== null) {
                        $sender->sendMessage($result->isSuccess()
                            ? CommandFeedback::normal($sender, $result->message())
                            : CommandFeedback::error($sender, $result->message()));
                    }
                } else {
                    if (!$session->play->queuePacket(new SystemTextPacket(
                        TextFormat::RED . 'Commands are not available yet.' . TextFormat::RESET,
                    ))) {
                        return false;
                    }
                }
                if ($outputTruncated) {
                    $player?->sendMessage(TextFormat::YELLOW . 'Additional command output was truncated.' . TextFormat::RESET);
                }
            } catch (Throwable $failure) {
                $this->diagnostics->record('runtime.player_command_failed.protocol_trace', [
                    'exception' => $failure::class,
                ]);
                if ($player !== null && !$player->isConnected()) {
                    continue;
                }
                if (!$session->play->queuePacket(new SystemTextPacket(
                    TextFormat::RED . 'The command failed internally.' . TextFormat::RESET,
                ))) {
                    return false;
                }
            }
        }
        return true;
    }

    private function promoteIfReady(string $key, RuntimeSession $session): void
    {
        if ($session->phase !== SessionPhase::LOGIN || $session->login === null) {
            return;
        }
        $ready = $session->login->takeReady();
        if ($ready === null) {
            return;
        }
        try {
            $bootstrap = null;
            $addressBan = $this->bans?->addressBan($session->transport->remoteAddress);
            $playerBan = $this->bans?->playerBan($ready->login->displayName, $ready->login->identity);
            $ban = $addressBan ?? $playerBan;
            if ($ban !== null) {
                $this->diagnostics->record('play.ban_rejected', [
                    'kind' => $addressBan !== null ? 'address' : 'player',
                ]);
                $this->rejectReadySession(
                    $key,
                    $session,
                    $ready,
                    'You are banned from this server.' . "\n" . $ban->reason,
                );
                return;
            }
            if ($this->whitelist?->isEnabled() === true
                && !($this->permissionStore?->isOperator($ready->login->identity) ?? false)
                && !$this->whitelist->admit($ready->login->displayName, $ready->login->identity)) {
                $this->diagnostics->record('play.whitelist_rejected');
                $this->rejectReadySession($key, $session, $ready, 'You are not whitelisted on this server.');
                return;
            }
            if ($this->playerPersistence !== null) {
                $loaded = $this->playerPersistence->load($ready->login);
                foreach ($this->sessions as $other) {
                    if ($other !== $session && $other->bootstrap?->identity->uuid === $loaded->identity->uuid) {
                        $this->diagnostics->record('play.duplicate_identity_rejected');
                        $this->rejectReadySession($key, $session, $ready, 'This account is already connected to this server.');

                        return;
                    }
                }
                $targetRuntime = $this->worldRuntimes?->get($loaded->worldName, $loaded->dimension)
                    ?? $this->worldRuntimes?->default($loaded->dimension)
                    ?? $this->worldRuntimes?->default();
                if ($targetRuntime !== null && (
                    $loaded->worldName !== $targetRuntime->handle->id()
                    || $loaded->dimension !== $targetRuntime->opened->world->dimension()
                )) {
                    $loaded = new \Bedriox\Server\Player\PlayerBootstrap(
                        $loaded->identity,
                        $targetRuntime->handle->id(),
                        new \Bedriox\Server\Simulation\Position(
                            $targetRuntime->opened->world->spawn()->x + 0.5,
                            $targetRuntime->opened->world->spawn()->y,
                            $targetRuntime->opened->world->spawn()->z + 0.5,
                        ),
                        $loaded->yaw,
                        $loaded->pitch,
                        $loaded->inventory,
                        $loaded->firstPlayedAt,
                        $loaded->lastPlayedAt,
                        $loaded->gamemode,
                        $loaded->health,
                        $loaded->food,
                        $loaded->saturation,
                        $loaded->exhaustion,
                        $loaded->effects,
                        $loaded->absorption,
                        $loaded->airTicks,
                        $loaded->fireTicks,
                        $loaded->effectPersistenceState,
                        $loaded->totalExperience,
                        $loaded->spawnPoint,
                        $targetRuntime->opened->world->dimension(),
                    );
                }
                if ($targetRuntime !== null) {
                    $session->worldId = $targetRuntime->handle->id();
                    $session->dimension = $targetRuntime->opened->world->dimension();
                }
                $bootstrap = ($targetRuntime === null ? $this->world : $targetRuntime->simulation)->prepareLogin(
                    $session->id,
                    $session->runtimeEntityId->toSignedBits(),
                    $loaded,
                );
                if ($bootstrap === null) {
                    $this->diagnostics->record('play.login_cancelled');
                    $ready->encryptor->close();
                    $ready->decryptor->close();
                    $this->disconnect($key, 'duplicate_identity_rejection_failed');

                    return;
                }
            }
            $play = $this->playChannels->create($ready, $session->id, $session->runtimeEntityId, $bootstrap);
            $session->craftingCatalogRevision = $this->craftingCatalog?->revision() ?? 0;
            $session->bootstrap = $bootstrap;
            $session->promote($play);
            $identity = $bootstrap?->identity->uuid ?? $ready->login->identity;
            $this->playerConnections->connect(
                $identity,
                $session->id,
                fn(): bool => isset($this->sessions[$key])
                    && $this->sessions[$key] === $session
                    && $session->play !== null
                    && $session->phase !== SessionPhase::CLOSING,
                fn(Packet $packet, bool $immediate): bool => $this->sendPlayerPacket(
                    $key,
                    $session,
                    $packet,
                    $immediate,
                ),
                fn(string $reason, ?string $quitMessage, ?string $screenMessage, PlayerKickCause $cause, ?string $actor): bool => $this->kickPlayer(
                    $key,
                    $session,
                    $reason,
                    $quitMessage,
                    $screenMessage,
                    $cause,
                    $actor,
                ),
                fn(): bool => $this->simulationForSession($session)?->enqueuePluginArmSwing($identity) ?? false,
                fn(ApiPosition $position): bool => $this->acceptPluginAction(
                    fn(): bool => $this->teleportPlayer($identity, $position),
                ),
                fn(GameMode $gameMode): bool => $this->acceptPluginAction(
                    fn(): bool => $this->changePlayerGameMode($identity, $gameMode),
                ),
                fn(ApiItemStack $stack): bool => $this->acceptPluginAction(
                    fn(): bool => $this->givePlayerStack($identity, $stack),
                ),
                fn(int $slot, ?ApiItemStack $stack): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerInventorySlot($identity, $slot, $stack),
                ),
                fn(float $amount): bool => $this->acceptPluginAction(
                    fn(): bool => $this->damagePlayer($identity, $amount),
                ),
                fn(array $contents): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerInventoryContents($identity, $contents),
                ),
                fn(ApiItemStack $stack): bool => $this->acceptPluginAction(
                    fn(): bool => $this->removePlayerInventoryItem($identity, $stack),
                ),
                fn(int $slot): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerSelectedHotbarSlot($identity, $slot),
                ),
                fn(EquipmentSlot $slot, ?ApiItemStack $stack): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerEquipmentItem($identity, $slot, $stack),
                ),
                fn(array $contents): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerArmorContents($identity, $contents),
                ),
                fn(ApiItemStack $stack): int => $this->maximumPlayerStackSize($stack),
                fn(EffectInstance $effect, EffectCause $cause): bool => $this->acceptPluginAction(
                    fn(): bool => $this->addPlayerEffect($identity, $effect, $cause),
                ),
                fn(EffectType $type, EffectCause $cause): bool => $this->acceptPluginAction(
                    fn(): bool => $this->removePlayerEffect($identity, $type, $cause),
                ),
                fn(EffectCause $cause): bool => $this->acceptPluginAction(
                    fn(): bool => $this->clearPlayerEffects($identity, $cause),
                ),
                fn(int $totalPoints, ExperienceChangeCause $cause): bool => $this->acceptPluginAction(
                    fn(): bool => $this->setPlayerExperience($identity, $totalPoints, $cause),
                ),
                fn(): ?ApiEntity => $this->mountedVehicle($identity),
                fn(ApiEntity $vehicle, MountSeat $seat): bool => $this->acceptPluginAction(
                    fn(): bool => $this->mountPlayer($identity, $vehicle, $seat),
                ),
                fn(): bool => $this->acceptPluginAction(
                    fn(): bool => $this->dismountPlayer($identity),
                ),
            );
            $this->logJoining($session, $ready->login->displayName);
            $this->flush($key, $session);
        } catch (Throwable $exception) {
            $this->diagnostics->record('play.channel_creation_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $ready->encryptor->close();
            $ready->decryptor->close();
            $this->disconnect($key, 'admission_projection_failed');
        }
    }

    /** Queues admission exactly once when the play channel releases its readiness latch. */
    private function tryQueueAdmission(string $key, RuntimeSession $session): bool
    {
        if ($session->phase !== SessionPhase::INITIALIZING || $session->play === null
            || $this->admissionCommandsQueued >= self::PLAYER_ADMISSIONS_PER_TICK
            || !$session->play->takeSpawnAcknowledged()) {
            return true;
        }
        $login = $session->play->login();
        try {
            $identityUuid = $login->identity;
            $identityName = $login->displayName;
            if ($session->bootstrap !== null) {
                $identityUuid = $session->bootstrap->identity->uuid;
                $identityName = $session->bootstrap->identity->displayName;
            }
            $join = $this->commands->join(
                $session->id,
                $identityUuid,
                $identityName,
                $session->runtimeEntityId->toSignedBits(),
                $session->bootstrap,
                $session->bootstrap !== null,
            );
        } catch (Throwable $exception) {
            $this->diagnostics->record('play.admission_command_failed', ['exception' => $exception::class]);
            $this->disconnect($key, 'admission_command_failed');

            return false;
        }
        $simulation = $this->simulationForSession($session);
        if ($simulation === null) {
            $this->disconnect($key, 'world_runtime_missing');

            return false;
        }
        if (!$simulation->enqueue($join)) {
            $this->disconnect($key, 'lifecycle_queue_exhausted');

            return false;
        }
        $session->phase = SessionPhase::ADMISSION_PENDING;
        ++$this->admissionCommandsQueued;

        return true;
    }

    private function sendPlayerPacket(string $key, RuntimeSession $session, Packet $packet, bool $immediate): bool
    {
        if (($this->sessions[$key] ?? null) !== $session || $session->play === null
            || $session->phase === SessionPhase::CLOSING || !$session->play->queuePacket($packet)) {
            return false;
        }
        if ($immediate) {
            $this->flush($key, $session);
        }

        return ($this->sessions[$key] ?? null) === $session;
    }

    private function flush(string $key, RuntimeSession $session): void
    {
        $outgoing = $session->phase === SessionPhase::LOGIN
            ? $session->login?->drainOutgoing() ?? []
            : $session->play?->drainOutgoing() ?? [];
        foreach ($outgoing as $payload) {
            try {
                $this->transport->sendPayload(
                    $session->transport->remoteAddress,
                    $session->transport->remotePort,
                    $payload->payload,
                    $payload->reliability,
                    $payload->orderingChannel,
                );
                $this->performance?->recordNetworkSent(strlen($payload->payload));
            } catch (Throwable $exception) {
                $this->diagnostics->record('runtime.transport_send_failed', [
                    'exception' => $exception::class,
                    'reason' => self::transportSendFailureReason($exception),
                    'payload_bytes' => strlen($payload->payload),
                    'reliability' => strtolower((string) preg_replace(
                        '/(?<!^)[A-Z]/',
                        '_$0',
                        $payload->reliability->name,
                    )),
                    'ordering_channel' => $payload->orderingChannel,
                    'phase' => strtolower($session->phase->name),
                ]);
                $this->disconnect($key, 'transport_send_failed');

                return;
            }
        }
    }

    private function flushConnectedSessions(): void
    {
        foreach ($this->sessions as $key => $session) {
            $this->flush($key, $session);
        }
    }

    private static function transportSendFailureReason(Throwable $exception): string
    {
        return match ($exception->getMessage()) {
            'Outbound frame queue limit reached.' => 'outbound_frame_queue',
            'Reliable frame tracking limit reached.' => 'reliable_frame_tracking',
            'Payload requires too many fragments.' => 'payload_fragment_limit',
            'Reliable index space collided with a pending frame.' => 'reliable_index_collision',
            'Outbound split-ID lease limit reached.' => 'split_id_lease',
            'No outbound split ID is available.' => 'split_id_exhausted',
            default => 'send_failure',
        };
    }

    /** @return array{PlayerQuitCause, string} */
    private static function transportQuitReason(SessionCloseReason $reason): array
    {
        return match ($reason) {
            SessionCloseReason::RemoteDisconnect => [PlayerQuitCause::DISCONNECTED, 'Disconnected'],
            SessionCloseReason::HandshakeTimeout, SessionCloseReason::IdleTimeout => [PlayerQuitCause::TIMED_OUT, 'Connection timed out'],
            SessionCloseReason::ServerClosed => [PlayerQuitCause::SERVER_SHUTDOWN, 'Server shutting down'],
            SessionCloseReason::TransportFailure => [PlayerQuitCause::CONNECTION_LOST, 'Transport failure'],
            SessionCloseReason::LocalRemoval => [PlayerQuitCause::DISCONNECTED, 'Disconnected by the server'],
        };
    }

    private static function readableInternalDisconnectReason(string $reason): string
    {
        $readable = trim(str_replace('_', ' ', $reason));

        return $readable === '' ? 'Connection lost' : ucfirst($readable);
    }

    private function disconnect(string $key, string $reason = 'unspecified'): void
    {
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession) {
            return;
        }
        $this->diagnostics->record('runtime.session_disconnected', [
            'phase' => strtolower($session->phase->name),
            'reason' => $reason,
        ]);
        $this->removeTransportSession($session->transport);
        $this->removeRuntimeSession($key, PlayerQuitCause::CONNECTION_LOST, self::readableInternalDisconnectReason($reason));
    }

    private function closeEndpoint(SessionInfo $info, PlayerQuitCause $cause, string $reason): void
    {
        $key = self::endpointKey($info);
        if (($this->pendingTransportCloses[$key][0] ?? null) === $info) {
            unset($this->pendingTransportCloses[$key]);
        }
        if (($this->sessions[$key]->transport ?? null) !== $info) {
            return;
        }
        $this->removeRuntimeSession($key, $cause, $reason);
    }

    private function kickPlayer(
        string $key,
        RuntimeSession $session,
        string $reason,
        ?string $quitMessage,
        ?string $screenMessage,
        PlayerKickCause $cause = PlayerKickCause::SERVER_POLICY,
        ?string $actor = null,
    ): bool {
        if (($this->sessions[$key] ?? null) !== $session || $session->play === null) {
            return false;
        }
        $identity = $session->bootstrap?->identity->uuid ?? $session->play->login()->identity;
        $player = $this->simulationForSession($session)?->pluginPlayer($identity);
        if ($player !== null && $this->pluginEvents !== null) {
            $decision = $this->pluginEvents->kick($player, $cause, $reason, $quitMessage, $screenMessage, $actor);
            if ($decision === null) {
                return false;
            }
            [$reason, $quitMessage, $screenMessage] = $decision;
        }
        $message = $screenMessage ?? ($reason !== '' ? $reason : 'Disconnected from server.');
        try {
            $packet = new DisconnectPacket(DisconnectReason::KICKED, false, $message, $message);
        } catch (Throwable) {
            return false;
        }
        if (!$session->play->queuePacket($packet)) {
            $this->disconnect($key, 'kick_packet_queue_failed');
            return false;
        }
        $this->flush($key, $session);
        if (($this->sessions[$key] ?? null) !== $session) {
            return false;
        }
        $quitCause = match ($cause) {
            PlayerKickCause::BAN => PlayerQuitCause::BANNED,
            default => PlayerQuitCause::KICKED,
        };
        $this->deferTransportClose($key, $session, $quitCause, $reason, $actor, $quitMessage);

        return true;
    }

    private function rejectReadySession(string $key, RuntimeSession $session, \Bedriox\Server\Login\LoginChannelReady $ready, string $message): void
    {
        $this->logFailedJoin($session, $ready->login->displayName, $message);
        try {
            $packet = new DisconnectPacket(DisconnectReason::KICKED, false, $message, $message);
            $batch = new BedrockBatch([
                new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet, $ready->protocolVersion)),
            ], CompressionMode::NegotiatedZlib, NetworkCompressionPolicy::THRESHOLD_BYTES);
            $this->transport->sendPayload(
                $session->transport->remoteAddress,
                $session->transport->remotePort,
                $ready->encryptor->encryptEnvelope(BedrockBatchCodec::encode($batch, new BatchLimits())),
                Reliability::ReliableOrdered,
                0,
            );
            $this->deferTransportClose($key, $session);
        } catch (Throwable $exception) {
            $this->diagnostics->record('play.duplicate_rejection_send_failed', ['exception' => $exception::class]);
            $this->disconnect($key, 'duplicate_rejection_send_failed');
        } finally {
            $ready->encryptor->close();
            $ready->decryptor->close();
        }
    }

    private function deferTransportClose(
        string $key,
        RuntimeSession $session,
        PlayerQuitCause $cause = PlayerQuitCause::KICKED,
        string $reason = 'Disconnected',
        ?string $actor = null,
        ?string $quitMessage = null,
    ): void {
        $this->pendingTransportCloses[$key] = [$session->transport, $this->closeClock->nowNanoseconds() + 10_000_000_000];
        $this->removeRuntimeSession($key, $cause, $reason, $actor, $quitMessage);
    }

    private function expirePendingTransportCloses(): void
    {
        $now = $this->closeClock->nowNanoseconds();
        foreach ($this->pendingTransportCloses as $key => [$info, $deadline]) {
            if ($now >= $deadline) {
                unset($this->pendingTransportCloses[$key]);
                $this->removeTransportSession($info);
            }
        }
    }

    private function removeTransportSession(SessionInfo $info): void
    {
        try {
            $this->transport->removeSession($info->remoteAddress, $info->remotePort);
        } catch (Throwable $exception) {
            $this->diagnostics->record('runtime.transport_remove_failed', ['exception' => $exception::class]);
            // The local owner still closes; a transport failure must not affect peers.
        }
    }

    private function removeRuntimeSession(
        string $key,
        PlayerQuitCause $cause = PlayerQuitCause::DISCONNECTED,
        string $reason = 'Disconnected',
        ?string $actor = null,
        ?string $quitMessage = null,
    ): void {
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession) {
            return;
        }
        $identity = $session->bootstrap?->identity->uuid ?? $session->play?->login()->identity;
        if ($identity !== null) {
            $this->playerConnections->disconnect($identity, $session->id);
        }
        if ($session->joined && $session->play !== null) {
            $this->playerLifecycleLogger?->left(
                $session->play->login()->displayName,
                $session->transport,
                $cause,
                $reason,
                $actor,
            );
        }
        unset(
            $this->sessions[$key],
            $this->sessionEndpoints[$session->id],
            $this->sessionsById[$session->id],
            $this->deliveredOnlinePlayerCommandRevisions[$key],
            $this->lastPeerMovementBroadcastTicks[$session->id],
            $this->peerMovementBroadcastPhaseSeeds[$session->id],
            $this->deferredWorldPackets[$session->id],
            $this->deferredAuthoritativeMovementFrames[$session->id],
            $this->pendingPlayerWorldTransfers[$session->id],
            $this->pendingPortalTravelNotifications[$session->id],
            $this->joiningLogs[$session->id],
            $this->joinedLogs[$session->id],
            $this->failedJoinLogs[$session->id],
        );
        foreach ($this->itemActorViewers as $worldId => $actors) {
            foreach ($actors as $runtimeId => $viewers) {
                unset($viewers[$session->id]);
                $this->itemActorViewers[$worldId][$runtimeId] = $viewers;
            }
        }
        foreach ($this->entityActorViewers as $worldId => $actors) {
            foreach ($actors as $runtimeId => $viewers) {
                unset($viewers[$session->id]);
                $this->entityActorViewers[$worldId][$runtimeId] = $viewers;
            }
        }
        foreach ($this->projectileViewers as $worldId => $actors) {
            foreach ($actors as $runtimeId => $viewers) {
                unset($viewers[$session->id]);
                $this->projectileViewers[$worldId][$runtimeId] = $viewers;
            }
        }
        foreach ($this->areaEffectCloudViewers as $worldId => $actors) {
            foreach ($actors as $runtimeId => $viewers) {
                unset($viewers[$session->id]);
                $this->areaEffectCloudViewers[$worldId][$runtimeId] = $viewers;
            }
        }
        $this->crashContextDirty = true;
        if ($session->joined || $session->phase === SessionPhase::ADMISSION_PENDING) {
            $simulation = $this->simulationForSession($session);
            if ($simulation !== null && !$simulation->enqueue($this->commands->disconnect(
                $session->id,
                $cause,
                $reason,
                $actor,
                $quitMessage,
            )) && $this->closed) {
                $this->recordShutdownFailure(
                    'runtime.lifecycle_enqueue_failed',
                    new RuntimeException('Unable to enqueue a player disconnect during shutdown.'),
                );
            }
            $session->joined = false;
        }
        $session->close();
    }

    private function drainShutdownLifecycle(): void
    {
        foreach ($this->simulations() as $simulation) {
            while ($simulation->queuedLifecycleCommands() > 0) {
                $before = $simulation->queuedLifecycleCommands();
                try {
                    $tick = $simulation->drainLifecycle();
                    foreach ($tick->events as $event) {
                        if ($event instanceof PlayerDisconnected) {
                            $this->actorVisibility->remove($event->sessionId);
                        }
                    }
                } catch (Throwable $exception) {
                    $this->recordShutdownFailure('runtime.lifecycle_drain_failed', $exception);
                }
                if ($simulation->queuedLifecycleCommands() >= $before) {
                    $this->recordShutdownFailure(
                        'runtime.lifecycle_drain_stalled',
                        new RuntimeException('Player disconnect lifecycle made no progress during shutdown.'),
                    );

                    return;
                }
            }
        }
    }

    private function recordShutdownFailure(string $event, Throwable $exception): void
    {
        $this->failure ??= $exception;
        $this->diagnostics->record($event, ['exception' => $exception::class]);
        $this->publishCrashContext();
    }

    private function failRuntime(string $reason, ?Throwable $exception = null): bool
    {
        $fields = ['reason' => $reason];
        if ($exception !== null) {
            $fields['exception'] = $exception::class;
        }
        $this->diagnostics->record('runtime.failed', $fields);
        $this->failure = $exception ?? new RuntimeException('Server runtime failed: ' . $reason);
        $this->publishCrashContext();
        $this->close();

        return false;
    }

    private function publishCrashContext(): void
    {
        if ($this->crashContext === null) {
            $this->crashContextDirty = false;
            return;
        }
        $players = [];
        $involved = null;
        foreach ($this->sessions as $session) {
            $login = $session->play?->login();
            $player = new CrashPlayer(
                $login->displayName ?? '',
                $login->identity ?? '',
                $login->xuid ?? '',
                $session->transport->remoteAddress . ':' . $session->transport->remotePort,
                $login?->clientData->deviceOs->name ?? 'Unknown',
                $session->phase->name,
            );
            $players[] = $player;
            if ($session->id === $this->involvedSessionId) {
                $involved = $player;
            }
        }
        $tick = 0;
        foreach ($this->simulations() as $simulation) {
            $tick = max($tick, $simulation->snapshot()->tick);
        }
        $this->crashContext->publishRuntime($tick, $players, $involved);
        $this->crashContextDirty = false;
    }

    /** Reconciles the asynchronous authoritative join result before any event is encoded. */
    private function reconcileAdmission(WorldEvent $event): bool
    {
        if ($event instanceof PlayerJoined) {
            $session = $this->sessionById($event->player->sessionId);
            if ($session?->phase === SessionPhase::ADMISSION_PENDING) {
                $session->joined = true;
                $session->phase = SessionPhase::SPAWNED;
                $this->crashContextDirty = true;
            }
            if ($session !== null && !isset($this->joinedLogs[$session->id])) {
                $this->joinedLogs[$session->id] = true;
                $this->playerLifecycleLogger?->joined($event->player, $session->worldId ?? 'world', $session->transport);
            }

            return true;
        }
        if ($event instanceof CommandRejected
            && in_array($event->reason, ['duplicate_session', 'duplicate_identity', 'world_full', 'plugin_cancelled'], true)) {
            $session = $this->sessionById($event->sessionId);
            if ($session?->phase === SessionPhase::ADMISSION_PENDING) {
                $this->logFailedJoin(
                    $session,
                    $session->play?->login()->displayName ?? 'Unknown player',
                    match ($event->reason) {
                        'duplicate_session', 'duplicate_identity' => 'This account is already connected to this server.',
                        'world_full' => 'The destination world is full.',
                        default => 'A plugin rejected the connection.',
                    },
                );
                $this->disconnect(self::endpointKey($session->transport), 'admission_event_failure');

                return false;
            }
        }

        return true;
    }

    private function logJoining(RuntimeSession $session, string $name): void
    {
        if (isset($this->joiningLogs[$session->id])) {
            return;
        }
        $this->joiningLogs[$session->id] = true;
        $this->playerLifecycleLogger?->joining($name, $session->transport);
    }

    private function logFailedJoin(RuntimeSession $session, string $name, string $reason): void
    {
        if (isset($this->failedJoinLogs[$session->id]) || isset($this->joinedLogs[$session->id])) {
            return;
        }
        $this->failedJoinLogs[$session->id] = true;
        $this->playerLifecycleLogger?->failed($name, $session->transport, $reason);
    }

    /**
     * Contains a causal event failure without penalizing healthy recipients.
     *
     * @param array<string, bool|int|string|null> $fields
     */
    private function containEventFailure(WorldEvent $event, string $diagnostic, array $fields = []): bool
    {
        $ownerSessionId = $this->eventOwnerSessionId($event);
        $this->diagnostics->record($diagnostic, ['event_type' => $event::class] + $fields);
        if ($event instanceof ItemEntitySpawned || $event instanceof ItemEntityMoved
            || $event instanceof ItemEntityPickedUp || $event instanceof ItemEntityDespawned
            || $event instanceof EntityActorSpawned || $event instanceof EntityActorMoved
            || $event instanceof EntityActorDamaged || $event instanceof EntityActorDied
            || $event instanceof EntityActorRemoved || $event instanceof \Bedriox\Server\Simulation\Event\EntityActorEffectChanged
            || $event instanceof ProjectileSpawned || $event instanceof ProjectileMoved
            || $event instanceof PotionSplashImpacted || $event instanceof ProjectileRemoved
            || $event instanceof AreaEffectCloudSpawned
            || $event instanceof AreaEffectCloudUpdated || $event instanceof AreaEffectCloudRemoved) {
            return true;
        }
        if ($ownerSessionId === null) {
            return $this->failRuntime('ownerless_event_failure');
        }
        $key = $this->sessionEndpoints[$ownerSessionId] ?? null;
        if ($key !== null) {
            $this->disconnect($key, 'owned_event_failure');
        }

        return true;
    }

    /** Returns the session whose command caused the event, independently of its fan-out recipients. */
    private function eventOwnerSessionId(WorldEvent $event): ?string
    {
        return match (true) {
            $event instanceof ChatBroadcast => $event->senderSessionId,
            $event instanceof BlockBreakStarted, $event instanceof BlockPunch, $event instanceof BlockBreakStopped, $event instanceof BlockChanged,
            $event instanceof BlockPlaced, $event instanceof BlockPlacementCorrected, $event instanceof HeldItemChanged => $event->ownerSessionId,
            $event instanceof CommandRejected => $event->sessionId,
            $event instanceof EmotePerformed => $event->senderSessionId,
            $event instanceof ArmSwung => $event->ownerSessionId,
            $event instanceof MovementCorrected => $event->authoritativePlayer->sessionId,
            $event instanceof PlayerDisconnected => $event->sessionId,
            $event instanceof PlayerBecameHidden => $event->playerSessionId,
            $event instanceof PlayerBecameVisible => $event->player->sessionId,
            $event instanceof PlayerKnockedBack, $event instanceof PlayerMotionChanged => $event->ownerSessionId,
            $event instanceof PlayerJoined, $event instanceof PlayerMoved, $event instanceof PlayerDamaged,
            $event instanceof PlayerDied, $event instanceof PlayerRespawned, $event instanceof RespawnAcknowledged,
            $event instanceof PlayerGameModeChanged,
            $event instanceof \Bedriox\Server\Simulation\Event\PlayerEffectChanged,
            $event instanceof \Bedriox\Server\Simulation\Event\PlayerEnvironmentChanged => $event->player->sessionId,
            $event instanceof EntityInteracted => $event->ownerSessionId,
            default => null,
        };
    }

    /** @return list<WorldEvent> */
    private function reconcileActorVisibility(string $sessionId): array
    {
        return [
            ...$this->actorVisibility->reconcileActor(
                $sessionId,
                fn(string $viewer, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool => $this->viewerCanSee(
                    $viewer,
                    $actor,
                ),
            ),
            ...$this->reconcileActorVisibilityForViewer($sessionId),
        ];
    }

    /** @param null|array<string, true> $viewerChunkKeys */
    private function enqueueActorVisibilityReconciliation(
        string $sessionId,
        bool $actor,
        bool $viewer,
        ?array $viewerChunkKeys = null,
    ): void {
        $peerSessionIds = $viewer && $viewerChunkKeys !== null
            ? $this->actorVisibility->sessionIdsInChunks($viewerChunkKeys)
            : $this->actorVisibility->sessionIds();
        foreach ($peerSessionIds as $peerSessionId) {
            if ($peerSessionId === $sessionId) {
                continue;
            }
            if ($actor) {
                $this->enqueueActorVisibilityPair($peerSessionId, $sessionId);
            }
            if ($viewer) {
                $this->enqueueActorVisibilityPair($sessionId, $peerSessionId);
            }
        }
    }

    private function drainActorVisibilityReconciliations(int &$directedCount): bool
    {
        $startedNanoseconds = hrtime(true);
        $processed = 0;
        while (!$this->pendingActorVisibilityPairs->isEmpty()
            && $processed < self::ACTOR_VISIBILITY_PAIRS_PER_TICK
            && ($processed === 0 || hrtime(true) - $startedNanoseconds < self::ACTOR_VISIBILITY_BUDGET_NANOSECONDS)) {
            [$viewerSessionId, $actorSessionId] = $this->pendingActorVisibilityPairs->dequeue();
            unset($this->pendingActorVisibilityPairKeys[self::actorVisibilityPairKey($viewerSessionId, $actorSessionId)]);
            ++$processed;
            $visibilityEvent = $this->actorVisibility->reconcilePairById(
                $viewerSessionId,
                $actorSessionId,
                fn(string $viewer, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool => $this->viewerCanSee(
                    $viewer,
                    $actor,
                ),
            );
            if ($visibilityEvent !== null && !$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                return false;
            }
        }

        return true;
    }

    private function enqueueActorVisibilityPair(string $viewerSessionId, string $actorSessionId): void
    {
        $key = self::actorVisibilityPairKey($viewerSessionId, $actorSessionId);
        if (isset($this->pendingActorVisibilityPairKeys[$key])) {
            return;
        }
        $this->pendingActorVisibilityPairKeys[$key] = true;
        $this->pendingActorVisibilityPairs->enqueue([$viewerSessionId, $actorSessionId]);
    }

    private static function actorVisibilityPairKey(string $viewerSessionId, string $actorSessionId): string
    {
        return $viewerSessionId . "\0" . $actorSessionId;
    }

    /** @return list<WorldEvent> */
    private function reconcileMovedActorVisibility(string $sessionId): array
    {
        return $this->actorVisibility->reconcileActor(
            $sessionId,
            fn(string $viewer, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool => $this->viewerCanSee(
                $viewer,
                $actor,
            ),
        );
    }

    /** @return list<WorldEvent> */
    private function reconcileActorVisibilityForViewer(string $sessionId): array
    {
        $viewer = $this->sessionById($sessionId);

        return $this->actorVisibility->reconcileViewer(
            $sessionId,
            fn(\Bedriox\Server\Simulation\PlayerSnapshot $actor): bool => $viewer?->phase === SessionPhase::SPAWNED
                && ($actorSession = $this->sessionById($actor->sessionId))?->phase === SessionPhase::SPAWNED
                && $viewer->worldId === $actorSession->worldId
                && $viewer->dimension === $actorSession->dimension
                && $actor->gameMode->isVisible()
                && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false),
        );
    }

    private function viewerCanSee(string $viewerSessionId, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool
    {
        $viewer = $this->sessionById($viewerSessionId);
        $actorSession = $this->sessionById($actor->sessionId);

        return $viewer?->phase === SessionPhase::SPAWNED
            && $actorSession?->phase === SessionPhase::SPAWNED
            && $viewer->worldId === $actorSession->worldId
            && $viewer->dimension === $actorSession->dimension
            && $actor->gameMode->isVisible()
            && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false);
    }

    /**
     * @return list<ItemEntitySpawned|ItemEntityMoved|ItemEntityPickedUp|ItemEntityDespawned>
     */
    private function reconcileItemEvent(
        ItemEntitySpawned|ItemEntityMoved|ItemEntityPickedUp|ItemEntityDespawned $event,
    ): array {
        $worldId = $this->processingWorldId ?? 'world';
        if ($event instanceof ItemEntityPickedUp) {
            $viewers = array_keys($this->itemActorViewers[$worldId][$event->itemRuntimeActorId] ?? []);
            $this->diagnostics->record('world.item_actor.protocol_trace', [
                'action' => 'picked_up',
                'actor_id' => $event->itemRuntimeActorId,
                'viewers' => count($viewers),
            ]);
            unset($this->itemActors[$worldId][$event->itemRuntimeActorId], $this->itemActorViewers[$worldId][$event->itemRuntimeActorId]);

            return [new ItemEntityPickedUp(
                $event->itemRuntimeActorId,
                $event->collectorRuntimeActorId,
                $event->stack,
                $event->collectorSessionId,
                true,
                $event->mainInventory,
                $viewers,
            )];
        }
        if ($event instanceof ItemEntityDespawned) {
            $viewers = array_keys($this->itemActorViewers[$worldId][$event->runtimeActorId] ?? []);
            $this->diagnostics->record('world.item_actor.protocol_trace', [
                'action' => 'expired',
                'actor_id' => $event->runtimeActorId,
                'viewers' => count($viewers),
            ]);
            unset($this->itemActors[$worldId][$event->runtimeActorId], $this->itemActorViewers[$worldId][$event->runtimeActorId]);

            return $viewers === [] ? [] : [new ItemEntityDespawned($event->runtimeActorId, $viewers)];
        }

        $entity = $event->entity;
        $runtimeId = $entity->runtimeEntityId;
        $this->itemActors[$worldId][$runtimeId] = $entity;
        $previous = $this->itemActorViewers[$worldId][$runtimeId] ?? [];
        $eligible = $previous;
        foreach (array_values(array_unique($event->recipientSessionIds)) as $recipient) {
            if ($this->sessionById($recipient)?->play?->hasSentChunkAt(
                $entity->position->x,
                $entity->position->z,
            ) ?? false) {
                $eligible[$recipient] = true;
            } else {
                unset($eligible[$recipient]);
            }
        }
        $this->itemActorViewers[$worldId][$runtimeId] = $eligible;
        $appeared = array_keys(array_diff_key($eligible, $previous));
        $disappeared = array_keys(array_diff_key($previous, $eligible));
        if ($appeared !== [] || $disappeared !== []) {
            $this->diagnostics->record('world.item_actor.protocol_trace', [
                'action' => 'visibility_changed',
                'actor_id' => $runtimeId,
                'shown_to' => count($appeared),
                'hidden_from' => count($disappeared),
            ]);
        }
        $events = [];
        if ($disappeared !== []) {
            $events[] = new ItemEntityDespawned($runtimeId, $disappeared);
        }
        if ($appeared !== []) {
            $events[] = new ItemEntitySpawned($entity, $appeared);
        }
        if ($event instanceof ItemEntityMoved) {
            $continuing = array_keys(array_intersect_key($eligible, $previous));
            if ($continuing !== []) {
                $events[] = new ItemEntityMoved($entity, $event->tick, $continuing, $event->motionChanged);
            }
        }

        return $events;
    }

    /**
     * @param null|array<string, true> $chunkKeys
     * @return list<ItemEntitySpawned|ItemEntityDespawned>
     */
    private function reconcileItemsForViewer(string $sessionId, ?array $chunkKeys = null): array
    {
        $events = [];
        $worldId = $this->processingWorldId ?? 'world';
        foreach ($this->itemActors[$worldId] ?? [] as $entity) {
            if ($chunkKeys !== null && !isset($chunkKeys[self::positionChunkKey(
                $entity->position->x,
                $entity->position->z,
            )])) {
                continue;
            }
            foreach ($this->reconcileItemEvent(new ItemEntitySpawned($entity, [$sessionId])) as $event) {
                if ($event instanceof ItemEntitySpawned || $event instanceof ItemEntityDespawned) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    /** @return list<ProjectileSpawned|ProjectileMoved|ProjectileRemoved> */
    private function reconcileProjectileEvent(
        ProjectileSpawned|ProjectileMoved|ProjectileRemoved $event,
    ): array {
        $worldId = $this->processingWorldId ?? 'world';
        if ($event instanceof ProjectileRemoved) {
            $viewers = array_keys($this->projectileViewers[$worldId][$event->runtimeEntityId] ?? []);
            unset(
                $this->projectileActors[$worldId][$event->runtimeEntityId],
                $this->projectileViewers[$worldId][$event->runtimeEntityId],
            );

            return $viewers === [] ? [] : [new ProjectileRemoved($event->runtimeEntityId, $viewers)];
        }

        $projectile = $event->projectile;
        $runtimeId = $projectile->runtimeEntityId;
        $this->projectileActors[$worldId][$runtimeId] = $projectile;
        $old = $this->projectileViewers[$worldId][$runtimeId] ?? [];
        $eligible = $old;
        foreach (array_keys($eligible) as $recipient) {
            if (!$this->transientActorViewerCanSee($recipient, $worldId, $projectile->position)) {
                unset($eligible[$recipient]);
            }
        }
        foreach (array_values(array_unique($event->recipientSessionIds)) as $recipient) {
            if ($this->transientActorViewerCanSee($recipient, $worldId, $projectile->position)) {
                $eligible[$recipient] = true;
            } else {
                unset($eligible[$recipient]);
            }
        }
        $this->projectileViewers[$worldId][$runtimeId] = $eligible;
        $appeared = array_keys(array_diff_key($eligible, $old));
        $disappeared = array_keys(array_diff_key($old, $eligible));
        $events = [];
        if ($disappeared !== []) {
            $events[] = new ProjectileRemoved($runtimeId, $disappeared);
        }
        if ($appeared !== []) {
            $events[] = new ProjectileSpawned($projectile, $appeared);
        }
        if ($event instanceof ProjectileMoved) {
            $continuing = array_keys(array_intersect_key($eligible, $old));
            if ($continuing !== []) {
                $events[] = new ProjectileMoved(
                    $projectile,
                    $continuing,
                    $event->motionChanged,
                    $event->embedded,
                );
            }
        }

        return $events;
    }

    /** @return list<AreaEffectCloudSpawned|AreaEffectCloudUpdated|AreaEffectCloudRemoved> */
    private function reconcileAreaEffectCloudEvent(
        AreaEffectCloudSpawned|AreaEffectCloudUpdated|AreaEffectCloudRemoved $event,
    ): array {
        $worldId = $this->processingWorldId ?? 'world';
        if ($event instanceof AreaEffectCloudRemoved) {
            $viewers = array_keys($this->areaEffectCloudViewers[$worldId][$event->runtimeEntityId] ?? []);
            unset(
                $this->areaEffectCloudActors[$worldId][$event->runtimeEntityId],
                $this->areaEffectCloudViewers[$worldId][$event->runtimeEntityId],
            );

            return $viewers === [] ? [] : [new AreaEffectCloudRemoved($event->runtimeEntityId, $viewers)];
        }

        $cloud = $event->cloud;
        $runtimeId = $cloud->runtimeEntityId;
        $this->areaEffectCloudActors[$worldId][$runtimeId] = $cloud;
        $old = $this->areaEffectCloudViewers[$worldId][$runtimeId] ?? [];
        $eligible = $old;
        foreach (array_keys($eligible) as $recipient) {
            if (!$this->transientActorViewerCanSee($recipient, $worldId, $cloud->position)) {
                unset($eligible[$recipient]);
            }
        }
        foreach (array_values(array_unique($event->recipientSessionIds)) as $recipient) {
            if ($this->transientActorViewerCanSee($recipient, $worldId, $cloud->position)) {
                $eligible[$recipient] = true;
            } else {
                unset($eligible[$recipient]);
            }
        }
        $this->areaEffectCloudViewers[$worldId][$runtimeId] = $eligible;
        $appeared = array_keys(array_diff_key($eligible, $old));
        $disappeared = array_keys(array_diff_key($old, $eligible));
        $events = [];
        if ($disappeared !== []) {
            $events[] = new AreaEffectCloudRemoved($runtimeId, $disappeared);
        }
        if ($appeared !== []) {
            $events[] = new AreaEffectCloudSpawned($cloud, $appeared);
        }
        if ($event instanceof AreaEffectCloudUpdated) {
            $continuing = array_keys(array_intersect_key($eligible, $old));
            if ($continuing !== []) {
                $events[] = new AreaEffectCloudUpdated($cloud, $continuing);
            }
        }

        return $events;
    }

    /**
     * @param null|array<string, true> $chunkKeys
     * @return list<ProjectileSpawned|ProjectileRemoved|AreaEffectCloudSpawned|AreaEffectCloudRemoved>
     */
    private function reconcileTransientActorsForViewer(string $sessionId, ?array $chunkKeys = null): array
    {
        $events = [];
        $worldId = $this->processingWorldId ?? 'world';
        foreach ($this->projectileActors[$worldId] ?? [] as $projectile) {
            if ($chunkKeys !== null && !isset($chunkKeys[self::positionChunkKey(
                $projectile->position->x,
                $projectile->position->z,
            )])) {
                continue;
            }
            foreach ($this->reconcileProjectileEvent(new ProjectileSpawned($projectile, [$sessionId])) as $event) {
                if ($event instanceof ProjectileSpawned || $event instanceof ProjectileRemoved) {
                    $events[] = $event;
                }
            }
        }
        foreach ($this->areaEffectCloudActors[$worldId] ?? [] as $cloud) {
            if ($chunkKeys !== null && !isset($chunkKeys[self::positionChunkKey(
                $cloud->position->x,
                $cloud->position->z,
            )])) {
                continue;
            }
            foreach ($this->reconcileAreaEffectCloudEvent(new AreaEffectCloudSpawned($cloud, [$sessionId])) as $event) {
                if ($event instanceof AreaEffectCloudSpawned || $event instanceof AreaEffectCloudRemoved) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private function transientActorViewerCanSee(
        string $sessionId,
        string $worldId,
        \Bedriox\Server\Simulation\Position $position,
    ): bool {
        $viewer = $this->sessionById($sessionId);

        return $viewer?->phase === SessionPhase::SPAWNED
            && $this->runtimePollKeyForSession($viewer) === $worldId
            && ($viewer->play?->hasSentChunkAt($position->x, $position->z) ?? false);
    }

    /**
     * @return list<EntityActorSpawned|EntityActorMoved|EntityActorDamaged|EntityActorDied|EntityActorRemoved>
     */
    private function reconcileEntityActorEvent(
        EntityActorSpawned|EntityActorMoved|EntityActorDamaged|EntityActorDied|EntityActorRemoved $event,
    ): array {
        $worldId = $this->processingWorldId ?? 'world';
        $entity = $event->entity;
        $runtimeId = $entity->getRuntimeId();
        if ($event instanceof EntityActorRemoved) {
            $viewers = array_keys($this->entityActorViewers[$worldId][$runtimeId] ?? []);
            unset(
                $this->entityActors[$worldId][$runtimeId],
                $this->entityActorNoAi[$worldId][$runtimeId],
                $this->entityActorViewers[$worldId][$runtimeId],
            );

            return $viewers === [] ? [] : [new EntityActorRemoved($entity, $viewers)];
        }

        if (!$entity instanceof AbstractLivingEntity) {
            return [];
        }
        $this->entityActors[$worldId][$runtimeId] = $entity;
        if ($event instanceof EntityActorSpawned) {
            $this->entityActorNoAi[$worldId][$runtimeId] = $event->noAi;
        }
        $previous = $this->entityActorViewers[$worldId][$runtimeId] ?? [];
        foreach (array_keys($previous) as $recipient) {
            if (!$this->entityActorViewerCanSee($recipient, $entity)) {
                unset($previous[$recipient]);
            }
        }
        $eligible = $previous;
        foreach (array_values(array_unique($event->recipientSessionIds)) as $recipient) {
            if ($this->entityActorViewerCanSee($recipient, $entity)) {
                $eligible[$recipient] = true;
            } else {
                unset($eligible[$recipient]);
            }
        }
        $old = $this->entityActorViewers[$worldId][$runtimeId] ?? [];
        $this->entityActorViewers[$worldId][$runtimeId] = $eligible;
        $appeared = array_keys(array_diff_key($eligible, $old));
        $disappeared = array_keys(array_diff_key($old, $eligible));
        $events = [];
        if ($disappeared !== []) {
            $events[] = new EntityActorRemoved($entity, $disappeared);
        }
        if ($appeared !== []) {
            $events[] = new EntityActorSpawned(
                $entity,
                $appeared,
                $this->entityActorNoAi[$worldId][$runtimeId] ?? false,
            );
        }
        $continuing = array_keys(array_intersect_key($eligible, $old));
        if ($continuing === []) {
            return $events;
        }
        if ($event instanceof EntityActorMoved) {
            $events[] = new EntityActorMoved($entity, $event->tick, $continuing, $event->motionChanged);
        } elseif ($event instanceof EntityActorDamaged) {
            $events[] = new EntityActorDamaged($entity, $event->tick, $continuing);
        } elseif ($event instanceof EntityActorDied) {
            $events[] = new EntityActorDied($entity, $continuing);
        }

        return $events;
    }

    /**
     * @param null|array<string, true> $chunkKeys
     * @return list<EntityActorSpawned|EntityActorRemoved>
     */
    private function reconcileEntityActorsForViewer(string $sessionId, ?array $chunkKeys = null): array
    {
        $events = [];
        $worldId = $this->processingWorldId ?? 'world';
        foreach ($this->entityActors[$worldId] ?? [] as $entity) {
            if ($chunkKeys !== null && !isset($chunkKeys[self::positionChunkKey(
                $entity->internalPosition()->x,
                $entity->internalPosition()->z,
            )])) {
                continue;
            }
            foreach ($this->reconcileEntityActorEvent(new EntityActorSpawned(
                $entity,
                [$sessionId],
                $this->entityActorNoAi[$worldId][$entity->getRuntimeId()] ?? false,
            )) as $event) {
                if ($event instanceof EntityActorSpawned || $event instanceof EntityActorRemoved) {
                    $events[] = $event;
                }
            }
        }

        return $events;
    }

    private function entityActorViewerCanSee(string $sessionId, AbstractLivingEntity $entity): bool
    {
        $viewer = $this->sessionById($sessionId);

        return $viewer?->phase === SessionPhase::SPAWNED
            && $this->runtimePollKeyForSession($viewer) === ($this->processingWorldId ?? 'world')
            && ($viewer->play?->hasSentChunkAt(
                $entity->internalPosition()->x,
                $entity->internalPosition()->z,
            ) ?? false);
    }

    private static function positionChunkKey(float $x, float $z): string
    {
        return (int) floor($x / 16.0) . ':' . (int) floor($z / 16.0);
    }

    private function filterBlockRecipients(
        BlockBreakStarted|BlockPunch|BlockBreakStopped|BlockChanged|BlockPlaced $event,
    ): BlockBreakStarted|BlockPunch|BlockBreakStopped|BlockChanged|BlockPlaced {
        $recipients = array_values(array_filter(
            array_values(array_unique($event->recipientSessionIds)),
            fn(string $recipient): bool => $this->sessionById($recipient)?->play?->hasSentChunkAt(
                $event->position->x,
                $event->position->z,
            ) ?? false,
        ));

        return match (true) {
            $event instanceof BlockBreakStarted => new BlockBreakStarted(
                $event->ownerSessionId,
                $event->position,
                $event->breakRate,
                $recipients,
                $event->previousPosition,
            ),
            $event instanceof BlockPunch => new BlockPunch(
                $event->ownerSessionId,
                $event->position,
                $event->state,
                $event->face,
                $recipients,
            ),
            $event instanceof BlockBreakStopped => new BlockBreakStopped(
                $event->ownerSessionId,
                $event->position,
                $recipients,
            ),
            $event instanceof BlockPlaced => new BlockPlaced(
                $event->ownerSessionId,
                $event->runtimeActorId,
                $event->position,
                $event->state,
                $event->inventorySlot,
                $event->remainingStack,
                $recipients,
                $event->stoppedBreakingPosition,
            ),
            default => new BlockChanged(
                $event->ownerSessionId,
                $event->position,
                $event->state,
                $recipients,
                $event->stopBreaking,
                $event->destroyedState,
            ),
        };
    }

    private function beginPortalTransfer(PortalTransferRequested $event): bool
    {
        $session = $this->sessionById($event->sessionId);
        $source = $session === null ? null : $this->runtimeForSession($session);
        if ($session?->play === null || $source === null || $this->worldRuntimes === null
            || $session->dimension !== $event->destination->sourceDimension
            || isset($this->pendingPlayerWorldTransfers[$event->sessionId])) {
            return false;
        }
        $target = $this->worldRuntimes->get(
            $source->handle->id(),
            $event->destination->targetDimension,
        );
        $identity = $session->bootstrap?->identity->uuid;
        $player = $identity === null ? null : $source->simulation->authoritativePlayer($identity);
        if ($target === null || $player === null) {
            return false;
        }
        if (!$target->simulation->preparePortalDestination($event->destination)) {
            return false;
        }
        $destination = $target->simulation->resolvePortalDestination($event->destination);
        if ($destination === null) {
            return false;
        }
        $from = new ApiPosition(
            $player->movement->position->x,
            $player->movement->position->y,
            $player->movement->position->z,
            $player->movement->yaw,
            $player->movement->pitch,
            $source->handle,
            $session->dimension,
        );
        $requested = new ApiPosition(
            $destination->x,
            $destination->y,
            $destination->z,
            $player->movement->yaw,
            $player->movement->pitch,
            $target->handle,
            $event->destination->targetDimension,
        );
        if (!$this->teleportPlayer($identity, $requested)) {
            return false;
        }
        $transfer = $this->pendingPlayerWorldTransfers[$event->sessionId] ?? null;
        if ($transfer === null) {
            return false;
        }
        $this->pendingPortalTravelNotifications[$event->sessionId] = [
            'type' => PortalType::NETHER,
            'from' => $from,
            'destination' => new ApiPosition(
                $transfer->decision->destination->x,
                $transfer->decision->destination->y,
                $transfer->decision->destination->z,
                $transfer->decision->yaw,
                $transfer->decision->pitch,
                $target->handle,
                $event->destination->targetDimension,
            ),
        ];

        return true;
    }

    private function beginEndPortalTransfer(EndPortalTransferRequested $event): bool
    {
        $session = $this->sessionById($event->sessionId);
        $source = $session === null ? null : $this->runtimeForSession($session);
        if ($session?->play === null || $source === null || $this->worldRuntimes === null
            || $session->dimension !== $event->sourceDimension
            || isset($this->pendingPlayerWorldTransfers[$event->sessionId])) {
            return false;
        }
        $target = $this->worldRuntimes->get($source->handle->id(), $event->targetDimension);
        $identity = $session->bootstrap?->identity->uuid;
        $player = $identity === null ? null : $source->simulation->authoritativePlayer($identity);
        if ($target === null || $player === null) {
            return false;
        }
        $desired = $event->targetDimension === WorldDimension::OVERWORLD
            ? $player->spawnPoint() ?? $target->simulation->spawnPosition()
            : $target->simulation->spawnPosition();
        $authorized = $source->simulation->authorizePortalTravel(
            $player,
            PortalType::END,
            $event->targetDimension,
            $desired,
        );
        if ($authorized === null) {
            return false;
        }
        $destination = new Position($authorized->x, $authorized->y, $authorized->z);
        if (!$target->simulation->preparePortalPosition($destination)) {
            return false;
        }
        $from = new ApiPosition(
            $player->movement->position->x,
            $player->movement->position->y,
            $player->movement->position->z,
            $player->movement->yaw,
            $player->movement->pitch,
            $source->handle,
            $session->dimension,
        );
        $requested = new ApiPosition(
            $destination->x,
            $destination->y,
            $destination->z,
            $authorized->yaw ?? $player->movement->yaw,
            $authorized->pitch ?? $player->movement->pitch,
            $target->handle,
            $event->targetDimension,
        );
        if (!$this->teleportPlayer($identity, $requested)) {
            return false;
        }
        $transfer = $this->pendingPlayerWorldTransfers[$event->sessionId] ?? null;
        if ($transfer === null) {
            return false;
        }
        $this->pendingPortalTravelNotifications[$event->sessionId] = [
            'type' => PortalType::END,
            'from' => $from,
            'destination' => new ApiPosition(
                $transfer->decision->destination->x,
                $transfer->decision->destination->y,
                $transfer->decision->destination->z,
                $transfer->decision->yaw,
                $transfer->decision->pitch,
                $target->handle,
                $event->targetDimension,
            ),
        ];

        return true;
    }

    private function dispatchWorldEvent(WorldEvent $event, int &$directedCount): bool
    {
        try {
            $directedPackets = $this->eventEncoder->encode($event, $this->sessionEndpointsById());
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_encoding_failed',
                ['exception' => $exception::class],
            );
        }
        if ($this->processingWorldId !== null) {
            $directedPackets = array_values(array_filter(
                $directedPackets,
                fn(DirectedPacket $directed): bool =>
                    ($session = $this->sessionById($directed->sessionId)) !== null
                    && $this->runtimePollKeyForSession($session) === $this->processingWorldId,
            ));
        }
        if (count($directedPackets) > $this->limits->maximumDirectedPacketsPerPoll - $directedCount) {
            return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                'packet_count' => count($directedPackets),
                'remaining_budget' => $this->limits->maximumDirectedPacketsPerPoll - $directedCount,
            ]);
        }
        $directedCount += count($directedPackets);
        /** @var array<int, array{packet: Packet, recipients: list<string>}> $groups */
        $groups = [];
        foreach ($directedPackets as $directed) {
            $packetId = spl_object_id($directed->packet);
            $groups[$packetId] ??= ['packet' => $directed->packet, 'recipients' => []];
            $groups[$packetId]['recipients'][] = $directed->sessionId;
        }
        foreach ($groups as $group) {
            $packet = $group['packet'];
            $recipients = $group['recipients'];
            $frame = null;
            $clearEnvelope = null;
            if (count($recipients) > 1) {
                try {
                    $frame = new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet),
                    );
                    $clearEnvelope = BedrockBatchCodec::encode(
                        new BedrockBatch(
                            [$frame],
                            CompressionMode::NegotiatedZlib,
                            NetworkCompressionPolicy::THRESHOLD_BYTES,
                        ),
                        new BatchLimits(maximumPackets: $this->limits->maximumPacketsPerPayload),
                    );
                } catch (Throwable $exception) {
                    return $this->containEventFailure(
                        $event,
                        'runtime.shared_event_projection_failed',
                        ['exception' => $exception::class],
                    );
                }
            }
            foreach ($recipients as $recipient) {
                $session = $this->sessionById($recipient);
                $queued = false;
                if ($session?->play !== null) {
                    if ($frame === null || $this->hasDeferredWorldPackets($session->id)) {
                        $queued = $this->queueWorldPacket($session, $packet);
                    } else {
                        $queued = $session->play->queuePreparedAuthoritativePacketFrames(
                            [$frame],
                            $clearEnvelope,
                            [$packet],
                        );
                        if (!$queued && !$session->play->isClosed()) {
                            $queued = $this->deferWorldPacket($session->id, $packet);
                        }
                    }
                }
                if (!$queued && $session !== null) {
                    $this->diagnostics->record('runtime.world_packet_backlog_exhausted.protocol_trace', [
                        'event_class' => $event::class,
                        'session_id' => $session->id,
                        'deferred_packets' => isset($this->deferredWorldPackets[$session->id])
                            ? $this->deferredWorldPackets[$session->id]->count()
                            : 0,
                    ]);
                    $this->disconnect(self::endpointKey($session->transport), 'world_packet_backlog_exhausted');
                }
            }
        }

        return true;
    }

    /** Gives a healthy session one delivery opportunity before treating bounded output rejection as fatal. */
    private function queueWorldPacket(RuntimeSession $session, Packet $packet): bool
    {
        $play = $session->play;
        if ($play === null) {
            return false;
        }
        if ($this->hasDeferredWorldPackets($session->id)) {
            return $this->deferWorldPacket($session->id, $packet);
        }
        if ($play->queuePacket($packet)) {
            return true;
        }
        if ($play->isClosed()) {
            return false;
        }
        $key = self::endpointKey($session->transport);
        $this->flush($key, $session);

        if (($this->sessions[$key] ?? null) !== $session || $session->play === null) {
            return false;
        }
        if ($session->play->queuePacket($packet)) {
            return true;
        }
        if ($session->play->isClosed()) {
            return false;
        }

        return $this->deferWorldPacket($session->id, $packet);
    }

    private function hasDeferredWorldPackets(string $sessionId): bool
    {
        return isset($this->deferredWorldPackets[$sessionId])
            && !$this->deferredWorldPackets[$sessionId]->isEmpty();
    }

    private function deferWorldPacket(string $sessionId, Packet $packet): bool
    {
        $queue = $this->deferredWorldPackets[$sessionId] ?? null;
        if ($queue === null) {
            $queue = new DeferredWorldPacketQueue();
            $queue->enqueue($packet);
            $this->deferredWorldPackets[$sessionId] = $queue;

            return true;
        }
        if ($queue->count() >= $this->limits->maximumPacketsPerPayload * 8) {
            return false;
        }
        $queue->enqueue($packet);

        return true;
    }

    /** Drains authoritative world updates fairly before admitting replaceable streaming work. */
    private function drainDeferredWorldPackets(): void
    {
        $sessionIds = array_keys($this->deferredWorldPackets);
        $sessionIds = self::rotateStreamingKeys($sessionIds, $this->deferredWorldPacketCursor);
        $remaining = self::DEFERRED_WORLD_PACKETS_PER_TICK;
        foreach ($sessionIds as $sessionId) {
            if ($remaining < 1) {
                break;
            }
            $queue = $this->deferredWorldPackets[$sessionId] ?? null;
            $session = $this->sessionById($sessionId);
            if (!$queue instanceof SplQueue || $session?->play === null) {
                unset($this->deferredWorldPackets[$sessionId]);
                continue;
            }
            $batchSize = min(
                $remaining,
                self::DEFERRED_WORLD_PACKETS_PER_SESSION_PER_TICK,
                $this->limits->maximumPacketsPerPayload,
                $queue->count(),
            );
            if ($batchSize > 0) {
                $packets = [];
                for ($index = 0; $index < $batchSize; ++$index) {
                    /** @var Packet $packet */
                    $packet = $queue->offsetGet($index);
                    $packets[] = $packet;
                }
                if ($session->play->queuePackets($packets)) {
                    for ($index = 0; $index < $batchSize; ++$index) {
                        $queue->dequeue();
                    }
                    $remaining -= $batchSize;
                }
            }
            if ($queue->isEmpty()) {
                unset($this->deferredWorldPackets[$sessionId]);
            }
        }
    }

    private function recordMovementCorrectionTrace(MovementCorrected $event): void
    {
        $now = hrtime(true);
        if ($this->movementCorrectionTraceStartedNanoseconds === 0) {
            $this->movementCorrectionTraceStartedNanoseconds = $now;
        }
        $this->movementCorrectionTraceCounts[$event->reason]
            = ($this->movementCorrectionTraceCounts[$event->reason] ?? 0) + 1;
        if ($now - $this->movementCorrectionTraceStartedNanoseconds < 1_000_000_000) {
            return;
        }
        foreach ($this->movementCorrectionTraceCounts as $reason => $count) {
            $this->diagnostics->record('world.movement_correction_summary.protocol_trace', [
                'reason' => $reason,
                'corrections' => $count,
            ]);
        }
        $this->movementCorrectionTraceCounts = [];
        $this->movementCorrectionTraceStartedNanoseconds = $now;
    }

    /**
     * Movement is a transient end-of-tick projection. Only actor lifecycle or
     * motion boundaries require it to be delivered before the following event.
     */
    private function isMovementFlushBoundary(WorldEvent $event): bool
    {
        return $event instanceof PlayerDisconnected
            || $event instanceof PlayerBecameHidden
            || $event instanceof PlayerDied
            || $event instanceof PlayerRespawned
            || $event instanceof PlayerKnockedBack
            || $event instanceof PlayerMotionChanged
            || $event instanceof EntityActorDied
            || $event instanceof EntityActorRemoved;
    }

    private function shouldBroadcastPeerMovement(PlayerMoved $event, int $tick): bool
    {
        $previous = $this->lastPeerMovementBroadcastTicks[$event->player->sessionId] ?? null;
        $sessionCount = count($this->sessions);
        $interval = match (true) {
            $sessionCount > 80 => 10,
            $sessionCount > 64 => 8,
            $sessionCount > 32 => 3,
            default => self::PEER_MOVEMENT_BROADCAST_INTERVAL_TICKS,
        };
        $phaseSeed = $this->peerMovementBroadcastPhaseSeeds[$event->player->sessionId]
            ??= (int) hexdec(substr(hash('sha256', $event->player->sessionId), 0, 8));
        // Use every tick in the cadence as a phase. Collapsing large populations into
        // half as many phases preserves average bandwidth but doubles peak fan-out.
        $phase = $phaseSeed % $interval;
        if ($tick % $interval !== $phase) {
            return false;
        }
        if ($previous !== null && ($previous === $tick || $tick - $previous < $interval)) {
            return false;
        }
        $this->lastPeerMovementBroadcastTicks[$event->player->sessionId] = $tick;

        return true;
    }

    private function shouldBroadcastEntityMovement(EntityActorMoved $event, int $tick): bool
    {
        $sessionCount = count($this->sessions);
        $interval = match (true) {
            $sessionCount > 80 => 4,
            $sessionCount > 64 => 3,
            $sessionCount > 32 => 2,
            default => 1,
        };
        $runtimeId = $event->entity->getRuntimeId();
        if ($interval > 1 && $tick % $interval !== $runtimeId % $interval) {
            return false;
        }
        $previous = $this->lastEntityMovementBroadcastTicks[$runtimeId] ?? null;
        if ($previous !== null && ($previous === $tick || $tick - $previous < $interval)) {
            return false;
        }
        $this->lastEntityMovementBroadcastTicks[$runtimeId] = $tick;

        return true;
    }

    private function recordMovementProjectionTrace(int $packets, int $batches, int $recipients): void
    {
        $now = hrtime(true);
        if ($this->movementProjectionTraceStartedNanoseconds === 0) {
            $this->movementProjectionTraceStartedNanoseconds = $now;
        }
        $this->movementProjectionTracePackets += $packets;
        $this->movementProjectionTraceBatches += $batches;
        $this->movementProjectionTraceRecipientDeliveries += $recipients;
        if ($now - $this->movementProjectionTraceStartedNanoseconds < 1_000_000_000) {
            return;
        }
        $this->diagnostics->record('world.movement_projection_summary.protocol_trace', [
            'packets' => $this->movementProjectionTracePackets,
            'batches' => $this->movementProjectionTraceBatches,
            'recipient_deliveries' => $this->movementProjectionTraceRecipientDeliveries,
            'dropped_packets' => $this->movementProjectionTraceDroppedPackets,
            'dropped_batches' => $this->movementProjectionTraceDroppedBatches,
            'suppressed_recipients' => $this->movementProjectionTraceSuppressedRecipients,
        ]);
        $this->movementProjectionTracePackets = 0;
        $this->movementProjectionTraceBatches = 0;
        $this->movementProjectionTraceRecipientDeliveries = 0;
        $this->movementProjectionTraceDroppedPackets = 0;
        $this->movementProjectionTraceDroppedBatches = 0;
        $this->movementProjectionTraceSuppressedRecipients = 0;
        $this->movementProjectionTraceStartedNanoseconds = $now;
    }

    private function recordDroppedMovementBatch(int $packets): void
    {
        $this->movementProjectionTraceDroppedPackets += $packets;
        ++$this->movementProjectionTraceDroppedBatches;
    }

    /**
     * @param array<string, list<PacketFrame>> $packetsBySession
     * @param SplObjectStorage<Packet, PacketFrame> $frameCache
     * @param array<string, bool> $recipientAvailability
     */
    private function collectMovementPackets(
        WorldEvent $event,
        array &$packetsBySession,
        SplObjectStorage $frameCache,
        array &$recipientAvailability,
        int &$directedCount,
    ): bool {
        if ($event instanceof PlayerMoved && $this->eventEncoder instanceof PlayerMovementPacketEncoder) {
            return $this->collectSharedPlayerMovementPackets(
                $event,
                $packetsBySession,
                $frameCache,
                $recipientAvailability,
                $directedCount,
            );
        }
        if ($event instanceof EntityActorMoved && $this->eventEncoder instanceof EntityMovementPacketEncoder) {
            return $this->collectSharedEntityMovementPackets(
                $event,
                $packetsBySession,
                $frameCache,
                $recipientAvailability,
                $directedCount,
            );
        }
        if ($event instanceof EntityActorMoved) {
            $eligibleRecipients = $this->transientProjectionRecipients(
                $event->recipientSessionIds,
                $recipientAvailability,
            );
            $this->movementProjectionTraceSuppressedRecipients += count($event->recipientSessionIds)
                - count($eligibleRecipients);
            if ($eligibleRecipients === []) {
                return true;
            }
            $event = new EntityActorMoved(
                $event->entity,
                $event->tick,
                $eligibleRecipients,
                $event->motionChanged,
            );
        }
        try {
            $directedPackets = $this->eventEncoder->encode($event, $this->sessionEndpointsById());
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_encoding_failed',
                ['exception' => $exception::class],
            );
        }
        if (count($directedPackets) > $this->limits->maximumDirectedPacketsPerPoll - $directedCount) {
            return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                'packet_count' => count($directedPackets),
                'remaining_budget' => $this->limits->maximumDirectedPacketsPerPoll - $directedCount,
            ]);
        }
        $directedCount += count($directedPackets);
        try {
            foreach ($directedPackets as $directed) {
                $packet = $directed->packet;
                if (!$frameCache->contains($packet)) {
                    $frameCache[$packet] = new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet),
                    );
                }
                $packetsBySession[$directed->sessionId][] = $frameCache[$packet];
            }
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_projection_failed',
                ['exception' => $exception::class],
            );
        }

        return true;
    }

    /**
     * @param array<string, list<array{frame: PacketFrame, packet: Packet}>> $packetsBySession
     * @param SplObjectStorage<Packet, PacketFrame> $frameCache
     */
    private function collectSharedChatPacket(
        ChatBroadcast $event,
        array &$packetsBySession,
        SplObjectStorage $frameCache,
        int &$directedCount,
    ): bool {
        try {
            $encoder = $this->eventEncoder;
            if (!$encoder instanceof ChatBroadcastPacketEncoder) {
                return false;
            }
            $recipientCount = count($event->recipientSessionIds);
            $remainingBudget = $this->limits->maximumDirectedPacketsPerPoll - $directedCount;
            if ($recipientCount > $remainingBudget) {
                return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                    'packet_count' => $recipientCount,
                    'remaining_budget' => $remainingBudget,
                ]);
            }
            $packet = $encoder->chatPacket($event);
            if (!$frameCache->contains($packet)) {
                $frameCache[$packet] = new PacketFrame(
                    new PacketHeader(BedrockPacketCodec::packetId($packet)),
                    BedrockPacketCodec::encode($packet),
                );
            }
            $frame = $frameCache[$packet];
            foreach ($event->recipientSessionIds as $recipient) {
                $packetsBySession[$recipient][] = ['frame' => $frame, 'packet' => $packet];
            }
            $directedCount += $recipientCount;
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_projection_failed',
                ['exception' => $exception::class],
            );
        }

        return true;
    }

    /**
     * @param array<string, list<PacketFrame>> $packetsBySession
     * @param SplObjectStorage<Packet, PacketFrame> $frameCache
     * @param array<string, bool> $recipientAvailability
     */
    private function collectSharedEntityMovementPackets(
        EntityActorMoved $event,
        array &$packetsBySession,
        SplObjectStorage $frameCache,
        array &$recipientAvailability,
        int &$directedCount,
    ): bool {
        try {
            $encoder = $this->eventEncoder;
            if (!$encoder instanceof EntityMovementPacketEncoder) {
                return false;
            }
            $eligibleRecipients = $this->transientProjectionRecipients(
                $event->recipientSessionIds,
                $recipientAvailability,
            );
            $this->movementProjectionTraceSuppressedRecipients += count($event->recipientSessionIds)
                - count($eligibleRecipients);
            if ($eligibleRecipients === []) {
                return true;
            }

            $packets = $encoder->entityMovementPackets($event);
            $projectionCount = count($eligibleRecipients) * count($packets);
            $remainingBudget = $this->limits->maximumDirectedPacketsPerPoll - $directedCount;
            if ($projectionCount > $remainingBudget) {
                return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                    'packet_count' => $projectionCount,
                    'remaining_budget' => $remainingBudget,
                ]);
            }

            $frames = [];
            foreach ($packets as $packet) {
                if (!$frameCache->contains($packet)) {
                    $frameCache[$packet] = new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet),
                    );
                }
                $frames[] = $frameCache[$packet];
            }
            foreach ($eligibleRecipients as $recipient) {
                foreach ($frames as $frame) {
                    $packetsBySession[$recipient][] = $frame;
                }
            }
            $directedCount += $projectionCount;
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_projection_failed',
                ['exception' => $exception::class],
            );
        }

        return true;
    }

    /**
     * Keeps only the newest correction for a player. Corrections are authoritative, but older
     * positions become obsolete as soon as a newer server position is available.
     *
     * @param array<string, list<PacketFrame>> $packetsBySession
     * @param SplObjectStorage<Packet, PacketFrame> $frameCache
     */
    private function collectAuthoritativeMovementPacket(
        MovementCorrected $event,
        array &$packetsBySession,
        SplObjectStorage $frameCache,
        int &$directedCount,
    ): bool {
        try {
            $directedPackets = $this->eventEncoder->encode($event, $this->sessionEndpointsById());
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_encoding_failed',
                ['exception' => $exception::class],
            );
        }
        if (count($directedPackets) > $this->limits->maximumDirectedPacketsPerPoll - $directedCount) {
            return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                'packet_count' => count($directedPackets),
                'remaining_budget' => $this->limits->maximumDirectedPacketsPerPoll - $directedCount,
            ]);
        }
        $directedCount += count($directedPackets);
        try {
            foreach ($directedPackets as $directed) {
                $packet = $directed->packet;
                if (!$frameCache->contains($packet)) {
                    $frameCache[$packet] = new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet),
                    );
                }
                $packetsBySession[$directed->sessionId] = [$frameCache[$packet]];
            }
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_projection_failed',
                ['exception' => $exception::class],
            );
        }

        return true;
    }

    /**
     * @param array<string, list<PacketFrame>> $packetsBySession
     * @param SplObjectStorage<Packet, PacketFrame> $frameCache
     * @param array<string, bool> $recipientAvailability
     */
    private function collectSharedPlayerMovementPackets(
        PlayerMoved $event,
        array &$packetsBySession,
        SplObjectStorage $frameCache,
        array &$recipientAvailability,
        int &$directedCount,
    ): bool {
        try {
            $encoder = $this->eventEncoder;
            if (!$encoder instanceof PlayerMovementPacketEncoder) {
                return false;
            }
            $eligibleRecipients = $this->transientProjectionRecipients(
                $event->recipientSessionIds,
                $recipientAvailability,
            );
            $this->movementProjectionTraceSuppressedRecipients += count($event->recipientSessionIds)
                - count($eligibleRecipients);
            if ($eligibleRecipients === []) {
                return true;
            }

            $packets = $encoder->playerMovementPackets($event);
            $projectionCount = count($eligibleRecipients) * count($packets);
            $remainingBudget = $this->limits->maximumDirectedPacketsPerPoll - $directedCount;
            if ($projectionCount > $remainingBudget) {
                return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                    'packet_count' => $projectionCount,
                    'remaining_budget' => $remainingBudget,
                ]);
            }

            $frames = [];
            foreach ($packets as $packet) {
                if (!$frameCache->contains($packet)) {
                    $frameCache[$packet] = new PacketFrame(
                        new PacketHeader(BedrockPacketCodec::packetId($packet)),
                        BedrockPacketCodec::encode($packet),
                    );
                }
                $frames[] = $frameCache[$packet];
            }
            foreach ($eligibleRecipients as $recipient) {
                foreach ($frames as $frame) {
                    $packetsBySession[$recipient][] = $frame;
                }
            }
            $directedCount += $projectionCount;
        } catch (Throwable $exception) {
            return $this->containEventFailure(
                $event,
                'runtime.event_projection_failed',
                ['exception' => $exception::class],
            );
        }

        return true;
    }

    /**
     * Availability is stable until the pending movement window is flushed.
     * Ordering-sensitive event boundaries flush and clear this cache before
     * any later transient projection is collected.
     *
     * @param list<string> $recipients
     * @param array<string, bool> $recipientAvailability
     * @return list<string>
     */
    private function transientProjectionRecipients(array $recipients, array &$recipientAvailability): array
    {
        $eligible = [];
        foreach ($recipients as $recipient) {
            $cached = $recipientAvailability[$recipient] ?? null;
            if ($cached === false) {
                continue;
            }
            if ($cached === true) {
                $eligible[] = $recipient;
                continue;
            }
            $session = $this->sessionById($recipient);
            $available = $session !== null
                && !$this->hasDeferredWorldPackets($session->id)
                && ($session->play?->prepareTransientProjection() ?? false);
            $recipientAvailability[$recipient] = $available;
            if ($available) {
                $eligible[] = $recipient;
            }
        }

        return $eligible;
    }

    /**
     * @param array<string, list<PacketFrame>> $packetsBySession
     * @param array<string, bool> $recipientAvailability
     * @param array<string, list<PacketFrame>> $authoritativePacketsBySession
     * @param array<string, list<array{frame: PacketFrame, packet: Packet}>> $sharedAuthoritativePacketsBySession
     */
    private function flushMovementPackets(
        array &$packetsBySession,
        array &$recipientAvailability,
        array &$authoritativePacketsBySession,
        array &$sharedAuthoritativePacketsBySession,
    ): bool {
        if ($packetsBySession === [] && $authoritativePacketsBySession === []
            && $sharedAuthoritativePacketsBySession === []) {
            $recipientAvailability = [];
            return true;
        }
        $packetCount = 0;
        $batchCount = 0;
        $recipientCount = count(array_unique([
            ...array_keys($packetsBySession),
            ...array_keys($authoritativePacketsBySession),
        ]));
        foreach ($authoritativePacketsBySession as $sessionId => $packets) {
            $packetCount += count($packets);
            $session = $this->sessionById($sessionId);
            $play = $session?->play;
            if ($play === null) {
                continue;
            }
            foreach (array_chunk($packets, max(1, $this->limits->maximumPacketsPerPayload)) as $batch) {
                ++$batchCount;
                if ($play->queuePacketFrames($batch)) {
                    continue;
                }
                if ($play->isClosed()) {
                    $this->disconnect(self::endpointKey($session->transport), 'authoritative_projection_closed');
                    break;
                }
                $key = self::endpointKey($session->transport);
                $this->flush($key, $session);
                if (($this->sessions[$key] ?? null) === $session
                    && $session->play !== null
                    && $session->play->queuePacketFrames($batch)) {
                    continue;
                }
                $this->deferredAuthoritativeMovementFrames[$sessionId] = $batch;
            }
        }
        /** @var array<string, array{frames: list<PacketFrame>, sessions: list<string>, authoritative: list<Packet>}> $projectionGroups */
        $projectionGroups = [];
        $projectionSessionIds = array_values(array_unique([
            ...array_keys($packetsBySession),
            ...array_keys($sharedAuthoritativePacketsBySession),
        ]));
        foreach ($projectionSessionIds as $sessionId) {
            $sharedAuthoritative = $sharedAuthoritativePacketsBySession[$sessionId] ?? [];
            $authoritativeFrames = array_map(
                static fn(array $entry): PacketFrame => $entry['frame'],
                $sharedAuthoritative,
            );
            $authoritativePackets = array_map(
                static fn(array $entry): Packet => $entry['packet'],
                $sharedAuthoritative,
            );
            $movementPackets = $packetsBySession[$sessionId] ?? [];
            $packetCount += count($movementPackets);
            $packets = [...$authoritativeFrames, ...$movementPackets];
            $session = $this->sessionById($sessionId);
            if ($session?->play === null) {
                continue;
            }
            $maximumBatch = max(1, $this->limits->maximumPacketsPerPayload);
            $remainingAuthoritative = count($authoritativePackets);
            $authoritativeOffset = 0;
            foreach (array_chunk($packets, $maximumBatch) as $batch) {
                $authoritativeCount = min(count($batch), $remainingAuthoritative);
                $batchAuthoritative = $authoritativeCount > 0
                    ? array_slice($authoritativePackets, $authoritativeOffset, $authoritativeCount)
                    : [];
                $authoritativeOffset += $authoritativeCount;
                $remainingAuthoritative -= $authoritativeCount;
                if (count($batch) > $authoritativeCount) {
                    ++$batchCount;
                }
                $identity = '';
                foreach ($batch as $frame) {
                    $identity .= spl_object_id($frame) . ':';
                }
                if (!isset($projectionGroups[$identity])) {
                    $projectionGroups[$identity] = [
                        'frames' => $batch,
                        'sessions' => [],
                        'authoritative' => $batchAuthoritative,
                    ];
                }
                $projectionGroups[$identity]['sessions'][] = $sessionId;
            }
        }
        foreach ($projectionGroups as $group) {
            $batch = $group['frames'];
            $sessionIds = $group['sessions'];
            $authoritativePackets = $group['authoritative'];
            $clearEnvelope = null;
            if (count($sessionIds) > 1) {
                try {
                    $clearEnvelope = BedrockBatchCodec::encode(
                        new BedrockBatch(
                            $batch,
                            CompressionMode::NegotiatedZlib,
                            NetworkCompressionPolicy::THRESHOLD_BYTES,
                        ),
                        new BatchLimits(maximumPackets: $this->limits->maximumPacketsPerPayload),
                    );
                } catch (Throwable $exception) {
                    $this->diagnostics->record('runtime.movement_batch_encoding_failed', [
                        'exception' => $exception::class,
                    ]);

                    return $this->failRuntime('movement_batch_encoding_failed', $exception);
                }
            }
            foreach ($sessionIds as $sessionId) {
                $session = $this->sessionById($sessionId);
                if ($session?->play === null) {
                    continue;
                }
                if ($clearEnvelope === null) {
                    $queued = $session->play->queuePacketFrames($batch);
                } elseif ($authoritativePackets !== []) {
                    $queued = $session->play->queuePreparedAuthoritativePacketFrames($batch, $clearEnvelope);
                } else {
                    $queued = $session->play->queuePreparedPacketFrames($batch, $clearEnvelope);
                }
                if (!$queued) {
                    $movementCount = count($batch) - count($authoritativePackets);
                    if ($movementCount > 0) {
                        $this->recordDroppedMovementBatch($movementCount);
                    }
                    if ($session->play->isClosed()) {
                        $this->disconnect(self::endpointKey($session->transport), 'transient_projection_closed');
                        continue;
                    }
                    foreach ($authoritativePackets as $packet) {
                        if (!$this->queueWorldPacket($session, $packet)) {
                            $this->disconnect(self::endpointKey($session->transport), 'world_packet_backlog_exhausted');
                            break;
                        }
                    }
                }
            }
        }
        $packetsBySession = [];
        $recipientAvailability = [];
        $authoritativePacketsBySession = [];
        $sharedAuthoritativePacketsBySession = [];
        $this->recordMovementProjectionTrace($packetCount, $batchCount, $recipientCount);

        return true;
    }

    private function sessionById(string $id): ?RuntimeSession
    {
        return $this->sessionsById[$id] ?? null;
    }

    private function pruneWorldMaintenanceState(): void
    {
        if ($this->worldRuntimes === null) {
            return;
        }
        $loaded = [];
        foreach ($this->worldRuntimes->loadedDimensions() as $runtime) {
            $loaded[self::runtimePollKey(
                $runtime->handle->id(),
                $runtime->opened->world->dimension(),
            )] = true;
        }
        $this->autosaveActive = array_intersect_key($this->autosaveActive, $loaded);
        $this->entityAutosaveActive = array_intersect_key($this->entityAutosaveActive, $loaded);
        $this->playerAutosaveActive = array_intersect_key($this->playerAutosaveActive, $loaded);
    }

    /** @return list<WorldSimulation> */
    private function simulations(): array
    {
        if ($this->worldRuntimes === null) {
            return [$this->world];
        }

        return array_map(
            static fn(ManagedWorldRuntime $runtime): WorldSimulation => $runtime->simulation,
            $this->worldRuntimes->loadedDimensions(),
        );
    }

    private function simulationForSession(RuntimeSession $session): ?WorldSimulation
    {
        if ($this->worldRuntimes === null) {
            return $this->world;
        }
        $runtime = $this->worldRuntimes->get($session->worldId, $session->dimension);

        return $runtime?->simulation;
    }

    private function simulationForIdentity(string $identity): ?WorldSimulation
    {
        foreach ($this->simulations() as $simulation) {
            if ($simulation->pluginPlayer($identity) !== null) {
                return $simulation;
            }
        }

        return null;
    }

    private function apiInventoryStack(ApiItemStack $stack): InventoryStack
    {
        $catalog = $this->itemCatalog
            ?? throw new RuntimeException('The authoritative item catalog is unavailable.');
        $type = $catalog->type($stack->identifier);
        $placed = $type->placedBlockState === null || $this->blockStateRegistry === null
            ? null
            : $this->blockStateRegistry->internalId($type->placedBlockState);

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

    /** @param Closure(): bool $action */
    private function acceptPluginAction(Closure $action): bool
    {
        $execute = static function () use ($action): void {
            if (!$action()) {
                throw new OverflowException('The authoritative player action queue rejected the request.');
            }
        };
        if ($this->pluginActions?->isCapturing() === true) {
            $this->pluginActions->stage($execute);
        } else {
            $execute();
        }

        return true;
    }

    private function runtimeForSession(RuntimeSession $session): ?ManagedWorldRuntime
    {
        return $this->worldRuntimes?->get($session->worldId, $session->dimension);
    }

    private function runtimePollKeyForSession(RuntimeSession $session): string
    {
        return self::runtimePollKey($session->worldId, $session->dimension);
    }

    private static function runtimePollKey(string $worldId, WorldDimension $dimension): string
    {
        return match ($dimension) {
            WorldDimension::OVERWORLD => $worldId,
            WorldDimension::NETHER => $worldId . '@nether',
            WorldDimension::END => $worldId . '@end',
        };
    }

    private static function protocolDimension(WorldDimension $dimension): DimensionId
    {
        return match ($dimension) {
            WorldDimension::OVERWORLD => DimensionId::Overworld,
            WorldDimension::NETHER => DimensionId::Nether,
            WorldDimension::END => DimensionId::End,
        };
    }

    private function updateAuthoritativeChunkView(\Bedriox\Server\Simulation\PlayerSnapshot $player): bool
    {
        $session = $this->sessionById($player->sessionId);
        if ($session?->play === null) {
            return true;
        }
        if ($session->play->updateChunkView($player->position->x, $player->position->y, $player->position->z)) {
            return true;
        }
        $this->disconnect(self::endpointKey($session->transport), 'movement_projection_failed');

        return false;
    }

    /** @return array<string, RuntimeSession> */
    private function sessionEndpointsById(): array
    {
        return $this->sessionsById;
    }

    private static function endpointKey(SessionInfo $info): string
    {
        return self::rawEndpointKey($info->remoteAddress, $info->remotePort);
    }

    private static function rawEndpointKey(string $address, int $port): string
    {
        return $address . ':' . $port;
    }
}

/**
 * @internal
 * @extends SplQueue<Packet>
 */
final class DeferredWorldPacketQueue extends SplQueue {}
