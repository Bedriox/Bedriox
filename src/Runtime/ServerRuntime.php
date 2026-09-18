<?php

declare(strict_types=1);

namespace Bedriox\Server\Runtime;

use Bedriox\Protocol\Value\UnsignedLong;
use Bedriox\RakNet\Connected\ConnectedPayloadEvent;
use Bedriox\RakNet\Protocol\Reliability;
use Bedriox\RakNet\ReceivedPayload;
use Bedriox\RakNet\SessionInfo;
use Bedriox\RakNet\SessionOpenedEvent;
use Bedriox\Server\Observability\CrashContextPublisher;
use Bedriox\Server\Observability\CrashPlayer;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Simulation\Event\BlockBreakStarted;
use Bedriox\Server\Simulation\Event\BlockBreakStopped;
use Bedriox\Server\Simulation\Event\BlockChanged;
use Bedriox\Server\Simulation\Event\BlockPlaced;
use Bedriox\Server\Simulation\Event\BlockPlacementCorrected;
use Bedriox\Server\Simulation\Event\ChatBroadcast;
use Bedriox\Server\Simulation\Event\CommandRejected;
use Bedriox\Server\Simulation\Event\EmotePerformed;
use Bedriox\Server\Simulation\Event\HeldItemChanged;
use Bedriox\Server\Simulation\Event\InventoryStackRequestProcessed;
use Bedriox\Server\Simulation\Event\MovementCorrected;
use Bedriox\Server\Simulation\Event\PlayerBecameHidden;
use Bedriox\Server\Simulation\Event\PlayerBecameVisible;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\Simulation\FixedRateWorldLoop;
use Bedriox\Server\Simulation\SimulationCommandFactory;
use Bedriox\Server\Simulation\WorldSimulation;
use Bedriox\Server\Transport\ConnectedTransport;
use Bedriox\Server\World\World;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/** Bounded single-threaded composition root for transport, protocol, and simulation. */
final class ServerRuntime implements RuntimeDriver, RuntimeFailureSource
{
    /** @var array<string, RuntimeSession> endpoint key => session */
    private array $sessions = [];

    /** @var array<string, string> runtime session ID => endpoint key */
    private array $sessionEndpoints = [];

    private bool $closed = false;
    private int $nextRuntimeEntityId = 1;
    private readonly SimulationCommandFactory $commands;
    private readonly RuntimeDiagnostics $diagnostics;
    private readonly PlayerActorVisibilityRegistry $actorVisibility;
    private ?Throwable $failure = null;
    private ?string $involvedSessionId = null;
    private bool $autosaveActive = false;
    private bool $playerAutosaveActive = false;

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
    ) {
        if ($this->autosaveIntervalTicks < 1 || $this->autosaveChunkBudget < 1
            || $this->playerAutosaveIntervalTicks < 1 || $this->playerAutosaveBudget < 1) {
            throw new InvalidArgumentException('Autosave interval and chunk budget must be positive.');
        }
        $this->commands = $commands ?? new SimulationCommandFactory();
        $this->diagnostics = $diagnostics ?? RuntimeDiagnostics::disabled();
        $this->actorVisibility = new PlayerActorVisibilityRegistry($this->limits->maximumSessions);
    }

    public function __destruct()
    {
        $this->close();
    }

    /** Runs one non-blocking, explicitly bounded network and simulation iteration. */
    public function poll(): bool
    {
        if ($this->closed) {
            return false;
        }
        try {
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
                    $this->closeEndpoint($event->session);
                }
            }
            foreach ($payloads as $payload) {
                $this->involvedSessionId = $this->sessions[self::rawEndpointKey($payload->remoteAddress, $payload->remotePort)]->id ?? null;
                $this->publishCrashContext();
                $this->accept($payload);
                $this->involvedSessionId = null;
            }
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
            $directedCount = 0;
            foreach ($this->worldLoop->poll() as $tick) {
                foreach (array_keys($this->sessions) as $key) {
                    $play = $this->sessions[$key]->play;
                    if ($play !== null && !$play->worldTick()) {
                        $this->disconnect($key);
                    }
                }
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
                    if (($event instanceof PlayerJoined || $event instanceof PlayerMoved)
                        && !$this->updateAuthoritativeChunkView($event->player)) {
                        continue;
                    }
                    if ($event instanceof MovementCorrected && $event->peerSessionIds !== []
                        && !$this->updateAuthoritativeChunkView($event->authoritativePlayer)) {
                        continue;
                    }
                    if ($event instanceof BlockBreakStarted
                        || $event instanceof BlockBreakStopped
                        || $event instanceof BlockChanged
                        || $event instanceof BlockPlaced) {
                        $event = $this->filterBlockRecipients($event);
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
                        continue;
                    }
                    if ($event instanceof PlayerMoved) {
                        $this->actorVisibility->upsert($event->player);
                        $session = $this->sessionById($event->player->sessionId);
                        $session?->play?->takeChunkVisibilityChanged();
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
                        ), $directedCount)) {
                            return false;
                        }
                        continue;
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
                        );
                    }
                    if (!$this->dispatchWorldEvent($event, $directedCount)) {
                        return false;
                    }
                }
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
            }
            foreach (array_keys($this->sessions) as $key) {
                $this->flush($key, $this->sessions[$key]);
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

    public function isClosed(): bool
    {
        return $this->closed;
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
        $this->drainShutdownLifecycle();
        if ($this->playerPersistence !== null && $this->playerPersistence->pendingCount() > 0) {
            $this->playerPersistence->retryPending($this->limits->maximumSessions);
            if ($this->playerPersistence->pendingCount() > 0) {
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
            $this->flush($key, $session);

            return;
        }
        $this->disconnect($key);
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
                        $ready->encryptor->close();
                        $ready->decryptor->close();
                        $this->disconnect($key);

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
            $this->flush($key, $session);
        } catch (Throwable $exception) {
            $this->diagnostics->record('play.channel_creation_failed', ['exception' => $exception::class]);
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
        $this->removeRuntimeSession(self::endpointKey($info));
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
            $event instanceof BlockBreakStarted, $event instanceof BlockBreakStopped, $event instanceof BlockChanged,
            $event instanceof BlockPlaced, $event instanceof BlockPlacementCorrected, $event instanceof HeldItemChanged => $event->ownerSessionId,
            $event instanceof CommandRejected => $event->sessionId,
            $event instanceof EmotePerformed => $event->senderSessionId,
            $event instanceof MovementCorrected => $event->authoritativePlayer->sessionId,
            $event instanceof PlayerDisconnected => $event->sessionId,
            $event instanceof PlayerBecameHidden => $event->playerSessionId,
            $event instanceof PlayerBecameVisible => $event->player->sessionId,
            $event instanceof PlayerJoined, $event instanceof PlayerMoved => $event->player->sessionId,
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
                && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false),
        );
    }

    private function viewerCanSee(string $viewerSessionId, \Bedriox\Server\Simulation\PlayerSnapshot $actor): bool
    {
        $viewer = $this->sessionById($viewerSessionId);

        return $viewer?->phase === SessionPhase::SPAWNED
            && ($viewer->play?->hasSentChunkAt($actor->position->x, $actor->position->z) ?? false);
    }

    private function filterBlockRecipients(
        BlockBreakStarted|BlockBreakStopped|BlockChanged|BlockPlaced $event,
    ): BlockBreakStarted|BlockBreakStopped|BlockChanged|BlockPlaced {
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
