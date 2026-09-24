<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Api\Command\CommandResult;
use Bedriox\Api\Player\GameMode;
use Bedriox\Protocol\Batch\BatchLimits;
use Bedriox\Protocol\Batch\BedrockBatch;
use Bedriox\Protocol\Batch\BedrockBatchCodec;
use Bedriox\Protocol\Batch\CompressionMode;
use Bedriox\Protocol\Packet\BedrockPacketCodec;
use Bedriox\Protocol\Packet\CommandOutputMessage;
use Bedriox\Protocol\Packet\CommandOutputPacket;
use Bedriox\Protocol\Packet\CommandOutputType;
use Bedriox\Protocol\Packet\DisconnectPacket;
use Bedriox\Protocol\Packet\DisconnectReason;
use Bedriox\Protocol\Packet\Packet;
use Bedriox\Protocol\Packet\PacketFrame;
use Bedriox\Protocol\Packet\PacketHeader;
use Bedriox\Protocol\Packet\SystemTextPacket;
use Bedriox\Protocol\Packet\UpdateAdventureSettingsPacket;
use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\Server\Entity\Item\DroppedItemEntity;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\CrashPlayer;
use Bedriox\Server\Observability\Memory\GarbageCollectionReport;
use Bedriox\Server\Observability\Memory\GarbageCollector;
use Bedriox\Server\Observability\Memory\MemoryManagementDecision;
use Bedriox\Server\Observability\Memory\MemoryManager;
use Bedriox\Server\Observability\Memory\MemoryPressure;
use Bedriox\Server\Observability\PerformanceMonitor;
use Bedriox\Server\Observability\PerformanceSubsystem;
use Bedriox\Server\Permission\PermissionStore;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Plugin\Command\CommandRegistry;
use Bedriox\Server\Plugin\Command\ServerPlayerCommandSender;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\BlockPunch;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\ItemEntityDespawned;
use Bedriox\Server\Simulation\Event\ItemEntityMoved;
use Bedriox\Server\Simulation\Event\ItemEntityPickedUp;
use Bedriox\Server\Simulation\Event\ItemEntitySpawned;
use Bedriox\Server\Simulation\Event\MovementCorrected;
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
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\PluginGameplayEventBridge;
use Bedriox\Server\Simulation\SimulationClock;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\SystemSimulationClock;
use Bedriox\Server\Simulation\VerticalState;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Transport\ConnectedTransport;
use Bedriox\Server\Worker\Chunk\PreparedChunkCache;
use Bedriox\Server\Worker\Chunk\PreparedChunkCacheSnapshot;
use Bedriox\Server\World\ChunkUnloadResult;
use Bedriox\Server\World\World;
use Closure;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Bounded single-threaded composition root for transport, protocol, and simulation. */
final class ServerRuntime implements RuntimeDriver, RuntimeFailureSource
{
    private const int MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGES = 256;

    /** @var array<string, RuntimeSession> endpoint key => session */
    private array $sessions = [];

    /** @var array<string, string> runtime session ID => endpoint key */
    private array $sessionEndpoints = [];

    private bool $closed = false;
    private int $nextRuntimeEntityId = 1;
    private readonly SimulationCommandFactory $commands;
    private readonly RuntimeDiagnostics $diagnostics;
    private readonly PlayerActorVisibilityRegistry $actorVisibility;
    private readonly PlayerConnectionDirectory $playerConnections;
    private ?Throwable $failure = null;
    private ?string $involvedSessionId = null;
    private bool $autosaveActive = false;
    private bool $playerAutosaveActive = false;
    private ?MemoryManagementDecision $lastMemoryDecision = null;
    private ?GarbageCollectionReport $lastGarbageCollection = null;
    private ?ChunkUnloadResult $lastChunkUnload = null;
    private int $totalChunksUnloaded = 0;
    private int $totalPreparedBytesTrimmed = 0;
    private int $commandSchemaRevision = 0;

    /** @var array<string, array{SessionInfo, int}> */
    private array $pendingTransportCloses = [];
    private readonly SimulationClock $closeClock;

    /** @var array<int, DroppedItemEntity> */
    private array $itemActors = [];

    /** @var array<int, array<string, true>> */
    private array $itemActorViewers = [];

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
    ) {
        if ($this->autosaveIntervalTicks < 1 || $this->autosaveChunkBudget < 1
            || $this->playerAutosaveIntervalTicks < 1 || $this->playerAutosaveBudget < 1
            || $this->chunkUnloadPerTick < 1 || $this->chunkUnloadPerTick > 1_024) {
            throw new InvalidArgumentException('Autosave interval and chunk budget must be positive.');
        }
        $this->commands = $commands ?? new SimulationCommandFactory();
        $this->diagnostics = $diagnostics ?? RuntimeDiagnostics::disabled();
        $this->actorVisibility = new PlayerActorVisibilityRegistry($this->limits->maximumSessions);
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
        return array_map($this->playerConnections->attach(...), $this->world->pluginPlayers());
    }

    public function changePlayerGameMode(string $uuid, GameMode $gameMode): bool
    {
        return $this->world->enqueueGameMode($uuid, $gameMode);
    }

    public function givePlayerItem(string $uuid, string $identifier, int $amount): bool
    {
        return $this->world->enqueueGiveItem($uuid, $identifier, $amount);
    }

    public function teleportPlayer(
        string $uuid,
        \Bedriox\Api\World\Position $position,
        ?float $yaw = null,
        ?float $pitch = null,
    ): bool {
        return $this->world->enqueueTeleport(
            $uuid,
            new \Bedriox\Server\Simulation\Position($position->x, $position->y, $position->z),
            $yaw,
            $pitch,
        );
    }

    /** Refreshes one connected player's command authority after a persisted permission change. */
    public function refreshPlayerAuthority(string $uuid, bool $includeAbilities): void
    {
        if ($this->commandRegistry === null || $this->permissionStore === null) {
            return;
        }
        $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
        $player = $this->world->pluginPlayer($uuid);
        $gameMode = $player === null ? GameMode::SURVIVAL : $player->getGamemode();
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
                $this->disconnect($key);

                return;
            }
            if (!$session->play->queuePacket($projector->availableCommands($uuid, $this->onlinePlayers()))) {
                $this->disconnect($key);
            }

            return;
        }
    }

    private function queueOnlinePlayerCommandUpdate(): bool
    {
        if ($this->commandRegistry === null || $this->permissionStore === null) {
            return true;
        }
        $packet = (new BedrockCommandPacketProjector(
            $this->commandRegistry,
            $this->permissionStore,
        ))->onlinePlayerUpdate($this->onlinePlayers());
        foreach ($this->sessions as $session) {
            if ($session->joined && $session->play !== null && !$session->play->queuePacket($packet)) {
                return false;
            }
        }

        return true;
    }

    private function queueCommandMetadataUpdates(): bool
    {
        if ($this->commandRegistry === null) {
            return true;
        }
        $updates = $this->commandRegistry->drainSoftEnumUpdates();
        if ($this->permissionStore === null) {
            $this->commandSchemaRevision = $this->commandRegistry->schemaRevision();

            return true;
        }
        $projector = new BedrockCommandPacketProjector($this->commandRegistry, $this->permissionStore);
        $schemaRevision = $this->commandRegistry->schemaRevision();
        $schemaChanged = $schemaRevision !== $this->commandSchemaRevision;
        foreach ($this->sessions as $session) {
            if (!$session->joined || $session->play === null) {
                continue;
            }
            if ($schemaChanged) {
                if (!$session->play->queuePacket($projector->availableCommands(
                    $session->play->login()->identity,
                    $this->onlinePlayers(),
                ))) {
                    return false;
                }
                continue;
            }
            foreach ($updates as $update) {
                if (!$session->play->queuePacket($projector->softEnumUpdate($update->name, $update->values))) {
                    return false;
                }
            }
        }
        $this->commandSchemaRevision = $schemaRevision;

        return true;
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
            $this->persistentWorld?->requestRetainSpawnChunk();
            $transportTiming = $this->performance?->startSubsystem(PerformanceSubsystem::TRANSPORT);
            $this->publishCrashContext();
            $this->transport->poll($this->limits->maximumDatagramsPerPoll);
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
                    $this->diagnostics->record('runtime.transport_session_closed', ['reason' => $event->reason->value]);
                    $this->closeEndpoint($event->session);
                }
            }
            $this->expirePendingTransportCloses();
            foreach ($payloads as $payload) {
                $this->performance?->recordNetworkReceived(strlen($payload->payload));
                $this->involvedSessionId = $this->sessions[self::rawEndpointKey($payload->remoteAddress, $payload->remotePort)]->id ?? null;
                $this->publishCrashContext();
                $this->accept($payload);
                $this->involvedSessionId = null;
            }
            $transportTiming?->end();
            $sessionTiming = $this->performance?->startSubsystem(PerformanceSubsystem::SESSIONS);
            foreach (array_keys($this->sessions) as $key) {
                $session = $this->sessions[$key];
                if ($session->phase === SessionPhase::LOGIN) {
                    try {
                        $alive = $session->login?->tick() ?? false;
                    } catch (Throwable $exception) {
                        $this->diagnostics->record('login.tick_failed', ['exception' => $exception::class]);
                        $alive = false;
                    }
                    if (!$alive) {
                        $this->disconnect($key);
                        continue;
                    }
                    $this->flush($key, $session);
                    $this->promoteIfReady($key, $session);
                } elseif ($session->play !== null) {
                    if (!$session->play->tick()) {
                        $this->disconnect($key);
                    } elseif (!$this->tryQueueAdmission($key, $session)) {
                        continue;
                    }
                }
            }
            $sessionTiming?->end();
            $directedCount = 0;
            $worldTiming = $this->performance?->startSubsystem(PerformanceSubsystem::WORLD);
            $ticks = $this->worldLoop->poll();
            $worldTiming?->end();
            $outboundTiming = $this->performance?->startSubsystem(PerformanceSubsystem::NETWORK_OUTBOUND);
            foreach ($ticks as $tick) {
                ++$completedTicks;
                $pluginTiming = $this->performance?->startSubsystem(PerformanceSubsystem::PLUGINS);
                ($this->simulationTickBoundary)?->__invoke($tick->number);
                $pluginTiming?->end();
                $chunkTiming = $this->performance?->startSubsystem(PerformanceSubsystem::CHUNKS);
                foreach (array_keys($this->sessions) as $key) {
                    $play = $this->sessions[$key]->play;
                    if ($play !== null && !$play->worldTick()) {
                        $this->disconnect($key);
                    }
                }
                $chunkTiming?->end();
                foreach ($this->sessions as $session) {
                    if ($session->play === null || !$session->play->takeChunkVisibilityChanged()) {
                        continue;
                    }
                    foreach ($this->reconcileActorVisibilityForViewer($session->id) as $visibilityEvent) {
                        if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                            return false;
                        }
                    }
                }
                foreach ($tick->events as $event) {
                    if (!$this->reconcileAdmission($event)) {
                        continue;
                    }
                    if ($event instanceof BlockPlacementCorrected) {
                        $this->diagnostics->record('world.protocol_trace', [
                            'kind' => 'block_placement_corrected',
                            'reason' => $event->reason,
                        ]);
                    }
                    if ($event instanceof MovementCorrected) {
                        $this->diagnostics->record('world.protocol_trace', [
                            'kind' => 'movement_corrected',
                            'reason' => $event->reason,
                            'packet' => match (true) {
                                $event->clientTick !== null => 'correct_player_move_prediction',
                                $event->reason === 'plugin_teleport' => 'move_player',
                                default => 'none',
                            },
                            'on_ground' => $event->authoritativePlayer->verticalState === VerticalState::GROUNDED,
                            'client_tick_high' => $event->clientTick?->high,
                            'client_tick_low' => $event->clientTick?->low,
                        ]);
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
                    if ($event instanceof ItemEntitySpawned || $event instanceof ItemEntityMoved
                        || $event instanceof ItemEntityPickedUp || $event instanceof ItemEntityDespawned) {
                        foreach ($this->reconcileItemEvent($event) as $itemEvent) {
                            if (!$this->dispatchWorldEvent($itemEvent, $directedCount)) {
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
                        foreach ($this->reconcileActorVisibility($event->player->sessionId) as $visibilityEvent) {
                            if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                return false;
                            }
                        }
                        if (!$this->queueOnlinePlayerCommandUpdate()) {
                            return false;
                        }
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
                        $this->actorVisibility->upsert($event->player);
                        $session = $this->sessionById($event->player->sessionId);
                        $chunkVisibilityChanged = $session?->play?->takeChunkVisibilityChanged() ?? false;
                        if ($chunkVisibilityChanged) {
                            foreach ($this->reconcileItemsForViewer($event->player->sessionId) as $itemVisibilityEvent) {
                                if (!$this->dispatchWorldEvent($itemVisibilityEvent, $directedCount)) {
                                    return false;
                                }
                            }
                        }
                        $newlyVisibleRecipients = [];
                        foreach ($this->reconcileActorVisibility($event->player->sessionId) as $visibilityEvent) {
                            if ($visibilityEvent instanceof PlayerBecameVisible
                                && $visibilityEvent->player->sessionId === $event->player->sessionId) {
                                $newlyVisibleRecipients[$visibilityEvent->recipientSessionId] = true;
                            }
                            if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                return false;
                            }
                        }
                        $visibleRecipients = array_values(array_intersect(
                            $event->recipientSessionIds,
                            $this->actorVisibility->viewersOf($event->player->sessionId),
                        ));
                        $visibleRecipients = array_values(array_filter(
                            $visibleRecipients,
                            static fn(string $recipient): bool => !isset($newlyVisibleRecipients[$recipient]),
                        ));
                        if (!$this->dispatchWorldEvent(new PlayerMoved(
                            $event->player,
                            $visibleRecipients,
                            $event->postureChanged,
                        ), $directedCount)) {
                            return false;
                        }
                        continue;
                    }
                    if ($event instanceof MovementCorrected && $event->peerSessionIds !== []) {
                        $this->actorVisibility->upsert($event->authoritativePlayer);
                        $session = $this->sessionById($event->authoritativePlayer->sessionId);
                        $session?->play?->takeChunkVisibilityChanged();
                        $newlyVisibleRecipients = [];
                        foreach ($this->reconcileActorVisibility($event->authoritativePlayer->sessionId) as $visibilityEvent) {
                            if ($visibilityEvent instanceof PlayerBecameVisible
                                && $visibilityEvent->player->sessionId === $event->authoritativePlayer->sessionId) {
                                $newlyVisibleRecipients[$visibilityEvent->recipientSessionId] = true;
                            }
                            if (!$this->dispatchWorldEvent($visibilityEvent, $directedCount)) {
                                return false;
                            }
                        }
                        $visibleRecipients = array_values(array_intersect(
                            $event->peerSessionIds,
                            $this->actorVisibility->viewersOf($event->authoritativePlayer->sessionId),
                        ));
                        $visibleRecipients = array_values(array_filter(
                            $visibleRecipients,
                            static fn(string $recipient): bool => !isset($newlyVisibleRecipients[$recipient]),
                        ));
                        if (!$this->dispatchWorldEvent(new MovementCorrected(
                            $event->authoritativePlayer,
                            $event->reason,
                            $visibleRecipients,
                            $event->postureChanged,
                            $event->clientTick,
                        ), $directedCount)) {
                            return false;
                        }
                        continue;
                    }
                    if ($event instanceof PlayerRespawned) {
                        $this->actorVisibility->upsert($event->player);
                        foreach ($this->reconcileActorVisibility($event->player->sessionId) as $visibilityEvent) {
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
                        );
                    }
                    if (!$this->dispatchWorldEvent($event, $directedCount)) {
                        return false;
                    }
                    if ($event instanceof PlayerDisconnected && !$this->queueOnlinePlayerCommandUpdate()) {
                        return false;
                    }
                }
                $persistenceTiming = $this->performance?->startSubsystem(PerformanceSubsystem::PERSISTENCE);
                if ($this->persistentWorld !== null && $tick->number % $this->autosaveIntervalTicks === 0) {
                    $this->autosaveActive = true;
                }
                if ($this->persistentWorld !== null && $this->autosaveActive) {
                    $saved = $this->persistentWorld->autosave($this->autosaveChunkBudget);
                    $remaining = $this->persistentWorld->dirtyChunkCount();
                    $this->autosaveActive = $remaining > 0;
                    $this->diagnostics->record('world.autosaved', [
                        'saved_chunks' => $saved,
                        'remaining_dirty_chunks' => $remaining,
                    ]);
                }
                if ($this->playerPersistence !== null && $tick->number % $this->playerAutosaveIntervalTicks === 0) {
                    $this->playerAutosaveActive = true;
                }
                if ($this->playerPersistence !== null && $this->playerAutosaveActive) {
                    $result = $this->world->autosavePlayers($this->playerAutosaveBudget);
                    $this->playerAutosaveActive = $result['remaining'] > 0;
                    $this->diagnostics->record('players.autosaved', [
                        'saved_players' => $result['saved'],
                        'remaining_dirty_players' => $result['remaining'],
                    ]);
                }
                $this->runMemoryMaintenance($tick->number);
                $persistenceTiming?->end();
            }
            if (!$this->queueCommandMetadataUpdates()) {
                return false;
            }
            foreach (array_keys($this->sessions) as $key) {
                $this->flush($key, $this->sessions[$key]);
            }
            $outboundTiming?->end();

            if ($completedTicks > 0) {
                $completedAt = hrtime(true);
                $this->performance?->completeTicks($completedTicks, $completedAt);
            } else {
                $this->performance?->cancelTick();
            }

            $this->publishCrashContext();

            return !$this->closed;
        } catch (Throwable $exception) {
            return $this->failRuntime('poll_failed', $exception);
        }
    }

    public function sessionCount(): int
    {
        return count($this->sessions);
    }

    public function entityCount(): int
    {
        return count($this->world->snapshot()->players) + count($this->itemActors);
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
        return $this->preparedChunks?->snapshot();
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
        $this->world->beginShutdown();
        foreach (array_keys($this->sessions) as $key) {
            $this->removeRuntimeSession($key);
        }
        $this->pendingTransportCloses = [];
        $this->drainShutdownLifecycle();
        $this->preparedChunks?->close();
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
        if ($this->persistentWorld !== null) {
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
        $session = new RuntimeSession($info, $id, UnsignedLong::fromInt($this->nextRuntimeEntityId++), $login);
        $this->sessions[$key] = $session;
        $this->sessionEndpoints[$id] = $key;
    }

    private function accept(ReceivedPayload $payload): void
    {
        $key = self::rawEndpointKey($payload->remoteAddress, $payload->remotePort);
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession
            || $payload->reliability !== Reliability::ReliableOrdered
            || $payload->orderingChannel !== 0) {
            if ($session instanceof RuntimeSession) {
                $this->disconnect($key);
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
                $this->disconnect($key);

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
                $this->disconnect($key);

                return;
            }
            if (!$this->tryQueueAdmission($key, $session)) {
                return;
            }
            $commands = $session->play->drainCommands();
            if ($session->phase !== SessionPhase::ADMISSION_PENDING
                && $session->phase !== SessionPhase::SPAWNED && $commands !== []) {
                $this->disconnect($key);

                return;
            }
            foreach ($commands as $command) {
                if (!$this->world->enqueue($command)) {
                    $this->disconnect($key);

                    return;
                }
            }
            if (!$this->dispatchPlayerCommands($session)) {
                $this->disconnect($key);
                return;
            }
            $this->flush($key, $session);

            return;
        }
        $this->disconnect($key);
    }

    private function dispatchPlayerCommands(RuntimeSession $session): bool
    {
        if ($session->play === null) {
            return true;
        }
        foreach ($session->play->drainPlayerCommands() as $request) {
            $messages = [];
            $outputTruncated = false;
            $result = CommandResult::failure('Commands are not available yet.');
            $player = $session->phase === SessionPhase::SPAWNED
                ? $this->world->pluginPlayer($session->play->login()->identity)
                : null;
            if ($player !== null) {
                $player = $this->playerConnections->attach($player);
            }
            if ($this->commandRegistry !== null && $player !== null) {
                $sender = new ServerPlayerCommandSender(
                    $player,
                    static function (string $message) use (&$messages, &$outputTruncated): void {
                        if (count($messages) >= self::MAXIMUM_PLAYER_COMMAND_OUTPUT_MESSAGES - 1) {
                            $outputTruncated = true;

                            return;
                        }
                        $messages[] = $message;
                    },
                    fn(string $permission): bool => $this->permissionStore?->hasPermission($player->uuid, $permission) ?? false,
                );
                $result = $this->commandRegistry->dispatch($sender, $request->command);
            } else {
                $messages[] = 'Commands are not available yet.';
            }
            if ($outputTruncated) {
                $messages[] = 'Additional command output was truncated.';
            }
            $outputMessages = array_map(
                static fn(string $message): CommandOutputMessage => new CommandOutputMessage($message),
                $messages === [] ? [$result->message() ?? ($result->isSuccess() ? 'Command completed.' : 'Command failed.')] : $messages,
            );
            if (!$session->play->queuePacket(new CommandOutputPacket(
                $request->origin,
                CommandOutputType::AllOutput,
                $result->isSuccess() ? 1 : 0,
                $outputMessages,
            ))) {
                return false;
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
            if ($this->playerPersistence !== null) {
                $loaded = $this->playerPersistence->load($ready->login);
                foreach ($this->sessions as $other) {
                    if ($other !== $session && $other->bootstrap?->identity->uuid === $loaded->identity->uuid) {
                        $this->diagnostics->record('play.duplicate_identity_rejected');
                        $this->rejectReadySession($key, $session, $ready, 'This account is already connected to this server.');

                        return;
                    }
                }
                $bootstrap = $this->world->prepareLogin(
                    $session->id,
                    $session->runtimeEntityId->toSignedBits(),
                    $loaded,
                );
                if ($bootstrap === null) {
                    $this->diagnostics->record('play.login_cancelled');
                    $ready->encryptor->close();
                    $ready->decryptor->close();
                    $this->disconnect($key);

                    return;
                }
            }
            $play = $this->playChannels->create($ready, $session->id, $session->runtimeEntityId, $bootstrap);
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
                fn(string $reason, ?string $quitMessage, ?string $screenMessage): bool => $this->kickPlayer(
                    $key,
                    $session,
                    $reason,
                    $quitMessage,
                    $screenMessage,
                ),
            );
            $this->flush($key, $session);
        } catch (Throwable $exception) {
            $this->diagnostics->record('play.channel_creation_failed', [
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
            $ready->encryptor->close();
            $ready->decryptor->close();
            $this->disconnect($key);
        }
    }

    /** Queues admission exactly once when the play channel releases its readiness latch. */
    private function tryQueueAdmission(string $key, RuntimeSession $session): bool
    {
        if ($session->phase !== SessionPhase::INITIALIZING || $session->play === null
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
            $this->disconnect($key);

            return false;
        }
        if (!$this->world->enqueue($join)) {
            $this->disconnect($key);

            return false;
        }
        $session->phase = SessionPhase::ADMISSION_PENDING;

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
                $this->diagnostics->record('runtime.transport_send_failed', ['exception' => $exception::class]);
                $this->disconnect($key);

                return;
            }
        }
    }

    private function disconnect(string $key): void
    {
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession) {
            return;
        }
        $this->diagnostics->record('runtime.session_disconnected', ['phase' => strtolower($session->phase->name)]);
        $this->removeTransportSession($session->transport);
        $this->removeRuntimeSession($key);
    }

    private function closeEndpoint(SessionInfo $info): void
    {
        $key = self::endpointKey($info);
        if (($this->pendingTransportCloses[$key][0] ?? null) === $info) {
            unset($this->pendingTransportCloses[$key]);
        }
        if (($this->sessions[$key]->transport ?? null) !== $info) {
            return;
        }
        $this->removeRuntimeSession($key);
    }

    private function kickPlayer(string $key, RuntimeSession $session, string $reason, ?string $quitMessage, ?string $screenMessage): bool
    {
        if (($this->sessions[$key] ?? null) !== $session || $session->play === null) {
            return false;
        }
        $identity = $session->bootstrap?->identity->uuid ?? $session->play->login()->identity;
        $player = $this->world->pluginPlayer($identity);
        if ($player !== null && $this->pluginEvents !== null) {
            $decision = $this->pluginEvents->kick($player, $reason, $quitMessage, $screenMessage);
            if ($decision === null) {
                return false;
            }
            [$reason, $quitMessage, $screenMessage] = $decision;
        }
        $message = $screenMessage ?? ($reason !== '' ? $reason : 'Disconnected from server.');
        try {
            $packet = new DisconnectPacket(DisconnectReason::KICKED, false, $message, $message);
            $quitPacket = $quitMessage !== null && $quitMessage !== '' ? new SystemTextPacket($quitMessage) : null;
        } catch (Throwable) {
            return false;
        }
        if (!$session->play->queuePacket($packet)) {
            $this->disconnect($key);
            return false;
        }
        $this->flush($key, $session);
        if (($this->sessions[$key] ?? null) !== $session) {
            return false;
        }
        if ($quitPacket !== null) {
            foreach ($this->sessions as $otherKey => $other) {
                if ($otherKey !== $key && $other->joined && $other->play !== null
                    && $other->play->queuePacket($quitPacket)) {
                    $this->flush($otherKey, $other);
                }
            }
        }
        $this->deferTransportClose($key, $session);

        return true;
    }

    private function rejectReadySession(string $key, RuntimeSession $session, \Bedriox\Server\Login\LoginChannelReady $ready, string $message): void
    {
        try {
            $packet = new DisconnectPacket(DisconnectReason::KICKED, false, $message, $message);
            $batch = new BedrockBatch([
                new PacketFrame(new PacketHeader(BedrockPacketCodec::packetId($packet)), BedrockPacketCodec::encode($packet, $ready->protocolVersion)),
            ], CompressionMode::NegotiatedZlib, 256);
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
            $this->disconnect($key);
        } finally {
            $ready->encryptor->close();
            $ready->decryptor->close();
        }
    }

    private function deferTransportClose(string $key, RuntimeSession $session): void
    {
        $this->pendingTransportCloses[$key] = [$session->transport, $this->closeClock->nowNanoseconds() + 10_000_000_000];
        $this->removeRuntimeSession($key);
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

    private function removeRuntimeSession(string $key): void
    {
        $session = $this->sessions[$key] ?? null;
        if (!$session instanceof RuntimeSession) {
            return;
        }
        $identity = $session->bootstrap?->identity->uuid ?? $session->play?->login()->identity;
        if ($identity !== null) {
            $this->playerConnections->disconnect($identity, $session->id);
        }
        unset($this->sessions[$key], $this->sessionEndpoints[$session->id]);
        if ($session->joined || $session->phase === SessionPhase::ADMISSION_PENDING) {
            if (!$this->world->enqueue($this->commands->disconnect($session->id)) && $this->closed) {
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
        while ($this->world->queuedLifecycleCommands() > 0) {
            $before = $this->world->queuedLifecycleCommands();
            try {
                $tick = $this->world->drainLifecycle();
                foreach ($tick->events as $event) {
                    if ($event instanceof PlayerDisconnected) {
                        $this->actorVisibility->remove($event->sessionId);
                    }
                }
            } catch (Throwable $exception) {
                $this->recordShutdownFailure('runtime.lifecycle_drain_failed', $exception);
            }
            if ($this->world->queuedLifecycleCommands() >= $before) {
                $this->recordShutdownFailure(
                    'runtime.lifecycle_drain_stalled',
                    new RuntimeException('Player disconnect lifecycle made no progress during shutdown.'),
                );

                return;
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
        $snapshot = $this->world->snapshot();
        $this->crashContext->publishRuntime($snapshot->tick, $players, $involved);
    }

    /** Reconciles the asynchronous authoritative join result before any event is encoded. */
    private function reconcileAdmission(WorldEvent $event): bool
    {
        if ($event instanceof PlayerJoined) {
            $session = $this->sessionById($event->player->sessionId);
            if ($session?->phase === SessionPhase::ADMISSION_PENDING) {
                $session->joined = true;
                $session->phase = SessionPhase::SPAWNED;
            }

            return true;
        }
        if ($event instanceof CommandRejected
            && in_array($event->reason, ['duplicate_session', 'duplicate_identity', 'world_full', 'plugin_cancelled'], true)) {
            $session = $this->sessionById($event->sessionId);
            if ($session?->phase === SessionPhase::ADMISSION_PENDING) {
                $this->disconnect(self::endpointKey($session->transport));

                return false;
            }
        }

        return true;
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
            || $event instanceof ItemEntityPickedUp || $event instanceof ItemEntityDespawned) {
            return true;
        }
        if ($ownerSessionId === null) {
            return $this->failRuntime('ownerless_event_failure');
        }
        $key = $this->sessionEndpoints[$ownerSessionId] ?? null;
        if ($key !== null) {
            $this->disconnect($key);
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
            $event instanceof MovementCorrected => $event->authoritativePlayer->sessionId,
            $event instanceof PlayerDisconnected => $event->sessionId,
            $event instanceof PlayerBecameHidden => $event->playerSessionId,
            $event instanceof PlayerBecameVisible => $event->player->sessionId,
            $event instanceof PlayerKnockedBack, $event instanceof PlayerMotionChanged => $event->ownerSessionId,
            $event instanceof PlayerJoined, $event instanceof PlayerMoved, $event instanceof PlayerDamaged,
            $event instanceof PlayerDied, $event instanceof PlayerRespawned, $event instanceof RespawnAcknowledged,
            $event instanceof PlayerGameModeChanged => $event->player->sessionId,
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

    /** @return list<WorldEvent> */
    private function reconcileActorVisibilityForViewer(string $sessionId): array
    {
        $viewer = $this->sessionById($sessionId);

        return $this->actorVisibility->reconcileViewer(
            $sessionId,
            static fn(\Bedriox\Server\Simulation\PlayerSnapshot $actor): bool => $viewer?->phase === SessionPhase::SPAWNED
                && $actor->gameMode->isVisible()
                && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false),
        );
    }

    private function viewerCanSee(string $viewerSessionId, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool
    {
        $viewer = $this->sessionById($viewerSessionId);

        return $viewer?->phase === SessionPhase::SPAWNED
            && $actor->gameMode->isVisible()
            && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false);
    }

    /**
     * @return list<ItemEntitySpawned|ItemEntityMoved|ItemEntityPickedUp|ItemEntityDespawned>
     */
    private function reconcileItemEvent(
        ItemEntitySpawned|ItemEntityMoved|ItemEntityPickedUp|ItemEntityDespawned $event,
    ): array {
        if ($event instanceof ItemEntityPickedUp) {
            $viewers = array_keys($this->itemActorViewers[$event->itemRuntimeActorId] ?? []);
            $this->diagnostics->record('world.item_actor.protocol_trace', [
                'action' => 'picked_up',
                'actor_id' => $event->itemRuntimeActorId,
                'viewers' => count($viewers),
            ]);
            unset($this->itemActors[$event->itemRuntimeActorId], $this->itemActorViewers[$event->itemRuntimeActorId]);

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
            $viewers = array_keys($this->itemActorViewers[$event->runtimeActorId] ?? []);
            $this->diagnostics->record('world.item_actor.protocol_trace', [
                'action' => 'expired',
                'actor_id' => $event->runtimeActorId,
                'viewers' => count($viewers),
            ]);
            unset($this->itemActors[$event->runtimeActorId], $this->itemActorViewers[$event->runtimeActorId]);

            return $viewers === [] ? [] : [new ItemEntityDespawned($event->runtimeActorId, $viewers)];
        }

        $entity = $event->entity;
        $runtimeId = $entity->runtimeEntityId;
        $this->itemActors[$runtimeId] = $entity;
        $previous = $this->itemActorViewers[$runtimeId] ?? [];
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
        $this->itemActorViewers[$runtimeId] = $eligible;
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

    /** @return list<ItemEntitySpawned|ItemEntityDespawned> */
    private function reconcileItemsForViewer(string $sessionId): array
    {
        $events = [];
        foreach ($this->itemActors as $entity) {
            foreach ($this->reconcileItemEvent(new ItemEntitySpawned($entity, [$sessionId])) as $event) {
                if ($event instanceof ItemEntitySpawned || $event instanceof ItemEntityDespawned) {
                    $events[] = $event;
                }
            }
        }

        return $events;
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
        if (count($directedPackets) > $this->limits->maximumDirectedPacketsPerPoll - $directedCount) {
            return $this->containEventFailure($event, 'runtime.directed_packet_limit_exceeded', [
                'packet_count' => count($directedPackets),
                'remaining_budget' => $this->limits->maximumDirectedPacketsPerPoll - $directedCount,
            ]);
        }
        $directedCount += count($directedPackets);
        foreach ($directedPackets as $directed) {
            $session = $this->sessionById($directed->sessionId);
            if ($session?->play === null || !$session->play->queuePacket($directed->packet)) {
                if ($session !== null) {
                    $this->disconnect(self::endpointKey($session->transport));
                }
            }
        }

        return true;
    }

    private function sessionById(string $id): ?RuntimeSession
    {
        $key = $this->sessionEndpoints[$id] ?? null;

        return $key === null ? null : ($this->sessions[$key] ?? null);
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
        $this->disconnect(self::endpointKey($session->transport));

        return false;
    }

    /** @return array<string, RuntimeSession> */
    private function sessionEndpointsById(): array
    {
        $sessions = [];
        foreach ($this->sessions as $session) {
            $sessions[$session->id] = $session;
        }

        return $sessions;
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
