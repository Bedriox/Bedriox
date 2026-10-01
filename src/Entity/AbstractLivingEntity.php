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

namespace Bedriox\Server\Entity;

use Bedriox\Api\Effect\EffectCause;
use Bedriox\Api\Effect\EffectInstance;
use Bedriox\Api\Effect\EffectManager;
use Bedriox\Api\Effect\EffectType;
use Bedriox\Api\Entity\Controller\LivingEntityController;
use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\LivingEntity as ApiLivingEntity;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Server\Effect\ActiveEffectCollection;
use Bedriox\Server\Effect\ActiveEffectTransition;
use Bedriox\Server\Effect\VanillaEffectBehavior;
use Bedriox\Server\Entity\Equipment\EntityEquipment;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Closure;
use InvalidArgumentException;

abstract class AbstractLivingEntity extends AbstractEntity implements ApiLivingEntity
{
    public const int MAXIMUM_FIRE_TICKS = 0x7fff;

    private float $health;

    private int $fireTicks = 0;

    private float $absorption = 0.0;

    private readonly EntityEquipment $equipment;

    private readonly ActiveEffectCollection $effects;

    /** @var array<string, EquipmentSlot> */
    private array $equipmentChanges = [];

    /** @var list<ActiveEffectTransition> */
    private array $effectChanges = [];

    /** @var null|Closure(float, EntityDamageCause, ?ApiEntity): void */
    private ?Closure $controllerDamageHandler = null;

    /** @var null|Closure(int, EntityCombustionCause): void */
    private ?Closure $controllerCombustHandler = null;

    /** @var null|Closure(EffectInstance, EffectCause): void */
    private ?Closure $controllerEffectAddHandler = null;

    /** @var null|Closure(EffectType, EffectCause): void */
    private ?Closure $controllerEffectRemoveHandler = null;

    /** @var null|Closure(EffectCause): void */
    private ?Closure $controllerEffectClearHandler = null;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        \Bedriox\Server\Simulation\Position $position,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct($uniqueId, $runtimeId, $definition, $worldName, $position, $motion, $yaw, $pitch);
        $this->health = $health ?? $definition->maximumHealth;
        if (!is_finite($this->health) || $this->health < 0.0 || $this->health > $definition->maximumHealth) {
            throw new InvalidArgumentException('Entity health is outside its supported bounds.');
        }
        $this->equipment = new EntityEquipment(function (EquipmentSlot $slot): void {
            $this->equipmentChanges[$slot->value] = $slot;
            $this->markChanged();
        });
        $this->effects = new ActiveEffectCollection();
    }

    final public function getEffects(): EffectManager
    {
        $controller = $this->getController();
        if (!$controller instanceof LivingEntityController) {
            throw new \LogicException('A living entity must expose a living-entity controller.');
        }

        return $controller->effects();
    }

    /** @internal Applies an effect through authoritative staged controller work. */
    final public function addEffect(\Bedriox\Api\Effect\EffectInstance $effect): void
    {
        $transition = $this->effects->add($effect);
        if ($transition->visibleStateChanged) {
            $this->effectChanges[] = $transition;
            if ($effect->type === \Bedriox\Api\Effect\EffectType::ABSORPTION) {
                $this->absorption = max(
                    $this->absorption,
                    VanillaEffectBehavior::absorptionCapacity($this->effects->snapshot()),
                );
            }
            if ($effect->type === \Bedriox\Api\Effect\EffectType::FIRE_RESISTANCE) {
                $this->extinguish();
            }
            $this->markChanged();
        }
    }

    /** @internal Removes an effect through authoritative staged controller work. */
    final public function removeEffect(\Bedriox\Api\Effect\EffectType $type): void
    {
        $transition = $this->effects->remove($type);
        if ($transition->visibleStateChanged) {
            $this->effectChanges[] = $transition;
            if ($type === \Bedriox\Api\Effect\EffectType::ABSORPTION) {
                $this->absorption = 0.0;
            }
            if ($type === \Bedriox\Api\Effect\EffectType::HEALTH_BOOST) {
                $this->health = min($this->health, $this->getMaximumHealth());
            }
            $this->markChanged();
        }
    }

    /** @internal Clears effects through authoritative staged controller work. */
    final public function clearEffects(): void
    {
        $transitions = $this->effects->clear();
        if ($transitions !== []) {
            foreach ($transitions as $transition) {
                $this->effectChanges[] = $transition;
            }
            $this->absorption = 0.0;
            $this->health = min($this->health, $this->getMaximumHealth());
            $this->markChanged();
        }
    }

    /** @internal Authoritative effect state owned by this living entity. */
    final public function effectState(): ActiveEffectCollection
    {
        return $this->effects;
    }

    /**
     * Drains visible effect transitions since the previous authoritative projection.
     *
     * @return list<ActiveEffectTransition>
     */
    final public function drainEffectChanges(): array
    {
        $changes = $this->effectChanges;
        $this->effectChanges = [];

        return $changes;
    }

    /**
     * @param list<ActiveEffectTransition> $transitions
     * @internal Records expirations and hidden-effect promotions produced by simulation ticking.
     */
    final public function recordEffectTransitions(array $transitions): void
    {
        foreach ($transitions as $transition) {
            if ($transition->visibleStateChanged) {
                $this->effectChanges[] = $transition;
                $type = $transition->previous !== null
                    ? $transition->previous->type
                    : $transition->current?->type;
                if ($type === \Bedriox\Api\Effect\EffectType::ABSORPTION) {
                    $this->absorption = VanillaEffectBehavior::absorptionCapacity($this->effects->snapshot());
                }
                if ($type === \Bedriox\Api\Effect\EffectType::HEALTH_BOOST) {
                    $this->health = min($this->health, $this->getMaximumHealth());
                }
            }
        }
    }

    /** @internal Authoritative equipment owned by this living entity. */
    final public function equipmentState(): EntityEquipment
    {
        return $this->equipment;
    }

    /** @internal Enables validation against the active server item catalog. */
    final public function configureEquipmentCatalog(ItemCatalog $catalog): void
    {
        $this->equipment->configureCatalog($catalog);
    }

    /**
     * Drains equipment slots changed since the previous authoritative projection.
     *
     * @return list<EquipmentSlot>
     */
    final public function drainEquipmentChanges(): array
    {
        $changes = array_values($this->equipmentChanges);
        $this->equipmentChanges = [];

        return $changes;
    }

    /** @param null|Closure(float, EntityDamageCause, ?ApiEntity): void $handler */
    final public function configureControllerDamageHandler(?Closure $handler): void
    {
        $this->controllerDamageHandler = $handler;
    }

    /** @internal Routes public-controller damage through the authoritative simulation when attached. */
    final public function requestControllerDamage(
        float $amount,
        EntityDamageCause $cause,
        ?ApiEntity $source,
    ): void {
        if ($this->controllerDamageHandler !== null) {
            ($this->controllerDamageHandler)($amount, $cause, $source);

            return;
        }
        $this->damage($amount);
    }

    /** @param null|Closure(int, EntityCombustionCause): void $handler */
    final public function configureControllerCombustHandler(?Closure $handler): void
    {
        $this->controllerCombustHandler = $handler;
    }

    /** @param null|Closure(EffectInstance, EffectCause): void $handler */
    final public function configureControllerEffectAddHandler(?Closure $handler): void
    {
        $this->controllerEffectAddHandler = $handler;
    }

    /** @param null|Closure(EffectType, EffectCause): void $handler */
    final public function configureControllerEffectRemoveHandler(?Closure $handler): void
    {
        $this->controllerEffectRemoveHandler = $handler;
    }

    /** @param null|Closure(EffectCause): void $handler */
    final public function configureControllerEffectClearHandler(?Closure $handler): void
    {
        $this->controllerEffectClearHandler = $handler;
    }

    /** @internal Routes public-controller effect addition through authoritative simulation. */
    final public function requestControllerEffectAdd(EffectInstance $effect, EffectCause $cause): void
    {
        if ($this->controllerEffectAddHandler !== null) {
            ($this->controllerEffectAddHandler)($effect, $cause);
            return;
        }
        $this->addEffect($effect);
    }

    /** @internal Routes public-controller effect removal through authoritative simulation. */
    final public function requestControllerEffectRemove(EffectType $type, EffectCause $cause): void
    {
        if ($this->controllerEffectRemoveHandler !== null) {
            ($this->controllerEffectRemoveHandler)($type, $cause);
            return;
        }
        $this->removeEffect($type);
    }

    /** @internal Routes public-controller effect clearing through authoritative simulation. */
    final public function requestControllerEffectClear(EffectCause $cause): void
    {
        if ($this->controllerEffectClearHandler !== null) {
            ($this->controllerEffectClearHandler)($cause);
            return;
        }
        $this->clearEffects();
    }

    /** @internal Routes public-controller combustion through the authoritative simulation when attached. */
    final public function requestControllerCombust(int $durationTicks, EntityCombustionCause $cause): void
    {
        if ($this->controllerCombustHandler !== null) {
            ($this->controllerCombustHandler)($durationTicks, $cause);

            return;
        }
        $this->setOnFire($durationTicks);
    }

    final public function getHealth(): float
    {
        return $this->health;
    }

    final public function getMaximumHealth(): float
    {
        return $this->definition->maximumHealth
            + VanillaEffectBehavior::maximumHealth($this->effects->snapshot())
            - \Bedriox\Server\Player\PlayerVitals::MAX_HEALTH;
    }

    final public function getAbsorption(): float
    {
        return $this->absorption;
    }

    final public function isAlive(): bool
    {
        return !$this->isRemoved() && $this->health > 0.0;
    }

    final public function isOnFire(): bool
    {
        return $this->fireTicks > 0;
    }

    final public function getFireTicks(): int
    {
        return $this->fireTicks;
    }

    /** @internal Authoritative simulation fire-state mutation. */
    final public function setOnFire(int $durationTicks): void
    {
        if ($durationTicks < 0 || $durationTicks > self::MAXIMUM_FIRE_TICKS) {
            throw new InvalidArgumentException('Entity fire duration is outside its supported bounds.');
        }
        if (VanillaEffectBehavior::hasFireResistance($this->effects->snapshot())) {
            $this->extinguish();
            return;
        }
        if ($durationTicks > $this->fireTicks) {
            $wasOnFire = $this->isOnFire();
            $this->fireTicks = $durationTicks;
            if (!$wasOnFire) {
                $this->markPresentationChanged();
            }
        }
    }

    /** @internal Returns true when one point of fire-tick damage is due. */
    final public function advanceFireTick(): bool
    {
        if ($this->fireTicks === 0) {
            return false;
        }
        --$this->fireTicks;
        $damageDue = $this->fireTicks % 20 === 0;
        if ($damageDue || $this->fireTicks === 0) {
            if ($this->fireTicks === 0) {
                $this->markPresentationChanged();
            } else {
                $this->markChanged();
            }
        }

        return $damageDue;
    }

    /** @internal Authoritative simulation fire-state mutation. */
    final public function extinguish(): void
    {
        if ($this->fireTicks > 0) {
            $this->fireTicks = 0;
            $this->markPresentationChanged();
        }
    }

    /** @internal Persistence hydration before the entity becomes observable. */
    final public function restoreFireTicks(int $fireTicks): void
    {
        if ($fireTicks < 0 || $fireTicks > self::MAXIMUM_FIRE_TICKS || $this->fireTicks !== 0) {
            throw new InvalidArgumentException('Entity fire hydration is invalid.');
        }
        $this->fireTicks = $fireTicks;
    }

    final public function damage(float $amount): float
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Entity damage is outside its supported bounds.');
        }
        $absorbed = min($amount, $this->absorption);
        $this->absorption -= $absorbed;
        $healthDamage = min($amount - $absorbed, $this->health);
        $this->health -= $healthDamage;
        $applied = $absorbed + $healthDamage;
        if ($applied > 0.0) {
            $this->markChanged();
        }

        return $applied;
    }

    final public function heal(float $amount): float
    {
        if (!is_finite($amount) || $amount < 0.0 || $amount > 1_000_000.0) {
            throw new InvalidArgumentException('Entity healing is outside its supported bounds.');
        }
        $applied = min($amount, $this->getMaximumHealth() - $this->health);
        $this->health += $applied;
        if ($applied > 0.0) {
            $this->markChanged();
        }

        return $applied;
    }
}
