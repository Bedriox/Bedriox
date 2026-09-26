<?php

declare(strict_types=1);

namespace Bedriox\Api\Entity;

/**
 * Plugin-owned behavior attached to one server-owned custom mob.
 * General mutations use the ordinary bounded server API captured by the plugin;
 * per-tick mob control uses CustomMobTickContext::$controller.
 */
abstract class CustomMobBehavior
{
    public function onSpawn(CustomMobSpawnContext $context): void {}

    public function onTick(CustomMobTickContext $context): void {}

    public function onAiTick(CustomMobTickContext $context): void {}

    public function onDespawn(CustomMobDespawnContext $context): void {}
}
