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

namespace Bedriox\Server\Entity\Spawn;

use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Event\Entity\EntitySpawnedEvent;
use Bedriox\Api\Event\Entity\EntitySpawnEvent;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityDefinitionRegistry;
use Bedriox\Server\Entity\EntityDespawnPolicy;
use Bedriox\Server\Entity\EntityRegistry;
use Closure;
use LogicException;
use Throwable;

/** One authoritative spawn transaction; projection happens only after success. */
final readonly class EntitySpawnService
{
    /**
     * @param null|Closure(string, int, int): bool $chunkLoaded world, chunk X, chunk Z
     * @param null|Closure(EntityDefinition, \Bedriox\Server\Simulation\Position): bool $collisionFree
     * @param null|Closure(EntitySpawnEvent): bool $beforeSpawn returns false when cancelled
     * @param null|Closure(EntitySpawnedEvent): void $afterSpawn
     * @param null|Closure(\Bedriox\Server\Entity\AbstractEntity, \Bedriox\Api\Entity\SpawnCause): bool $admitSpawn
     * @param null|Closure(\Bedriox\Server\Entity\AbstractEntity): void $prepareEntity
     */
    public function __construct(
        private EntityRegistry $entities,
        private EntityDefinitionRegistry $definitions,
        private ?Closure $chunkLoaded = null,
        private ?Closure $collisionFree = null,
        private ?Closure $beforeSpawn = null,
        private ?Closure $afterSpawn = null,
        private ?Closure $admitSpawn = null,
        private ?Closure $prepareEntity = null,
    ) {}

    public function spawn(EntitySpawnRequest $request): EntitySpawnOutcome
    {
        $registration = $this->definitions->get($request->type);
        if ($registration === null) {
            return EntitySpawnOutcome::failed('unsupported_type');
        }
        $chunkX = (int) floor($request->position->x / 16.0);
        $chunkZ = (int) floor($request->position->z / 16.0);
        if ($this->chunkLoaded !== null && !($this->chunkLoaded)($request->worldName, $chunkX, $chunkZ)) {
            return EntitySpawnOutcome::failed('chunk_unavailable');
        }
        if (self::requiresCollisionClearance($request->cause)
            && $this->collisionFree !== null
            && !($this->collisionFree)($registration->definition, $request->position)) {
            return EntitySpawnOutcome::failed('collision');
        }
        if (!$this->entities->canSpawn()) {
            return EntitySpawnOutcome::failed('capacity');
        }

        $entity = null;
        try {
            $entity = $this->entities->spawn(
                fn(string $uuid, int $runtimeId) => ($registration->factory)(
                    $uuid,
                    $runtimeId,
                    $request->worldName,
                    $request->position,
                    $request->yaw,
                    $request->pitch,
                ),
                $request->uniqueId,
            );
            if ($entity->getType()->identifier() !== $registration->definition->type->identifier()
                || $entity->getCategory() !== $registration->definition->category
                || $entity->definition() !== $registration->definition) {
                $this->entities->remove($entity->getRuntimeId());
                throw new LogicException('Entity factory returned an entity which does not match its definition.');
            }
            $entity->restoreSpawnOwnership($request->cause, EntityDespawnPolicy::forSpawnCause($request->cause));
            if ($request->variant !== null) {
                if (!$entity instanceof SpawnVariantAware) {
                    $this->entities->remove($entity->getRuntimeId());

                    return EntitySpawnOutcome::failed('unsupported_variant');
                }
                $entity->applySpawnVariant($request->variant);
            }
            ($this->prepareEntity)?->__invoke($entity);
            $event = new EntitySpawnEvent($entity, $request->cause);
            if ($this->beforeSpawn !== null && !($this->beforeSpawn)($event)) {
                $this->entities->remove($entity->getRuntimeId());

                return EntitySpawnOutcome::failed('cancelled');
            }
            if ($this->admitSpawn !== null && !($this->admitSpawn)($entity, $request->cause)) {
                $this->entities->remove($entity->getRuntimeId());

                return EntitySpawnOutcome::failed('admission_rejected');
            }
        } catch (Throwable) {
            if ($entity !== null && $this->entities->getByRuntimeId($entity->getRuntimeId()) === $entity) {
                $this->entities->remove($entity->getRuntimeId());
            }

            return EntitySpawnOutcome::failed('invalid_state');
        }
        try {
            ($this->afterSpawn)?->__invoke(new EntitySpawnedEvent($entity, $request->cause));
        } catch (Throwable) {
            // The entity is already authoritative. Public event dispatchers isolate listener failures.
        }

        return EntitySpawnOutcome::success($entity);
    }

    private static function requiresCollisionClearance(SpawnCause $cause): bool
    {
        return match ($cause) {
            SpawnCause::NATURAL,
            SpawnCause::SPAWNER,
            SpawnCause::BREEDING,
            SpawnCause::STRUCTURE,
            SpawnCause::BUCKET,
            SpawnCause::EFFECT,
            SpawnCause::ITEM => true,
            SpawnCause::SPAWN_EGG,
            SpawnCause::COMMAND,
            SpawnCause::PLUGIN,
            SpawnCause::CHUNK_LOAD,
            SpawnCause::TRANSFORMATION => false,
        };
    }
}
