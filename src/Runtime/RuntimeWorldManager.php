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

use Bedriox\Api\Event\Event;
use Bedriox\Api\Event\World\WorldCreatedEvent;
use Bedriox\Api\Event\World\WorldCreateEvent;
use Bedriox\Api\Event\World\WorldLoadedEvent;
use Bedriox\Api\Event\World\WorldLoadEvent;
use Bedriox\Api\Event\World\WorldSavedEvent;
use Bedriox\Api\Event\World\WorldSaveEvent;
use Bedriox\Api\Event\World\WorldUnloadedEvent;
use Bedriox\Api\Event\World\WorldUnloadEvent;
use Bedriox\Api\World\World;
use Bedriox\Api\World\WorldCreationOptions;
use Bedriox\Api\World\WorldInfo;
use Bedriox\Api\World\WorldLifecycleState;
use Bedriox\Api\World\WorldManager;
use Bedriox\Api\World\WorldOperation;
use Bedriox\Api\World\WorldOperationFailure;
use Bedriox\Api\World\WorldOperationFailureCode;
use Bedriox\Api\World\WorldOperationResult;
use Bedriox\Api\World\WorldOperationState;
use Bedriox\Api\World\WorldOperationType;
use Bedriox\Api\World\WorldUnloadOptions;
use Closure;
use LogicException;
use Throwable;

/** @internal Callback-backed public world service; all mutations run through the main-thread operation queue. */
final class RuntimeWorldManager implements WorldManager
{
    /** @var array<string, WorldLifecycleState> */
    private array $states = [];
    /** @var array<string, array{WorldOperationType, WorldOperation}> */
    private array $pending = [];

    /**
     * @param Closure(World, WorldCreationOptions): (ManagedWorldRuntime|PendingManagedWorldRuntime) $createRuntime
     * @param Closure(World): (ManagedWorldRuntime|PendingManagedWorldRuntime)                       $loadRuntime
     * @param null|Closure(Event): Event                                                            $dispatchEvent
     */
    public function __construct(
        private readonly WorldRuntimeManager $runtimes,
        private readonly WorldOperationQueue $operations,
        private readonly Closure $createRuntime,
        private readonly Closure $loadRuntime,
        private readonly ?Closure $dispatchEvent = null,
    ) {
        foreach ($runtimes->loaded() as $runtime) {
            $this->states[$runtime->handle->id()] = WorldLifecycleState::LOADED;
        }
    }

    public function getDefault(): World
    {
        return $this->runtimes->default()->handle;
    }

    public function get(string $worldId): ?World
    {
        return $this->runtimes->get($worldId)?->handle;
    }

    public function getLoaded(): array
    {
        return array_map(static fn(ManagedWorldRuntime $runtime): World => $runtime->handle, $this->runtimes->loaded());
    }

    public function getState(string $worldId): ?WorldLifecycleState
    {
        return $this->states[WorldRuntimeManager::canonicalId($worldId)] ?? null;
    }

    public function info(World $world): WorldInfo
    {
        $runtime = $this->runtimes->get($world->id())
            ?? throw new LogicException('World is not loaded.');
        if (!$runtime->handle->isSameLoad($world)) {
            throw new LogicException('World handle belongs to an earlier load generation.');
        }
        $internal = $runtime->opened->world;
        $spawn = $internal->spawn();
        $difficulty = match ($runtime->opened->data->difficulty) {
            0 => \Bedriox\Api\World\WorldDifficulty::PEACEFUL,
            1 => \Bedriox\Api\World\WorldDifficulty::EASY,
            2 => \Bedriox\Api\World\WorldDifficulty::NORMAL,
            3 => \Bedriox\Api\World\WorldDifficulty::HARD,
            default => throw new LogicException('Loaded world difficulty is invalid.'),
        };
        $dimensionSpawns = [];
        foreach ($runtime->dimensions() as $dimensionRuntime) {
            $dimension = $dimensionRuntime->opened->world->dimension();
            $dimensionSpawn = $dimensionRuntime->opened->world->spawn();
            $dimensionSpawns[$dimension->value] = new \Bedriox\Api\World\Position(
                (float) $dimensionSpawn->x,
                (float) $dimensionSpawn->y,
                (float) $dimensionSpawn->z,
                0.0,
                0.0,
                $runtime->handle,
                $dimension,
            );
        }

        return new WorldInfo(
            $runtime->handle,
            $internal->metadata->name,
            $runtime->opened->data->generatorName,
            $internal->metadata->seed,
            $difficulty,
            $internal->time(),
            new \Bedriox\Api\World\Position(
                (float) $spawn->x,
                (float) $spawn->y,
                (float) $spawn->z,
                0.0,
                0.0,
                $runtime->handle,
            ),
            $runtime->playerCount(),
            $runtime->handle->getDimensions(),
            $dimensionSpawns,
        );
    }

    public function create(string $worldId, WorldCreationOptions $options = new WorldCreationOptions()): WorldOperation
    {
        $id = WorldRuntimeManager::canonicalId($worldId);

        return $this->schedule(WorldOperationType::CREATE, $id, function () use ($id, $options): WorldOperationResult|PolledWorldOperationExecutor {
            try {
                $this->runtimes->assertReservable($id);
            } catch (Throwable $error) {
                return $this->exceptionFailure(WorldOperationType::CREATE, $id, $error);
            }
            $event = new WorldCreateEvent($id, $options);
            $this->dispatch($event);
            if ($event->isCancelled()) {
                return $this->cancelled(WorldOperationType::CREATE, $id);
            }
            $this->states[$id] = WorldLifecycleState::CREATING;
            try {
                $handle = $this->runtimes->reserve($id);
                $prepared = ($this->createRuntime)($handle, $options);
                if ($prepared instanceof PendingManagedWorldRuntime) {
                    return new CallbackPolledWorldOperationExecutor(function () use ($id, $handle, $prepared, $options): ?WorldOperationResult {
                        try {
                            $runtime = $prepared->poll();
                            if (!$runtime instanceof ManagedWorldRuntime) {
                                return null;
                            }
                            $this->runtimes->attach($handle, $runtime);
                            $this->states[$id] = WorldLifecycleState::LOADED;
                            $this->dispatch(new WorldCreatedEvent($runtime->handle, $options));

                            return $this->success(WorldOperationType::CREATE, $runtime->handle);
                        } catch (Throwable $error) {
                            $this->cancelFailedPreparation($prepared);
                            $this->runtimes->abortReservation($handle);
                            unset($this->states[$id]);

                            return $this->exceptionFailure(WorldOperationType::CREATE, $id, $error);
                        }
                    });
                }
                $runtime = $this->runtimes->attach($handle, $prepared);
                $this->states[$id] = WorldLifecycleState::LOADED;
                $this->dispatch(new WorldCreatedEvent($runtime->handle, $options));

                return $this->success(WorldOperationType::CREATE, $runtime->handle);
            } catch (Throwable $error) {
                if (isset($handle)) {
                    $this->runtimes->abortReservation($handle);
                }
                unset($this->states[$id]);

                return $this->exceptionFailure(WorldOperationType::CREATE, $id, $error);
            }
        });
    }

    public function load(string $worldId): WorldOperation
    {
        $id = WorldRuntimeManager::canonicalId($worldId);

        return $this->schedule(WorldOperationType::LOAD, $id, function () use ($id): WorldOperationResult|PolledWorldOperationExecutor {
            try {
                $this->runtimes->assertReservable($id);
            } catch (Throwable $error) {
                return $this->exceptionFailure(WorldOperationType::LOAD, $id, $error);
            }
            $event = new WorldLoadEvent($id);
            $this->dispatch($event);
            if ($event->isCancelled()) {
                return $this->cancelled(WorldOperationType::LOAD, $id);
            }
            $this->states[$id] = WorldLifecycleState::LOADING;
            try {
                $handle = $this->runtimes->reserve($id);
                $prepared = ($this->loadRuntime)($handle);
                if ($prepared instanceof PendingManagedWorldRuntime) {
                    return new CallbackPolledWorldOperationExecutor(function () use ($id, $handle, $prepared): ?WorldOperationResult {
                        try {
                            $runtime = $prepared->poll();
                            if (!$runtime instanceof ManagedWorldRuntime) {
                                return null;
                            }
                            $this->runtimes->attach($handle, $runtime);
                            $this->states[$id] = WorldLifecycleState::LOADED;
                            $this->dispatch(new WorldLoadedEvent($runtime->handle));

                            return $this->success(WorldOperationType::LOAD, $runtime->handle);
                        } catch (Throwable $error) {
                            $this->cancelFailedPreparation($prepared);
                            $this->runtimes->abortReservation($handle);
                            unset($this->states[$id]);

                            return $this->exceptionFailure(WorldOperationType::LOAD, $id, $error);
                        }
                    });
                }
                $runtime = $this->runtimes->attach($handle, $prepared);
                $this->states[$id] = WorldLifecycleState::LOADED;
                $this->dispatch(new WorldLoadedEvent($runtime->handle));

                return $this->success(WorldOperationType::LOAD, $runtime->handle);
            } catch (Throwable $error) {
                if (isset($handle)) {
                    $this->runtimes->abortReservation($handle);
                }
                unset($this->states[$id]);

                return $this->exceptionFailure(WorldOperationType::LOAD, $id, $error);
            }
        });
    }

    public function save(World $world): WorldOperation
    {
        return $this->schedule(WorldOperationType::SAVE, $world->id(), function () use ($world): WorldOperationResult {
            try {
                $this->runtimes->assertCurrent($world);
            } catch (Throwable $error) {
                return $this->exceptionFailure(WorldOperationType::SAVE, $world->id(), $error, $world);
            }
            $event = new WorldSaveEvent($world);
            $this->dispatch($event);
            if ($event->isCancelled()) {
                return $this->cancelled(WorldOperationType::SAVE, $world->id(), $world);
            }
            try {
                $this->runtimes->save($world);
                $this->dispatch(new WorldSavedEvent($world));

                return $this->success(WorldOperationType::SAVE, $world);
            } catch (Throwable $error) {
                return $this->exceptionFailure(WorldOperationType::SAVE, $world->id(), $error, $world);
            }
        });
    }

    public function unload(World $world, WorldUnloadOptions $options = new WorldUnloadOptions()): WorldOperation
    {
        return $this->schedule(WorldOperationType::UNLOAD, $world->id(), function () use ($world, $options): WorldOperationResult {
            try {
                $this->runtimes->assertUnloadable($world);
            } catch (Throwable $error) {
                return $this->exceptionFailure(WorldOperationType::UNLOAD, $world->id(), $error, $world);
            }
            $event = new WorldUnloadEvent($world, $options);
            $this->dispatch($event);
            if ($event->isCancelled()) {
                return $this->cancelled(WorldOperationType::UNLOAD, $world->id(), $world);
            }
            $this->states[$world->id()] = WorldLifecycleState::UNLOADING;
            try {
                $this->runtimes->unload($world, $options->save);
                $this->states[$world->id()] = WorldLifecycleState::CLOSED;
                $this->dispatch(new WorldUnloadedEvent($world, $options));

                return $this->success(WorldOperationType::UNLOAD, $world);
            } catch (Throwable $error) {
                $this->states[$world->id()] = $this->runtimes->get($world->id()) === null
                    ? WorldLifecycleState::CLOSED
                    : WorldLifecycleState::LOADED;

                return $this->exceptionFailure(WorldOperationType::UNLOAD, $world->id(), $error, $world);
            }
        });
    }

    /** @param Closure(): (WorldOperationResult|PolledWorldOperationExecutor) $executor */
    private function schedule(WorldOperationType $type, string $id, Closure $executor): WorldOperation
    {
        $current = $this->pending[$id] ?? null;
        if ($current !== null && !$current[1]->state()->isTerminal()) {
            if ($current[0] === $type) {
                return $current[1];
            }

            return $this->operations->enqueue(
                $type,
                $id,
                fn(): WorldOperationResult => $this->failure(
                    $type,
                    $id,
                    WorldOperationFailureCode::BUSY,
                    'Another lifecycle operation is already in progress for this world.',
                ),
            );
        }
        $operation = $this->operations->enqueue($type, $id, $executor);
        $operation->onComplete(function () use ($id, $operation): void {
            if (($this->pending[$id][1] ?? null) === $operation) {
                unset($this->pending[$id]);
            }
        });

        $this->pending[$id] = [$type, $operation];

        return $operation;
    }

    private function dispatch(Event $event): void
    {
        ($this->dispatchEvent)?->__invoke($event);
    }

    private function success(WorldOperationType $type, World $world): WorldOperationResult
    {
        return new WorldOperationResult($type, WorldOperationState::SUCCEEDED, $world->id(), $world);
    }

    private function cancelled(WorldOperationType $type, string $id, ?World $world = null): WorldOperationResult
    {
        return new WorldOperationResult(
            $type,
            WorldOperationState::CANCELLED,
            $id,
            $world,
            new WorldOperationFailure(WorldOperationFailureCode::CANCELLED, 'World operation was cancelled.'),
        );
    }

    private function failure(
        WorldOperationType $type,
        string $id,
        WorldOperationFailureCode $code,
        string $message,
        ?World $world = null,
    ): WorldOperationResult {
        return new WorldOperationResult(
            $type,
            WorldOperationState::REJECTED,
            $id,
            $world,
            new WorldOperationFailure($code, $message),
        );
    }

    private function exceptionFailure(
        WorldOperationType $type,
        string $id,
        Throwable $error,
        ?World $world = null,
    ): WorldOperationResult {
        $code = match (true) {
            str_contains(strtolower($error->getMessage()), 'default world') => WorldOperationFailureCode::DEFAULT_WORLD,
            str_contains(strtolower($error->getMessage()), 'occupied') => WorldOperationFailureCode::OCCUPIED,
            str_contains(strtolower($error->getMessage()), 'in progress') => WorldOperationFailureCode::BUSY,
            str_contains(strtolower($error->getMessage()), 'not exist'),
            str_contains(strtolower($error->getMessage()), 'not found') => WorldOperationFailureCode::NOT_FOUND,
            str_contains(strtolower($error->getMessage()), 'already exist'),
            str_contains(strtolower($error->getMessage()), 'already loaded') => WorldOperationFailureCode::ALREADY_EXISTS,
            str_contains(strtolower($error->getMessage()), 'capacity') => WorldOperationFailureCode::CAPACITY,
            $error instanceof LogicException => WorldOperationFailureCode::STALE_HANDLE,
            default => WorldOperationFailureCode::STORAGE,
        };

        return $this->failure($type, $id, $code, 'World operation could not be completed.', $world);
    }

    private function cancelFailedPreparation(PendingManagedWorldRuntime $prepared): void
    {
        try {
            $prepared->cancel();
        } catch (Throwable) {
            // Cleanup failure must not escape the operation boundary or replace the startup failure.
        }
    }
}
