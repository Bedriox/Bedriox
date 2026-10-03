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

namespace Bedriox\Server\Entity\Vehicle;

use Bedriox\Api\Entity\Value\BoatVariant;
use Bedriox\Api\Entity\Value\MountSeat;
use Bedriox\Api\Entity\Vanilla\Boat;
use Bedriox\Api\Inventory\ItemNbt;
use Bedriox\Api\Inventory\ItemStack;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\Spawn\SpawnVariantAware;
use Bedriox\Server\Inventory\SimpleContainerInventory;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class BoatEntity extends AbstractMobEntity implements Boat, IntrinsicEntityPersistence, SpawnVariantAware
{
    private const float PLAYER_SEAT_Y = 1.020_01;
    private const float WATER_CONTROL_SPEED = 0.28;
    private const float LAND_CONTROL_SPEED = 0.04;
    private const float WATERLINE_OFFSET_Y = 0.375;
    private const float WATERLINE_EQUILIBRIUM = -0.01;
    private const float STRUCTURAL_DAMAGE_MULTIPLIER = 10.0;

    private bool $paddlingLeft = false;
    private bool $paddlingRight = false;
    private float $paddleTimeLeft = 0.0;
    private float $paddleTimeRight = 0.0;
    private int $hurtTicks = 0;
    private int $hurtDirection = 1;
    private readonly ?SimpleContainerInventory $inventory;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        private BoatVariant $variant = BoatVariant::OAK,
        private readonly bool $chestBoat = false,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            $chestBoat ? VehicleEntityDefinitions::chestBoat() : VehicleEntityDefinitions::boat(),
            $worldName,
            $position,
            new AiBehaviorDefinition(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->setAiEnabled(false);
        $this->inventory = $chestBoat
            ? new SimpleContainerInventory('entity:boat:' . $uniqueId, 27)
            : null;
    }

    public function getVariant(): BoatVariant
    {
        return $this->variant;
    }

    public function setVariant(BoatVariant $variant): void
    {
        if ($this->variant !== $variant) {
            $this->variant = $variant;
            $this->markPresentationChanged();
        }
    }

    public function applySpawnVariant(int|string $variant): void
    {
        if (!is_int($variant) || BoatVariant::tryFrom($variant) === null) {
            throw new InvalidArgumentException('Boat spawn variant is unsupported.');
        }
        $this->setVariant(BoatVariant::from($variant));
    }

    public function isChestBoat(): bool
    {
        return $this->chestBoat;
    }

    public function isSaddled(): bool
    {
        return true;
    }

    public function getSeatCapacity(): int
    {
        return $this->chestBoat ? 1 : 2;
    }

    public function chestInventory(): ?SimpleContainerInventory
    {
        return $this->inventory;
    }

    public function markChestInventoryChanged(): void
    {
        if ($this->inventory !== null) {
            $this->markChanged();
        }
    }

    public function mountedPassengerOffsetY(MountSeat $seat, float $passengerHeight, bool $playerPassenger): float
    {
        return $playerPassenger ? self::PLAYER_SEAT_Y : -0.2;
    }

    public function applyPaddleInput(bool $left, bool $right): void
    {
        if ($this->paddlingLeft !== $left || $this->paddlingRight !== $right) {
            $this->paddlingLeft = $left;
            $this->paddlingRight = $right;
            if (!$left) {
                $this->paddleTimeLeft = 0.0;
            }
            if (!$right) {
                $this->paddleTimeRight = 0.0;
            }
            $this->markPresentationChanged();
        }
    }

    public function advancePaddles(): void
    {
        $changed = false;
        if ($this->paddlingLeft) {
            $this->paddleTimeLeft += 0.04;
            $changed = true;
        }
        if ($this->paddlingRight) {
            $this->paddleTimeRight += 0.04;
            $changed = true;
        }
        if ($changed) {
            $this->markPresentationChanged();
        }
    }

    public function paddleTimeLeft(): float
    {
        return $this->paddleTimeLeft;
    }

    public function paddleTimeRight(): float
    {
        return $this->paddleTimeRight;
    }

    public function controlledSpeed(bool $waterSupported): float
    {
        return $waterSupported ? self::WATER_CONTROL_SPEED : self::LAND_CONTROL_SPEED;
    }

    public function waterlineCorrection(float $waterSurfaceY): float
    {
        return self::floatingPositionY($waterSurfaceY) - $this->internalPosition()->y;
    }

    public static function floatingPositionY(float $waterSurfaceY): float
    {
        return $waterSurfaceY + self::WATERLINE_EQUILIBRIUM - self::WATERLINE_OFFSET_Y;
    }

    public function bedrockPositionOffsetY(): float
    {
        return self::WATERLINE_OFFSET_Y;
    }

    public function structuralDamage(float $attackDamage, bool $instant): float
    {
        return $instant ? $this->getHealth() : $attackDamage * self::STRUCTURAL_DAMAGE_MULTIPLIER;
    }

    public function showDamageAnimation(): void
    {
        $this->hurtTicks = 9;
        $this->hurtDirection *= -1;
        $this->markPresentationChanged();
    }

    public function advanceDamageAnimation(): void
    {
        if ($this->hurtTicks > 0) {
            --$this->hurtTicks;
            $this->markPresentationChanged();
        }
    }

    public function hurtTicks(): int
    {
        return $this->hurtTicks;
    }

    public function hurtDirection(): int
    {
        return $this->hurtDirection;
    }

    public function persistenceVariant(): int
    {
        return $this->variant->value;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'chestBoat' => $this->chestBoat,
            'items' => array_map(
                static fn(?ItemStack $stack): ?array => $stack === null ? null : [
                    'identifier' => $stack->identifier,
                    'count' => $stack->count,
                    'damage' => $stack->damage,
                    'auxValue' => $stack->auxValue,
                    'nbt' => $stack->nbt === null ? null : base64_encode($stack->nbt->toBinary()),
                ],
                $this->inventory?->contents() ?? [],
            ),
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || $schemaVersion !== 1
            || strlen($data) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Persisted boat state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted boat state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['chestBoat', 'items']
            || $decoded['chestBoat'] !== $this->chestBoat) {
            throw new InvalidArgumentException('Persisted boat state is malformed.');
        }
        $this->variant = BoatVariant::tryFrom($variant)
            ?? throw new InvalidArgumentException('Persisted boat variant is unsupported.');
        if ($this->inventory !== null) {
            if (!is_array($decoded['items']) || !array_is_list($decoded['items'])
                || count($decoded['items']) !== 27) {
                throw new InvalidArgumentException('Persisted chest-boat inventory is malformed.');
            }
            $items = [];
            foreach ($decoded['items'] as $encoded) {
                if ($encoded === null) {
                    $items[] = null;
                    continue;
                }
                if (!is_array($encoded) || array_keys($encoded) !== ['identifier', 'count', 'damage', 'auxValue', 'nbt']
                    || !is_string($encoded['identifier']) || !is_int($encoded['count'])
                    || !is_int($encoded['damage']) || !is_int($encoded['auxValue'])
                    || ($encoded['nbt'] !== null && !is_string($encoded['nbt']))) {
                    throw new InvalidArgumentException('Persisted chest-boat item is malformed.');
                }
                $binary = $encoded['nbt'] === null ? null : base64_decode($encoded['nbt'], true);
                if ($encoded['nbt'] !== null && $binary === false) {
                    throw new InvalidArgumentException('Persisted chest-boat item NBT is malformed.');
                }
                $items[] = new ItemStack(
                    $encoded['identifier'],
                    $encoded['count'],
                    $encoded['damage'],
                    $binary === null ? null : ItemNbt::fromBinary($binary),
                    $encoded['auxValue'],
                );
            }
            $this->inventory->replaceContents($items);
        } elseif ($decoded['items'] !== []) {
            throw new InvalidArgumentException('A normal boat cannot contain persisted items.');
        }
    }
}
