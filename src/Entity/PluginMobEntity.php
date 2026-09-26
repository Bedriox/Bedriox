<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\CustomMobBehavior;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Plugin\RegisteredCustomMobDefinition;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

/** @internal Authoritative runtime shell for a plugin-owned mob behavior. */
final class PluginMobEntity extends AbstractMobEntity
{
    private static ?AiBehaviorDefinition $emptyAi = null;

    private bool $pluginDespawnRequested = false;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        private readonly RegisteredCustomMobDefinition $pluginRegistration,
        private readonly CustomMobBehavior $customBehavior,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        if ($pluginRegistration->owner === '' || strlen($pluginRegistration->owner) > 128
            || preg_match('//u', $pluginRegistration->owner) !== 1) {
            throw new InvalidArgumentException('Plugin mob owner must be valid UTF-8 and bounded.');
        }
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            self::$emptyAi ??= new AiBehaviorDefinition(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }

    public function pluginOwner(): string
    {
        return $this->pluginRegistration->owner;
    }

    public function pluginRegistration(): RegisteredCustomMobDefinition
    {
        return $this->pluginRegistration;
    }

    public function customBehavior(): CustomMobBehavior
    {
        return $this->customBehavior;
    }

    public function requestPluginDespawn(): void
    {
        $this->pluginDespawnRequested = true;
    }

    public function pluginDespawnRequested(): bool
    {
        return $this->pluginDespawnRequested;
    }
}
