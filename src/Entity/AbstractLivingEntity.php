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

use Bedriox\Api\Entity\Entity as ApiEntity;
use Bedriox\Api\Entity\EntityCombustionCause;
use Bedriox\Api\Entity\EntityDamageCause;
use Bedriox\Api\Entity\LivingEntity as ApiLivingEntity;
use Bedriox\Api\Inventory\EquipmentSlot;
use Bedriox\Server\Entity\Equipment\EntityEquipment;
use Bedriox\Server\Gameplay\Item\ItemCatalog;
use Closure;
use InvalidArgumentException;

abstract class AbstractLivingEntity extends AbstractEntity implements ApiLivingEntity
{
    public const int MAXIMUM_FIRE_TICKS = 0x7fff;

    private float $health;

    private int $fireTicks = 0;

    private readonly EntityEquipment $equipment;

    /** @var array<string, EquipmentSlot> */
    private array $equipmentChanges = [];

    /** @var null|Closure(float, EntityDamageCause, ?ApiEntity): void */
    private ?Closure $controllerDamageHandler = null;

    /** @var null|Closure(int, EntityCombustionCause): void */
    private ?Closure $controllerCombustHandler = null;

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
        return $this->definition->maximumHealth;
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
        $applied = min($amount, $this->health);
        $this->health -= $applied;
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
        $applied = min($amount, $this->definition->maximumHealth - $this->health);
        $this->health += $applied;
        if ($applied > 0.0) {
            $this->markChanged();
        }

        return $applied;
    }
}
