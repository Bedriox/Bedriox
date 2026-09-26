<?php

declare(strict_types=1);

namespace Bedriox\Server\Entity;

use Bedriox\Api\Entity\Mob as ApiMob;
use Bedriox\Api\Entity\MobActivationState;
use Bedriox\Api\Entity\MobController;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\AiBehaviorRuntime;
use Bedriox\Server\Entity\Ai\AiTickContext;
use Bedriox\Server\Entity\Ai\AiTickResult;
use Bedriox\Server\Plugin\BufferedCustomMobController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use InvalidArgumentException;

abstract class AbstractMobEntity extends AbstractLivingEntity implements ApiMob
{
    private MobActivationState $activationState = MobActivationState::ACTIVE;

    private readonly AiBehaviorRuntime $ai;

    private int $aiMovementSuppressedUntilTick = -1;

    private int $lastAiMovementIntentTick = -1;

    private bool $aiEnabled = true;

    private MobController $controller;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        \Bedriox\Server\Simulation\Position $position,
        AiBehaviorDefinition $behavior,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $definition,
            $worldName,
            $position,
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->ai = new AiBehaviorRuntime($behavior);
        $this->controller = new BufferedCustomMobController(null, $this, $this->equipmentState());
    }

    final public function getController(): MobController
    {
        return $this->controller;
    }

    /** @internal Rebinds the controller to the active plugin transaction boundary. */
    final public function attachController(?PluginActionBuffer $actions): void
    {
        $this->controller = new BufferedCustomMobController($actions, $this, $this->equipmentState());
    }

    final public function getActivationState(): MobActivationState
    {
        return $this->activationState;
    }

    final public function setActivationState(MobActivationState $state, ?AiTickContext $context = null): void
    {
        if ($state === MobActivationState::SLEEPING && $context !== null) {
            $this->ai->stopAll($this, $context);
        }
        $this->activationState = $state;
    }

    final public function tickAi(AiTickContext $context, bool $enabled): AiTickResult
    {
        if (!$enabled || !$this->aiEnabled || $this->isImmobile()
            || $this->activationState === MobActivationState::SLEEPING) {
            $this->ai->stopAll($this, $context);

            return new AiTickResult(0, 0, 0);
        }

        return $this->ai->tick($this, $context);
    }

    final public function isAiEnabled(): bool
    {
        return $this->aiEnabled;
    }

    /** @internal Authoritative controller mutation. */
    final public function setAiEnabled(bool $enabled): void
    {
        if ($this->aiEnabled !== $enabled) {
            $this->aiEnabled = $enabled;
            if (!$enabled) {
                $this->setMotion(new EntityMotion());
            }
            $this->markPresentationChanged();
        }
    }

    final public function aiRuntime(): AiBehaviorRuntime
    {
        return $this->ai;
    }

    /** Prevents movement goals from replacing an authoritative external impulse such as knockback. */
    final public function suppressAiMovementUntil(int $tick): void
    {
        if ($tick < 0) {
            throw new InvalidArgumentException('AI movement suppression tick cannot be negative.');
        }
        $this->aiMovementSuppressedUntilTick = max($this->aiMovementSuppressedUntilTick, $tick);
    }

    /** Applies one movement-goal decision and records it for obstacle-aware entity physics. */
    final public function applyAiMotion(EntityMotion $motion, int $tick): bool
    {
        if ($tick < 0) {
            throw new InvalidArgumentException('AI movement intent tick cannot be negative.');
        }
        if ($tick < $this->aiMovementSuppressedUntilTick) {
            return false;
        }
        $this->lastAiMovementIntentTick = $tick;
        $this->setMotion($motion);

        return true;
    }

    final public function hasAiMovementIntentAt(int $tick): bool
    {
        return $tick >= 0 && $this->lastAiMovementIntentTick === $tick;
    }
}
