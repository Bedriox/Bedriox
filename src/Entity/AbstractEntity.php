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
use Bedriox\Api\Entity\EntityCategory;
use Bedriox\Api\Entity\EntityType;
use Bedriox\Api\Entity\MountedPassenger;
use Bedriox\Api\Entity\SpawnCause;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\World\Position as ApiPosition;
use Bedriox\Server\Entity\Mount\MountSeatOffset;
use Bedriox\Server\Simulation\Position;
use Closure;
use InvalidArgumentException;
use LogicException;

abstract class AbstractEntity implements ApiEntity
{
    private readonly string $uniqueId;

    private bool $removed = false;

    private bool $controllerDespawnRequested = false;

    private bool $onGround = false;

    private int $ageTicks = 0;

    private int $revision = 0;

    private SpawnCause $spawnOrigin = SpawnCause::PLUGIN;

    private EntityDespawnPolicy $despawnPolicy = EntityDespawnPolicy::EXPLICIT_ONLY;

    private string $nameTag = '';

    private bool $nameTagVisible = false;

    private bool $immobile = false;

    private bool $invisible = false;

    private bool $glowing = false;

    private bool $gravityEnabled = true;

    private float $scale = 1.0;

    private int $presentationRevision = 0;

    /** @var null|Closure(): ?ApiEntity */
    private ?Closure $vehicleResolver = null;

    /** @var null|Closure(): list<MountedPassenger> */
    private ?Closure $passengerResolver = null;

    private ?Closure $controllerMountHandler = null;

    private ?Closure $controllerDismountHandler = null;

    /** @var null|Closure(string, Position, float, float): void */
    private ?Closure $controllerTransformHandler = null;

    public function __construct(
        string $uniqueId,
        private readonly int $runtimeId,
        protected readonly EntityDefinition $definition,
        private string $worldName,
        protected Position $position,
        protected EntityMotion $motion = new EntityMotion(),
        protected float $yaw = 0.0,
        protected float $pitch = 0.0,
    ) {
        $this->uniqueId = EntityUuid::validate($uniqueId);
        if ($runtimeId < 1 || $runtimeId >= PHP_INT_MAX) {
            throw new InvalidArgumentException('Entity runtime ID must be positive and bounded.');
        }
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('Entity world name must be valid UTF-8 and bounded.');
        }
        self::validatePosition($position);
        self::validateRotation($yaw, $pitch);
    }

    final public function getUniqueId(): string
    {
        return $this->uniqueId;
    }

    final public function getRuntimeId(): int
    {
        return $this->runtimeId;
    }

    final public function getType(): EntityType
    {
        return $this->definition->type;
    }

    final public function getCategory(): EntityCategory
    {
        return $this->definition->category;
    }

    final public function definition(): EntityDefinition
    {
        return $this->definition;
    }

    final public function getPosition(): ApiPosition
    {
        return new ApiPosition($this->position->x, $this->position->y, $this->position->z);
    }

    final public function internalPosition(): Position
    {
        return $this->position;
    }

    final public function getMotion(): EntityMotion
    {
        return $this->motion;
    }

    final public function getYaw(): float
    {
        return $this->yaw;
    }

    final public function getPitch(): float
    {
        return $this->pitch;
    }

    final public function getWorldName(): string
    {
        return $this->worldName;
    }

    final public function isPersistent(): bool
    {
        return $this->definition->persistent;
    }

    final public function getVehicle(): ?ApiEntity
    {
        return $this->vehicleResolver === null ? null : ($this->vehicleResolver)();
    }

    final public function isRiding(): bool
    {
        return $this->getVehicle() !== null;
    }

    public function getPassengers(): array
    {
        return $this->passengerResolver === null ? [] : ($this->passengerResolver)();
    }

    final public function hasPassengers(): bool
    {
        return $this->getPassengers() !== [];
    }

    /**
     * @internal MountRegistry remains the single relationship owner.
     * @param Closure(): ?ApiEntity $vehicle
     * @param Closure(): list<MountedPassenger> $passengers
     */
    final public function bindMountView(Closure $vehicle, Closure $passengers): void
    {
        $this->vehicleResolver = $vehicle;
        $this->passengerResolver = $passengers;
    }

    /** @param null|Closure(ApiEntity, MountSeat): void $mount */
    final public function configureControllerMountHandlers(?Closure $mount, ?Closure $dismount): void
    {
        $this->controllerMountHandler = $mount;
        $this->controllerDismountHandler = $dismount;
    }

    /** @internal */
    final public function requestControllerMount(ApiEntity $vehicle, MountSeat $seat): void
    {
        if ($this->controllerMountHandler === null) {
            throw new LogicException('Entity mounting is unavailable outside an authoritative world.');
        }
        ($this->controllerMountHandler)($vehicle, $seat);
    }

    /** @internal */
    final public function requestControllerDismount(): void
    {
        if ($this->controllerDismountHandler === null) {
            throw new LogicException('Entity dismounting is unavailable outside an authoritative world.');
        }
        ($this->controllerDismountHandler)();
    }

    final public function isOnGround(): bool
    {
        return $this->onGround;
    }

    final public function ageTicks(): int
    {
        return $this->ageTicks;
    }

    final public function revision(): int
    {
        return $this->revision;
    }

    final public function spawnOrigin(): SpawnCause
    {
        return $this->spawnOrigin;
    }

    final public function despawnPolicy(): EntityDespawnPolicy
    {
        return $this->despawnPolicy;
    }

    final public function nameTag(): string
    {
        return $this->nameTag;
    }

    final public function isNameTagVisible(): bool
    {
        return $this->nameTagVisible;
    }

    final public function isImmobile(): bool
    {
        return $this->immobile;
    }

    final public function isInvisible(): bool
    {
        return $this->invisible;
    }

    final public function isGlowing(): bool
    {
        return $this->glowing;
    }

    final public function isGravityEnabled(): bool
    {
        return $this->gravityEnabled;
    }

    final public function scale(): float
    {
        return $this->visualSizeMultiplier() * $this->scale;
    }

    final public function collisionWidth(): float
    {
        return $this->definition->width * $this->sizeMultiplier() * $this->scale;
    }

    final public function collisionHeight(): float
    {
        return $this->definition->height * $this->sizeMultiplier() * $this->scale;
    }

    /** @internal Resolves the Bedrock seat offset carried by passenger actor metadata. */
    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return $playerPassenger
            ? ($passengerHeight * 0.9) * 0.753_086_42
            : -$passengerHeight * 0.246_913_58;
    }

    /** @internal Resolves the local-space Bedrock seat attachment carried by passenger actor metadata. */
    public function mountedPassengerOffset(MountSeat $seat, float $passengerHeight, bool $playerPassenger): MountSeatOffset
    {
        return new MountSeatOffset(
            0.0,
            $this->mountedPassengerOffsetY($seat, $passengerHeight, $playerPassenger),
            0.0,
        );
    }

    final public function presentationRevision(): int
    {
        return $this->presentationRevision;
    }

    /** @internal Authoritative controller mutation. */
    final public function setNameTag(string $nameTag): void
    {
        if (strlen($nameTag) > 256 || preg_match('//u', $nameTag) !== 1) {
            throw new InvalidArgumentException('Entity name tag must be valid UTF-8 and bounded.');
        }
        if ($this->nameTag !== $nameTag) {
            $this->nameTag = $nameTag;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setNameTagVisible(bool $visible): void
    {
        if ($this->nameTagVisible !== $visible) {
            $this->nameTagVisible = $visible;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setImmobile(bool $immobile): void
    {
        if ($this->immobile !== $immobile) {
            $this->immobile = $immobile;
            if ($immobile) {
                $this->setMotion(new EntityMotion());
            }
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setInvisible(bool $invisible): void
    {
        if ($this->invisible !== $invisible) {
            $this->invisible = $invisible;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setGlowing(bool $glowing): void
    {
        if ($this->glowing !== $glowing) {
            $this->glowing = $glowing;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setGravityEnabled(bool $enabled): void
    {
        if ($this->gravityEnabled !== $enabled) {
            $this->gravityEnabled = $enabled;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative controller mutation. */
    final public function setScale(float $scale): void
    {
        if (!is_finite($scale) || $scale < 0.01 || $scale > 16.0) {
            throw new InvalidArgumentException('Entity scale must be finite and between 0.01 and 16.');
        }
        if ($this->scale !== $scale) {
            $this->scale = $scale;
            $this->markPresentationChanged();
        }
    }

    /** @internal Assigns spawn ownership before the entity becomes observable. */
    final public function restoreSpawnOwnership(SpawnCause $origin, EntityDespawnPolicy $despawnPolicy): void
    {
        if ($despawnPolicy === EntityDespawnPolicy::NATURAL_DISTANCE && $origin !== SpawnCause::NATURAL) {
            throw new InvalidArgumentException('Natural-distance despawn ownership requires a natural spawn origin.');
        }
        if ($this->ageTicks !== 0 || $this->removed) {
            throw new InvalidArgumentException('Entity spawn ownership can only be assigned before simulation.');
        }
        $this->spawnOrigin = $origin;
        $this->despawnPolicy = $despawnPolicy;
    }

    final public function isRemoved(): bool
    {
        return $this->removed;
    }

    /** @internal Defers public-controller despawn until the authoritative world boundary. */
    final public function requestControllerDespawn(): void
    {
        if (!$this->removed) {
            $this->controllerDespawnRequested = true;
        }
    }

    final public function controllerDespawnRequested(): bool
    {
        return $this->controllerDespawnRequested;
    }

    final public function clearControllerDespawnRequest(): void
    {
        $this->controllerDespawnRequested = false;
    }

    final public function moveTo(string $worldName, Position $position, float $yaw, float $pitch): void
    {
        if ($worldName === '' || strlen($worldName) > 128 || preg_match('//u', $worldName) !== 1) {
            throw new InvalidArgumentException('Entity world name must be valid UTF-8 and bounded.');
        }
        self::validatePosition($position);
        self::validateRotation($yaw, $pitch);
        $yaw = self::normalizeYaw($yaw);
        if ($this->worldName !== $worldName || $this->position != $position
            || $this->yaw !== $yaw || $this->pitch !== $pitch) {
            $this->worldName = $worldName;
            $this->position = $position;
            $this->yaw = $yaw;
            $this->pitch = $pitch;
            $this->markChanged();
        }
    }

    /** @param null|Closure(string, Position, float, float): void $handler */
    final public function configureControllerTransformHandler(?Closure $handler): void
    {
        $this->controllerTransformHandler = $handler;
    }

    /** @internal Routes controller transforms through spatial ownership when attached. */
    final public function requestControllerTransform(
        string $worldName,
        Position $position,
        float $yaw,
        float $pitch,
    ): void {
        if ($this->controllerTransformHandler !== null) {
            ($this->controllerTransformHandler)($worldName, $position, $yaw, $pitch);

            return;
        }
        $this->moveTo($worldName, $position, $yaw, $pitch);
    }

    final public function setMotion(EntityMotion $motion): void
    {
        if ($this->motion != $motion) {
            $this->motion = $motion;
            $this->markChanged();
        }
    }

    final public function setOnGround(bool $onGround): void
    {
        if ($this->onGround !== $onGround) {
            $this->onGround = $onGround;
            $this->markChanged();
        }
    }

    final public function advanceAge(): void
    {
        if ($this->ageTicks === PHP_INT_MAX) {
            return;
        }
        ++$this->ageTicks;
    }

    /** @internal Persistence hydration before the entity becomes observable. */
    final public function restoreAgeTicks(int $ageTicks): void
    {
        if ($ageTicks < 0 || $ageTicks > 0x7fffffff || $this->ageTicks !== 0) {
            throw new InvalidArgumentException('Entity age hydration is invalid.');
        }
        $this->ageTicks = $ageTicks;
    }

    final public function remove(): void
    {
        if (!$this->removed) {
            $this->removed = true;
            $this->markChanged();
        }
    }

    private static function validatePosition(Position $position): void
    {
        if (!is_finite($position->x) || !is_finite($position->y) || !is_finite($position->z)
            || abs($position->x) > 30_000_000.0 || abs($position->z) > 30_000_000.0
            || abs($position->y) > 2_048.0) {
            throw new InvalidArgumentException('Entity position must be finite and bounded.');
        }
    }

    private static function validateRotation(float $yaw, float $pitch): void
    {
        if (!is_finite($yaw) || !is_finite($pitch) || $pitch < -90.0 || $pitch > 90.0) {
            throw new InvalidArgumentException('Entity rotation must be finite and bounded.');
        }
    }

    private static function normalizeYaw(float $yaw): float
    {
        $normalized = fmod($yaw, 360.0);

        return $normalized < 0.0 ? $normalized + 360.0 : $normalized;
    }

    final protected function markChanged(): void
    {
        if ($this->revision < PHP_INT_MAX) {
            ++$this->revision;
        }
    }

    final protected function markPresentationChanged(): void
    {
        if ($this->presentationRevision < PHP_INT_MAX) {
            ++$this->presentationRevision;
        }
        $this->markChanged();
    }

    protected function sizeMultiplier(): float
    {
        return 1.0;
    }

    protected function visualSizeMultiplier(): float
    {
        return $this->sizeMultiplier();
    }

    /** @internal Species-specific downward velocity cap used by authoritative physics. */
    public function maximumDownwardVelocity(): float
    {
        return PHP_FLOAT_MAX;
    }
}
