<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Player\InventoryContainer;
use Bedriox\Server\Player\InventoryResponseMode;
use Bedriox\Server\Player\InventorySlotReference;
use Bedriox\Server\Player\InventoryStack;
use Bedriox\Server\Player\InventoryStackRequestResult;
use Bedriox\Server\Player\Persistence\PlayerPersistenceManager;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerBootstrap;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Simulation\Command\AcknowledgeRespawn;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\DamagePlayer;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\RespawnPlayer;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
use Bedriox\Server\Simulation\Command\SendPluginMessage;
use Bedriox\Server\Simulation\Command\SetPluginBlock;
use Bedriox\Server\Simulation\Command\SetPluginInventorySlot;
use Bedriox\Server\Simulation\Command\TeleportPlayer;
use Bedriox\Server\Simulation\Command\WorldCommand;
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
use Bedriox\Server\Simulation\Event\PlayerDamaged;
use Bedriox\Server\Simulation\Event\PlayerDied;
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\PlayerRespawned;
use Bedriox\Server\Simulation\Event\RespawnAcknowledged;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
use Bedriox\Server\World\Block\InternalBlockStateId;
use Bedriox\Server\World\BlockPosition;
use Bedriox\Server\World\Collision\AxisAlignedBox;
use Bedriox\Server\World\Collision\BlockCollisionQuery;
use Bedriox\Server\World\Collision\PlayerCollisionResolver;
use Bedriox\Server\World\Collision\PlayerCollisionShape;
use Bedriox\Server\World\World;
use InvalidArgumentException;
use OverflowException;
use SplQueue;

final class WorldSimulation
{
    private const int EMOTE_COOLDOWN_TICKS = 5;
    private const int EMPTY_HAND_GRASS_BREAK_RATE = 3640;
    private const float MAXIMUM_BLOCK_REACH = 6.0;

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
    private readonly ?PlayerCollisionResolver $collisionResolver;

    /** @var list<WorldEvent> */
    private array $deferredEvents = [];

    /** @var array<string, array{position: BlockPosition, state: int, sequence: int}> */
    private array $breakingBlocks = [];

    public function __construct(
        private readonly SimulationLimits $limits = new SimulationLimits(),
        private readonly Position $spawn = new Position(0.0, 64.0, 0.0),
        private readonly ?World $blockWorld = null,
        private readonly ?FixedFlatBlockPalette $blockPalette = null,
        private readonly ?PluginGameplayEventBridge $pluginEvents = null,
        private readonly ?InternalBlockStateId $waterState = null,
        private readonly ?InternalBlockStateId $lavaState = null,
        private readonly ?PlayerPersistenceManager $playerPersistence = null,
    ) {
        $this->commands = new SplQueue();
        $this->lifecycleCommands = new SplQueue();
        $this->movementOrder = new SplQueue();
        $this->validator = new SimulationCommandFactory($this->limits);
        $this->players = new PlayerRegistry($this->limits->maximumPlayers);
        $this->collisionResolver = $blockWorld !== null && $blockPalette !== null
            ? new PlayerCollisionResolver(new BlockCollisionQuery(
                $blockWorld,
                $blockPalette->air,
                array_values(array_filter([$waterState, $lavaState])),
            ))
            : null;
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
            PlayerInventory::restore($bootstrap->inventory, $this->blockPalette),
            $bootstrap->worldName,
            $bootstrap->firstPlayedAt,
            $bootstrap->gamemode,
            $bootstrap->health,
        );
        $candidate->movement->yaw = $bootstrap->yaw;
        $candidate->movement->headYaw = $bootstrap->yaw;
        $candidate->movement->pitch = $bootstrap->pitch;
        $decision = $this->pluginEvents?->login(PluginGameplayEventBridge::playerView($candidate));
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
            array_push($events, ...$this->drainDeferredEvents());
        }

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
                $views[] = PluginGameplayEventBridge::playerView($player);
            }
        }

        return $views;
    }

    public function pluginPlayer(string $identity): ?\Bedriox\Api\Player\Player
    {
        $player = $this->players->playerByIdentity($identity);

        return $player === null ? null : PluginGameplayEventBridge::playerView($player);
    }

    public function enqueuePluginMessage(string $identity, string $message): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null && $this->enqueue($this->validator->pluginMessage($player->sessionId, $message));
    }

    public function enqueuePluginTeleport(string $identity, Position $position): bool
    {
        $player = $this->players->playerByIdentity($identity);

        return $player !== null && $this->enqueue($this->validator->teleport(
            $player->sessionId,
            $position->x,
            $position->y,
            $position->z,
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

    private function apply(WorldCommand $command): ?WorldEvent
    {
        $player = $this->players->player($command->sessionId());
        if ($player !== null && !$player->vitals->isAlive()
            && !$command instanceof RespawnPlayer
            && !$command instanceof AcknowledgeRespawn
            && !$command instanceof DisconnectPlayer) {
            return new CommandRejected($command->sessionId(), 'player_dead');
        }

        return match (true) {
            $command instanceof JoinPlayer => $this->join($command),
            $command instanceof SendChat => $this->chat($command),
            $command instanceof PerformEmote => $this->emote($command),
            $command instanceof BreakBlock => $this->breakBlock($command),
            $command instanceof PlaceBlock => $this->placeBlock($command),
            $command instanceof ApplyInventoryStackRequest => $this->inventoryStackRequest($command),
            $command instanceof SelectHotbarSlot => $this->selectHotbarSlot($command),
            $command instanceof DisconnectPlayer => $this->disconnect($command),
            $command instanceof SendPluginMessage => $this->pluginMessage($command),
            $command instanceof TeleportPlayer => $this->pluginTeleport($command),
            $command instanceof SetPluginBlock => $this->pluginBlock($command),
            $command instanceof SetPluginInventorySlot => $this->pluginInventorySlot($command),
            $command instanceof DamagePlayer => $this->damage($command),
            $command instanceof RespawnPlayer => $this->respawn($command),
            $command instanceof AcknowledgeRespawn => $this->acknowledgeRespawn($command),
            default => null,
        };
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
            ? PlayerInventory::restore($bootstrap->inventory, $this->blockPalette)
            : ($this->blockPalette === null ? PlayerInventory::empty() : PlayerInventory::starter($this->blockPalette));
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
            return new MovementCorrected($player->snapshot(), 'player_dead');
        }
        $movement = $player->movement;
        if ($command->sequence <= $movement->sequence) {
            return new MovementCorrected($player->snapshot(), 'stale_sequence');
        }
        $movement->sequence = $command->sequence;
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
            return new MovementCorrected($player->snapshot(), 'movement_rate');
        }

        $wasGrounded = $movement->verticalState === VerticalState::GROUNDED;
        $collidedVertically = false;
        $terrainConstrained = false;
        if ($this->collisionResolver === null) {
            if ($command->position->y < $this->limits->flatGroundY - $this->limits->flatGroundTolerance) {
                return new MovementCorrected($player->snapshot(), 'terrain_collision');
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
            $wasGrounded
            && $position->y > $movement->position->y + $this->limits->flatGroundTolerance
            && !$stepped
            && !$command->jumpRequested
            && $this->tick > $movement->jumpAuthorizedUntilTick
        ) {
            return new MovementCorrected($player->snapshot(), 'jump_required');
        }

        if (
            $wasGrounded
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
            return new MovementCorrected($player->snapshot(), 'plugin_cancelled');
        }
        $verticalDistance = $position->y - $movement->position->y;
        $movement->position = $position;
        $movement->yaw = $command->yaw;
        $movement->headYaw = $command->headYaw ?? $command->yaw;
        $movement->pitch = $command->pitch;
        $movement->mode = $command->mode;
        $movement->sneaking = $sneaking;
        $movement->sprinting = $sprinting;
        $movement->verticalState = $grounded ? VerticalState::GROUNDED : VerticalState::AIRBORNE;
        $movement->verticalVelocity = $grounded || $collidedVertically ? 0.0 : $command->deltaY;
        $movement->distanceThisTick += $distance;
        $movement->lastTick = $this->tick;
        $player->markDirty();

        $snapshot = $player->snapshot();
        $this->pluginEvents?->moved($player);
        if ($verticalDistance < $movement->fallDistance) {
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
            );
        }

        return new PlayerMoved($snapshot, $this->players->recipients($player->sessionId), $postureChanged);
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
        if ($this->tick <= $player->vitals->invulnerableUntilTick) {
            return new CommandRejected($command->session, 'damage_cooldown');
        }
        $damage = $this->pluginEvents === null
            ? $command->amount
            : $this->pluginEvents->damage($player, $command->cause, $command->amount);
        if ($damage === null) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        if ($damage <= 0.0) {
            return new CommandRejected($command->session, 'plugin_cancelled');
        }
        $applied = min($damage, $player->vitals->health);
        $player->vitals->health -= $applied;
        $player->vitals->invulnerableUntilTick = $this->tick + 10;
        $player->markDirty();
        $this->pluginEvents?->damaged($player, $command->cause, $applied);
        if (!$player->vitals->isAlive()) {
            $player->movement->fallDistance = 0.0;
            $this->pluginEvents?->died($player, $command->cause);
            $this->deferredEvents[] = new PlayerDied(
                $player->snapshot(),
                $command->cause,
                $this->players->recipients(),
            );
        }

        return new PlayerDamaged($player->snapshot(), $applied, $command->cause, $this->players->recipients());
    }

    private function respawn(RespawnPlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
        if ($player->vitals->isAlive()) {
            return new CommandRejected($command->session, 'already_alive');
        }
        $position = $this->pluginEvents?->respawn($player, $this->spawn) ?? $this->spawn;
        $player->movement->position = $position;
        $player->movement->yaw = 0.0;
        $player->movement->headYaw = 0.0;
        $player->movement->pitch = 0.0;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->fallDistance = 0.0;
        $player->movement->verticalState = $this->collisionResolver?->isGrounded($position) === false
            ? VerticalState::AIRBORNE
            : VerticalState::GROUNDED;
        $player->vitals->health = \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH;
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

    private function acknowledgeRespawn(AcknowledgeRespawn $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player !== null && !$player->vitals->isAlive()) {
            $this->deferredEvents[] = $this->respawn(new RespawnPlayer($command->session));
        }

        return $player === null
            ? new CommandRejected($command->session, 'not_joined')
            : new RespawnAcknowledged($player->snapshot());
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

    private function disconnect(DisconnectPlayer $command): WorldEvent
    {
        unset($this->breakingBlocks[self::sessionKey($command->session)]);
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }
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
                ),
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
                $command instanceof DamagePlayer => $this->validator->damage(
                    $command->session,
                    $command->amount,
                    $command->cause,
                ),
                $command instanceof RespawnPlayer => $this->validator->respawn($command->session),
                $command instanceof AcknowledgeRespawn => $this->validator->acknowledgeRespawn($command->session),
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
        if ($command->action === BlockBreakAction::Start) {
            if ($state->value === $this->blockPalette->air->value
                || $state->value === $this->blockPalette->bedrock->value
                || $state->value === $this->waterState?->value
                || $state->value === $this->lavaState?->value) {
                return new CommandRejected($command->session, 'block_not_breakable');
            }
            if ($active !== null && $command->sequence <= $active['sequence']) {
                return new CommandRejected($command->session, 'stale_block_sequence');
            }
            $this->breakingBlocks[$key] = [
                'position' => $position,
                'state' => $state->value,
                'sequence' => $command->sequence,
            ];

            return new BlockBreakStarted(
                $command->session,
                $position,
                self::EMPTY_HAND_GRASS_BREAK_RATE,
                $this->players->recipients(),
                $active !== null && !$active['position']->equals($position) ? $active['position'] : null,
            );
        }
        $stopsActiveBreak = $active !== null && $active['position']->equals($position);
        if ($state->value === $this->blockPalette->air->value
            || $state->value === $this->blockPalette->bedrock->value
            || $state->value === $this->waterState?->value
            || $state->value === $this->lavaState?->value
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
        $this->refreshPlayerGroundStates();
        $this->pluginEvents?->blockBroken($player, $position, $identifier);

        return new BlockChanged(
            $command->session,
            $position,
            $this->blockPalette->air,
            $this->players->recipients(),
            $stopsActiveBreak,
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
        if ($this->pluginEvents === null) {
            $result = $command->rejectionReason === null
                ? $player->inventory->applyStackRequest($command->requestId, $command->actions)
                : new InventoryStackRequestResult(false, reason: $command->rejectionReason);
        } else {
            $before = clone $player->inventory;
            $proposed = clone $player->inventory;
            $result = $command->rejectionReason === null
                ? $proposed->applyStackRequest($command->requestId, $command->actions)
                : new InventoryStackRequestResult(false, reason: $command->rejectionReason);
            if ($result->success && !$this->pluginEvents->allowInventoryChange($player, $before, $proposed)) {
                $result = new InventoryStackRequestResult(false, reason: 'plugin_cancelled');
            } elseif ($result->success) {
                $result = $player->inventory->applyStackRequest($command->requestId, $command->actions);
                if ($result->success) {
                    $this->pluginEvents->inventoryChanged($player, $before);
                }
            }
        }
        if ($result->success) {
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
        );
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
        $placedState = $this->blockWorld->blockStateAt($placedPosition->x, $placedPosition->y, $placedPosition->z);
        $key = self::sessionKey($command->session);
        $activeBreak = $this->breakingBlocks[$key] ?? null;
        unset($this->breakingBlocks[$key]);
        $held = $player->inventory->selectedStack();
        $correctionReason = match (true) {
            $command->sequence <= $player->placementSequence => 'stale_sequence',
            $command->hotbarSlot !== $player->inventory->selectedHotbarSlot() => 'selected_slot',
            $held === null => 'empty_hand',
            $held->identifier !== 'minecraft:grass_block'
                || $held->placedBlockState?->value !== $this->blockPalette->grassBlock->value => 'unsupported_item',
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
            && !$this->pluginEvents->allowBlockPlace($player, $placedPosition, 'minecraft:grass_block')) {
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
                $this->blockPalette->grassBlock,
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
        $this->refreshPlayerGroundStates();
        $remaining = $player->inventory->decrementSelectedOne();
        $player->markDirty();
        $this->pluginEvents?->blockPlaced($player, $placedPosition, 'minecraft:grass_block');

        return new BlockPlaced(
            $command->session,
            $player->runtimeActorId,
            $placedPosition,
            $this->blockPalette->grassBlock,
            $player->inventory->selectedHotbarSlot(),
            $remaining,
            $this->players->recipients(),
            $activeBreak['position'] ?? null,
        );
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
        if ($this->blockWorld !== null && $this->blockPalette !== null
            && (new BlockCollisionQuery($this->blockWorld, $this->blockPalette->air))
                ->hasCollision(PlayerCollisionShape::at($command->position))) {
            return new MovementCorrected($player->snapshot(), 'plugin_teleport_collision');
        }
        $player->movement->position = $command->position;
        $player->movement->mode = MovementMode::STOPPED;
        $player->movement->verticalVelocity = 0.0;
        $player->movement->jumpAuthorizedUntilTick = -1;
        $player->movement->lastTick = $this->tick;
        $player->movement->verticalState = $this->collisionResolver?->isGrounded($command->position) === true
            ? VerticalState::GROUNDED
            : VerticalState::AIRBORNE;
        $player->markDirty();

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
        $state = match ($command->identifier) {
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
        );
    }

    private function blockIdentifier(int $state): string
    {
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
