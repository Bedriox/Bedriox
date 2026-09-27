<?php

declare(strict_types=1);

namespace Bedriox\Server\Plugin;

use Bedriox\Api\Entity\Entity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\EntityEquipment;
use Bedriox\Api\Entity\MobController;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\HorizontalSteering;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\PluginMobEntity;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use LogicException;

/** @internal Collects a bounded set of authoritative intents in the active plugin transaction. */
final class BufferedMobController implements MobController
{
    private const int MAXIMUM_INTENTS = 16;
    private const float MAXIMUM_STEERING_SPEED = 10.0;

    private int $intents = 0;

    private ?int $intentTransactionId = null;

    private readonly ?EntityEquipment $equipmentView;

    public function __construct(
        private readonly ?PluginActionBuffer $actions,
        private readonly AbstractMobEntity $entity,
        private readonly ?EntityEquipment $equipment = null,
        private readonly ?MobController $delegate = null,
    ) {
        $this->equipmentView = $equipment === null
            ? null
            : new BufferedEntityEquipment($equipment, function (callable $mutation): void {
                $this->stage(function () use ($mutation): void {
                    if (!$this->entity->isRemoved()) {
                        $mutation();
                    }
                });
            });
    }

    public function isAvailable(): bool
    {
        return !$this->entity->isRemoved() && ($this->delegate === null || $this->delegate->isAvailable());
    }

    public function teleport(ApiPosition $position, ?string $worldName = null): void
    {
        self::validatePosition($position);
        if ($worldName !== null && ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1)) {
            throw new InvalidArgumentException('Custom mob target world name must be valid UTF-8 and bounded.');
        }
        $this->stage(function () use ($position, $worldName): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->requestControllerTransform(
                    $worldName ?? $this->entity->getWorldName(),
                    self::position($position),
                    $this->entity->getYaw(),
                    $this->entity->getPitch(),
                );
            }
        });
    }

    public function setRotation(float $yaw, float $pitch): void
    {
        self::validateRotation($yaw, $pitch);
        $this->stage(function () use ($yaw, $pitch): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->requestControllerTransform(
                    $this->entity->getWorldName(),
                    $this->entity->internalPosition(),
                    $yaw,
                    $pitch,
                );
            }
        });
    }

    public function moveToward(ApiPosition $target, float $speed): void
    {
        self::validatePosition($target);
        self::validateSpeed($speed);
        $this->stage(function () use ($target, $speed): void {
            if (!$this->entity->isRemoved()) {
                HorizontalSteering::toward($this->entity, self::position($target), $speed);
            }
        });
    }

    public function moveAway(ApiPosition $target, float $speed): void
    {
        self::validatePosition($target);
        self::validateSpeed($speed);
        $this->stage(function () use ($target, $speed): void {
            if (!$this->entity->isRemoved()) {
                HorizontalSteering::away($this->entity, self::position($target), $speed);
            }
        });
    }

    public function stopMoving(): void
    {
        $this->stage(function (): void {
            if (!$this->entity->isRemoved()) {
                HorizontalSteering::stop($this->entity);
            }
        });
    }

    public function lookAt(ApiPosition $target): void
    {
        self::validatePosition($target);
        $this->stage(function () use ($target): void {
            if (!$this->entity->isRemoved()) {
                self::applyLook($this->entity, $target);
            }
        });
    }

    public function target(Entity $target, float $speed): void
    {
        $targetWorld = $target->getWorldName();
        $targetPosition = $target->getPosition();
        self::validatePosition($targetPosition);
        self::validateSpeed($speed);
        $this->stage(function () use ($targetWorld, $targetPosition, $speed): void {
            if ($this->entity->isRemoved() || $this->entity->getWorldName() !== $targetWorld) {
                return;
            }
            HorizontalSteering::toward($this->entity, self::position($targetPosition), $speed);
            self::applyLook($this->entity, $targetPosition);
        });
    }

    public function setVelocity(float $x, float $y, float $z): void
    {
        $motion = new EntityMotion($x, $y, $z);
        $this->stage(function () use ($motion): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setMotion($motion);
            }
        });
    }

    public function setNameTag(string $nameTag): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setNameTag($nameTag);

            return;
        }
        if (strlen($nameTag) > 256 || preg_match('//u', $nameTag) !== 1) {
            throw new InvalidArgumentException('Entity name tag must be valid UTF-8 and bounded.');
        }
        $this->stage(function () use ($nameTag): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setNameTag($nameTag);
            }
        });
    }

    public function setNameTagVisible(bool $visible): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setNameTagVisible($visible);

            return;
        }
        $this->stage(function () use ($visible): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setNameTagVisible($visible);
            }
        });
    }

    public function setImmobile(bool $immobile): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setImmobile($immobile);

            return;
        }
        $this->stage(function () use ($immobile): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setImmobile($immobile);
            }
        });
    }

    public function setInvisible(bool $invisible): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setInvisible($invisible);

            return;
        }
        $this->stage(function () use ($invisible): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setInvisible($invisible);
            }
        });
    }

    public function setGlowing(bool $glowing): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setGlowing($glowing);

            return;
        }
        $this->stage(function () use ($glowing): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setGlowing($glowing);
            }
        });
    }

    public function setScale(float $scale): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setScale($scale);

            return;
        }
        if (!is_finite($scale) || $scale < 0.01 || $scale > 16.0) {
            throw new InvalidArgumentException('Entity scale must be finite and between 0.01 and 16.');
        }
        $this->stage(function () use ($scale): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setScale($scale);
            }
        });
    }

    public function setGravityEnabled(bool $enabled): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setGravityEnabled($enabled);

            return;
        }
        $this->stage(function () use ($enabled): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setGravityEnabled($enabled);
            }
        });
    }

    public function setOnFire(
        int $durationTicks,
        EntityCombustionCause $cause = EntityCombustionCause::PLUGIN,
    ): void {
        if ($this->delegate !== null) {
            $this->delegate->setOnFire($durationTicks, $cause);

            return;
        }
        $this->stage(function () use ($durationTicks, $cause): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->requestControllerCombust($durationTicks, $cause);
            }
        });
    }

    public function extinguish(): void
    {
        if ($this->delegate !== null) {
            $this->delegate->extinguish();

            return;
        }
        $this->stage(function (): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->extinguish();
            }
        });
    }

    public function damage(
        float $amount,
        EntityDamageCause $cause = EntityDamageCause::PLUGIN,
        ?Entity $source = null,
    ): void {
        if ($this->delegate !== null) {
            $this->delegate->damage($amount, $cause, $source);

            return;
        }
        self::validateHealthAmount($amount, 'damage');
        $this->stage(function () use ($amount, $cause, $source): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->requestControllerDamage($amount, $cause, $source);
            }
        });
    }

    public function heal(float $amount): void
    {
        if ($this->delegate !== null) {
            $this->delegate->heal($amount);

            return;
        }
        self::validateHealthAmount($amount, 'healing');
        $this->stage(function () use ($amount): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->heal($amount);
            }
        });
    }

    public function setHealth(float $health): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setHealth($health);

            return;
        }
        if (!is_finite($health) || $health < 0.0 || $health > $this->entity->getMaximumHealth()) {
            throw new InvalidArgumentException('Entity health is outside its supported bounds.');
        }
        $this->stage(function () use ($health): void {
            if ($this->entity->isRemoved()) {
                return;
            }
            $current = $this->entity->getHealth();
            if ($health < $current) {
                $this->entity->requestControllerDamage(
                    $current - $health,
                    EntityDamageCause::PLUGIN,
                    null,
                );
            } elseif ($health > $current) {
                $this->entity->heal($health - $current);
            }
        });
    }

    public function equipment(): EntityEquipment
    {
        return $this->equipmentView ?? $this->fullController()->equipment();
    }

    public function setAiEnabled(bool $enabled): void
    {
        if ($this->delegate !== null) {
            $this->delegate->setAiEnabled($enabled);

            return;
        }
        $this->stage(function () use ($enabled): void {
            if (!$this->entity->isRemoved()) {
                $this->entity->setAiEnabled($enabled);
            }
        });
    }

    public function clearTarget(): void
    {
        if ($this->delegate !== null) {
            $this->delegate->clearTarget();

            return;
        }
        $this->stopMoving();
    }

    public function despawn(): void
    {
        $this->stage(function (): void {
            if (!$this->entity->isRemoved()) {
                if ($this->entity instanceof PluginMobEntity) {
                    $this->entity->requestPluginDespawn();
                } else {
                    $this->entity->requestControllerDespawn();
                }
            }
        });
    }

    /** @param callable(): void $intent */
    private function stage(callable $intent): void
    {
        $transactionId = $this->actions?->currentTransactionId();
        if ($transactionId === null) {
            $intent();

            return;
        }
        if ($this->intentTransactionId !== $transactionId) {
            $this->intentTransactionId = $transactionId;
            $this->intents = 0;
        }
        if (++$this->intents > self::MAXIMUM_INTENTS) {
            throw new PluginException('Custom mob control-intent limit exceeded for one plugin callback.');
        }
        $this->actions->stage($intent);
    }

    private function fullController(): MobController
    {
        return $this->delegate ?? throw new LogicException(
            'This custom-mob controller is not attached to the complete authoritative controller runtime.',
        );
    }

    private static function validatePosition(ApiPosition $position): void
    {
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Custom mob control target is outside world bounds.');
        }
    }

    private static function validateSpeed(float $speed): void
    {
        if (!is_finite($speed) || $speed < 0.0 || $speed > self::MAXIMUM_STEERING_SPEED) {
            throw new InvalidArgumentException('Custom mob steering speed is outside its supported bounds.');
        }
    }

    private static function validateRotation(float $yaw, float $pitch): void
    {
        if (!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Custom mob rotation must be finite and bounded.');
        }
    }

    private static function validateHealthAmount(float $amount, string $kind): void
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException(sprintf('Entity %s is outside its supported bounds.', $kind));
        }
    }

    private static function position(ApiPosition $position): Position
    {
        return new Position($position->x, $position->y, $position->z);
    }

    private static function applyLook(AbstractMobEntity $entity, ApiPosition $target): void
    {
        $position = $entity->internalPosition();
        $x = $target->x - $position->x;
        $y = $target->y - $position->y;
        $z = $target->z - $position->z;
        $horizontal = hypot($x, $z);
        if ($horizontal < 0.000_001 && abs($y) < 0.000_001) {
            return;
        }
        $entity->requestControllerTransform(
            $entity->getWorldName(),
            $position,
            $horizontal < 0.000_001 ? $entity->getYaw() : rad2deg(atan2(-$x, $z)),
            max(-90.0, min(90.0, -rad2deg(atan2($y, $horizontal)))),
        );
    }
}
