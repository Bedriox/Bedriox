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

namespace Bedriox\Server\Entity\Block;

use Bedriox\Api\Entity\Controller\EntityController;
use Bedriox\Api\Entity\Vanilla\FallingBlock;
use Bedriox\Data\CanonicalBlockState;
use Bedriox\Server\Entity\AbstractEntity;
use Bedriox\Server\Entity\Controller\BasicEntityController;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class FallingBlockEntity extends AbstractEntity implements FallingBlock, IntrinsicEntityPersistence
{
    private readonly EntityController $controller;
    private float $highestY;
    private ?int $settledRemovalTick = null;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        private CanonicalBlockState $blockState,
        private bool $dropItem = true,
        private float $damagePerBlock = 0.0,
        private float $maximumDamage = 0.0,
        EntityMotion $motion = new EntityMotion(),
    ) {
        parent::__construct($uniqueId, $runtimeId, $definition, $worldName, $position, $motion);
        self::validateDamage($damagePerBlock, $maximumDamage);
        $this->highestY = $position->y;
        $this->controller = new BasicEntityController($this);
    }

    public function getController(): EntityController
    {
        return $this->controller;
    }

    public function blockState(): CanonicalBlockState
    {
        return $this->blockState;
    }

    public function setBlockState(CanonicalBlockState $state): void
    {
        $this->blockState = $state;
    }

    public function getBlockIdentifier(): string
    {
        return $this->blockState->identifier();
    }

    public function getBlockProperties(): array
    {
        return $this->blockState->properties();
    }

    public function dropsAsItem(): bool
    {
        return $this->dropItem;
    }

    public function setDropsAsItem(bool $dropItem): void
    {
        $this->dropItem = $dropItem;
    }

    public function damagePerBlock(): float
    {
        return $this->damagePerBlock;
    }

    public function maximumDamage(): float
    {
        return $this->maximumDamage;
    }

    public function getFallDistance(): float
    {
        return max(0.0, $this->highestY - $this->internalPosition()->y);
    }

    /** @internal Called after authoritative movement. */
    public function observeHeight(): void
    {
        $this->highestY = max($this->highestY, $this->internalPosition()->y);
    }

    /** @internal Keeps the grounded actor visible briefly while the client applies the synchronized block update. */
    public function settleUntil(int $removalTick): void
    {
        if ($removalTick < 0) {
            throw new InvalidArgumentException('Falling-block removal tick must not be negative.');
        }
        $this->settledRemovalTick = $removalTick;
        $this->setMotion(new EntityMotion());
        $this->setOnGround(true);
    }

    public function isSettled(): bool
    {
        return $this->settledRemovalTick !== null;
    }

    public function shouldRemoveSettledActor(int $tick): bool
    {
        return $this->settledRemovalTick !== null && $tick >= $this->settledRemovalTick;
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'identifier' => $this->blockState->identifier(),
            'properties' => $this->blockState->properties(),
            'dropItem' => $this->dropItem,
            'damagePerBlock' => $this->damagePerBlock,
            'maximumDamage' => $this->maximumDamage,
            'highestY' => $this->highestY,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Persisted falling-block state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted falling-block state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== [
            'identifier', 'properties', 'dropItem', 'damagePerBlock', 'maximumDamage', 'highestY',
        ] || !is_string($decoded['identifier']) || !is_array($decoded['properties'])
            || !is_bool($decoded['dropItem']) || (!is_int($decoded['damagePerBlock']) && !is_float($decoded['damagePerBlock']))
            || (!is_int($decoded['maximumDamage']) && !is_float($decoded['maximumDamage']))
            || (!is_int($decoded['highestY']) && !is_float($decoded['highestY']))) {
            throw new InvalidArgumentException('Persisted falling-block state is malformed.');
        }
        $damagePerBlock = (float) $decoded['damagePerBlock'];
        $maximumDamage = (float) $decoded['maximumDamage'];
        $highestY = (float) $decoded['highestY'];
        self::validateDamage($damagePerBlock, $maximumDamage);
        if (!is_finite($highestY) || abs($highestY) > 2_048.0) {
            throw new InvalidArgumentException('Persisted falling-block height is invalid.');
        }
        $this->blockState = CanonicalBlockState::from($decoded['identifier'], $decoded['properties']);
        $this->dropItem = $decoded['dropItem'];
        $this->damagePerBlock = $damagePerBlock;
        $this->maximumDamage = $maximumDamage;
        $this->highestY = max($highestY, $this->internalPosition()->y);
    }

    private static function validateDamage(float $perBlock, float $maximum): void
    {
        if (!is_finite($perBlock) || $perBlock < 0.0 || $perBlock > 1_000.0
            || !is_finite($maximum) || $maximum < 0.0 || $maximum > 1_000_000.0) {
            throw new InvalidArgumentException('Falling-block damage values are invalid.');
        }
    }
}
