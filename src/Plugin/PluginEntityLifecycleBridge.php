<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\CustomEntityState;
use Bedriox\Api\Entity\CustomMobDespawnContext;
use Bedriox\Api\Entity\CustomMobSpawnContext;
use Bedriox\Api\Entity\CustomMobTickContext;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Server\Entity\PluginMobEntity;

/**
 * @internal Main-thread boundary for plugin mob lifecycle and persistent state.
 * False means the owner is unavailable or failed and the runtime should remove the mob.
 */
final readonly class PluginEntityLifecycleBridge
{
    public function __construct(private PluginEntityRegistrar $registrar) {}

    public function spawned(PluginMobEntity $entity, SpawnCause $cause): bool
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return false;
        }

        return $this->registrar->invokeSpawn(
            $entity->pluginRegistration(),
            $entity->customBehavior(),
            new CustomMobSpawnContext($entity, $cause),
        );
    }

    public function tick(PluginMobEntity $entity, int $currentTick): bool
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return false;
        }
        $context = new CustomMobTickContext(
            $entity,
            $currentTick,
            new BufferedCustomMobController($this->registrar->actions(), $entity, $entity->equipmentState()),
        );
        if (!$this->registrar->invokeTick($entity->pluginRegistration(), $entity->customBehavior(), $context)) {
            return false;
        }

        return true;
    }

    public function aiTick(PluginMobEntity $entity, int $currentTick): bool
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return false;
        }

        return $this->registrar->invokeAiTick(
            $entity->pluginRegistration(),
            $entity->customBehavior(),
            new CustomMobTickContext(
                $entity,
                $currentTick,
                new BufferedCustomMobController($this->registrar->actions(), $entity, $entity->equipmentState()),
            ),
        );
    }

    public function despawned(PluginMobEntity $entity): bool
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return false;
        }

        return $this->registrar->invokeDespawn(
            $entity->pluginRegistration(),
            $entity->customBehavior(),
            new CustomMobDespawnContext($entity),
        );
    }

    public function encodeState(PluginMobEntity $entity): ?CustomEntityState
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return null;
        }

        return $this->registrar->encodeState($entity->pluginRegistration(), $entity->customBehavior());
    }

    public function restoreState(PluginMobEntity $entity, CustomEntityState $state): bool
    {
        if (!$this->ownsCurrentDefinition($entity)) {
            return false;
        }

        return $this->registrar->restoreState(
            $entity->pluginRegistration(),
            $entity->customBehavior(),
            $state,
        );
    }

    public function isAvailable(PluginMobEntity $entity): bool
    {
        return $this->ownsCurrentDefinition($entity);
    }

    private function ownsCurrentDefinition(PluginMobEntity $entity): bool
    {
        return $this->registrar->isAvailable($entity->pluginRegistration());
    }
}
