<?php

declare(strict_types=1);

namespace Bedriox\Server\Simulation;

use Bedriox\Server\Player\InventoryStackRequestResult;
use Bedriox\Server\Player\Player;
use Bedriox\Server\Player\PlayerIdentity;
use Bedriox\Server\Player\PlayerInventory;
use Bedriox\Server\Player\PlayerRegistry;
use Bedriox\Server\Simulation\Command\ApplyInventoryStackRequest;
use Bedriox\Server\Simulation\Command\BreakBlock;
use Bedriox\Server\Simulation\Command\DisconnectPlayer;
use Bedriox\Server\Simulation\Command\JoinPlayer;
use Bedriox\Server\Simulation\Command\MovePlayer;
use Bedriox\Server\Simulation\Command\PerformEmote;
use Bedriox\Server\Simulation\Command\PlaceBlock;
use Bedriox\Server\Simulation\Command\SelectHotbarSlot;
use Bedriox\Server\Simulation\Command\SendChat;
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
use Bedriox\Server\Simulation\Event\PlayerDisconnected;
use Bedriox\Server\Simulation\Event\PlayerJoined;
use Bedriox\Server\Simulation\Event\PlayerMoved;
use Bedriox\Server\Simulation\Event\WorldEvent;
use Bedriox\Server\World\Block\FixedFlatBlockPalette;
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
    private int $tick = 0;
    private int $nextRuntimeActorId = 1;
    private readonly SimulationCommandFactory $validator;
    private readonly ?PlayerCollisionResolver $collisionResolver;

    /** @var array<string, array{position: BlockPosition, state: int, sequence: int}> */
    private array $breakingBlocks = [];

    public function __construct(
        private readonly SimulationLimits $limits = new SimulationLimits(),
        private readonly Position $spawn = new Position(0.0, 64.0, 0.0),
        private readonly ?World $blockWorld = null,
        private readonly ?FixedFlatBlockPalette $blockPalette = null,
    ) {
        $this->commands = new SplQueue();
        $this->lifecycleCommands = new SplQueue();
        $this->movementOrder = new SplQueue();
        $this->validator = new SimulationCommandFactory($this->limits);
        $this->players = new PlayerRegistry($this->limits->maximumPlayers);
        $this->collisionResolver = $blockWorld !== null && $blockPalette !== null
            ? new PlayerCollisionResolver(new BlockCollisionQuery($blockWorld, $blockPalette->air))
            : null;
        $this->validator->move('spawn', 0, $spawn->x, $spawn->y, $spawn->z, 0.0, 0.0, MovementMode::STOPPED);
        if ($spawn->y < $this->limits->flatGroundY) {
            throw new InvalidArgumentException('Spawn cannot be below the flat-world surface.');
        }
    }

    public function enqueue(WorldCommand $command): bool
    {
        if (!$this->isValid($command)) {
            return false;
        }
        if ($command instanceof DisconnectPlayer) {
            return $this->enqueueDisconnect($command);
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
        $events = [];
        $processed = 0;
        while (!$this->lifecycleCommands->isEmpty() && $processed < $this->limits->maximumCommandsPerTick) {
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
        }

        return new SimulationTick($this->tick, $processed, $events);
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

    private function apply(WorldCommand $command): ?WorldEvent
    {
        return match (true) {
            $command instanceof JoinPlayer => $this->join($command),
            $command instanceof SendChat => $this->chat($command),
            $command instanceof PerformEmote => $this->emote($command),
            $command instanceof BreakBlock => $this->breakBlock($command),
            $command instanceof PlaceBlock => $this->placeBlock($command),
            $command instanceof ApplyInventoryStackRequest => $this->inventoryStackRequest($command),
            $command instanceof SelectHotbarSlot => $this->selectHotbarSlot($command),
            $command instanceof DisconnectPlayer => $this->disconnect($command),
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

        $peers = $this->players->snapshots();
        $player = new Player(
            $command->session,
            $runtimeActorId,
            new PlayerIdentity($command->identity, $command->displayName),
            $this->spawn,
            $this->limits->chatBucketCapacity,
            $this->tick,
            $this->limits->flatGroundY,
            $this->blockPalette === null ? PlayerInventory::empty() : PlayerInventory::starter($this->blockPalette),
        );
        if ($this->collisionResolver !== null) {
            $player->movement->verticalState = $this->collisionResolver->isGrounded($this->spawn)
                ? VerticalState::GROUNDED
                : VerticalState::AIRBORNE;
        }
        $this->players->add($player);
        if ($runtimeActorId >= $this->nextRuntimeActorId && $runtimeActorId < PHP_INT_MAX) {
            $this->nextRuntimeActorId = $runtimeActorId + 1;
        }

        return new PlayerJoined($player->snapshot(), $peers, $this->players->recipients());
    }

    private function acceptMovementInput(MovePlayer $command): WorldEvent
    {
        $player = $this->players->player($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
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

        $snapshot = $player->snapshot();
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

        --$player->chatTokens;
        return new ChatBroadcast(
            $player->sessionId,
            $player->identity->uuid,
            $player->identity->displayName,
            $command->sequence,
            $command->message,
            $this->players->recipients(),
        );
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
        $player = $this->players->remove($command->session);
        if ($player === null) {
            return new CommandRejected($command->session, 'not_joined');
        }

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
            if ($state->value === $this->blockPalette->air->value || $state->value === $this->blockPalette->bedrock->value) {
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
        $player->inventory->selectHotbarSlot($command->hotbarSlot);

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
        $result = $command->rejectionReason === null
            ? $player->inventory->applyStackRequest($command->requestId, $command->actions)
            : new InventoryStackRequestResult(false, reason: $command->rejectionReason);

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
