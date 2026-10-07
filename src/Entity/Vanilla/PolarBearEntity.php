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

namespace Bedriox\Server\Entity\Vanilla;

use Bedriox\Api\Entity\Vanilla\PolarBear;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\AnimalEntity;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Movement\WaterBuoyant;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class PolarBearEntity extends AnimalEntity implements IntrinsicEntityPersistence, MutableAngerState, PolarBear, WaterBuoyant
{
    use AngerStateTrait;

    private const int BABY_GROWTH_TICKS = 24_000;

    private int $babyGrowthTicks;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        string $worldName,
        Position $position,
        ?AiBehaviorDefinition $behavior = null,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
        private bool $baby = false,
        private bool $standing = false,
        ?string $angerTargetUniqueId = null,
        int $angerTicks = 0,
        int $babyGrowthTicks = 0,
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::polarBear(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::polarBear(), $motion, $yaw, $pitch, $health);
        $this->initializeAngerState($angerTargetUniqueId, $angerTicks);
        if ($baby && $babyGrowthTicks === 0) {
            $babyGrowthTicks = self::BABY_GROWTH_TICKS;
        }
        if ($babyGrowthTicks < 0 || $babyGrowthTicks > self::BABY_GROWTH_TICKS
            || ($baby !== ($babyGrowthTicks > 0))) {
            throw new InvalidArgumentException('Polar-bear growth state is outside its supported bounds.');
        }
        $this->babyGrowthTicks = $babyGrowthTicks;
    }

    public function isBaby(): bool
    {
        return $this->baby;
    }

    public function isStanding(): bool
    {
        return $this->standing;
    }

    public function waterBuoyancyVelocity(): float
    {
        return 0.04;
    }

    /** @internal Authoritative attack-presentation mutation. */
    public function setStanding(bool $standing): void
    {
        if ($this->standing !== $standing) {
            $this->standing = $standing;
            $this->markPresentationChanged();
        }
    }

    /** @internal Authoritative species-state mutation. */
    public function setBaby(bool $baby): void
    {
        if ($this->baby !== $baby) {
            $this->baby = $baby;
            $this->babyGrowthTicks = $baby ? self::BABY_GROWTH_TICKS : 0;
            $this->markPresentationChanged();
        }
    }

    /** @internal Advances the fixed vanilla cub growth duration. */
    public function advanceGrowth(int $ticks): void
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Polar-bear growth advance is outside its supported bound.');
        }
        if ($this->babyGrowthTicks === 0) {
            return;
        }
        $this->babyGrowthTicks = max(0, $this->babyGrowthTicks - $ticks);
        if ($this->babyGrowthTicks === 0) {
            $this->baby = false;
            $this->markPresentationChanged();
        } else {
            $this->markChanged();
        }
    }

    protected function sizeMultiplier(): float
    {
        return $this->baby ? 0.5 : 1.0;
    }

    public function persistenceVariant(): null
    {
        return null;
    }

    public function persistenceSchemaVersion(): int
    {
        return 2;
    }

    public function persistenceData(): string
    {
        return json_encode([
            'baby' => $this->baby,
            'babyGrowthTicks' => $this->babyGrowthTicks,
            'standing' => $this->standing,
            ...$this->angerPersistenceData(),
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 2 || strlen($data) > 512) {
            throw new InvalidArgumentException('Persisted polar-bear state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted polar-bear state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== ['baby', 'babyGrowthTicks', 'standing', 'angerTargetUniqueId', 'angerTicks']
            || !is_bool($decoded['baby']) || !is_int($decoded['babyGrowthTicks'])
            || $decoded['babyGrowthTicks'] < 0 || $decoded['babyGrowthTicks'] > self::BABY_GROWTH_TICKS
            || ($decoded['baby'] !== ($decoded['babyGrowthTicks'] > 0))
            || !is_bool($decoded['standing'])) {
            throw new InvalidArgumentException('Persisted polar-bear state is malformed.');
        }
        $this->baby = $decoded['baby'];
        $this->babyGrowthTicks = $decoded['babyGrowthTicks'];
        $this->standing = $decoded['standing'];
        $this->restoreAngerPersistenceData($decoded);
    }
}
