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

use Bedriox\Api\Entity\Vanilla\Husk;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class HuskEntity extends ZombieFamilyEntity implements Husk, IntrinsicEntityPersistence
{
    private const int MAXIMUM_SUBMERGED_CONVERSION_TICKS = 600;

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
        bool $baby = false,
        private int $submergedConversionTicks = 0,
    ) {
        if ($submergedConversionTicks < 0 || $submergedConversionTicks > self::MAXIMUM_SUBMERGED_CONVERSION_TICKS) {
            throw new InvalidArgumentException('Husk submerged conversion time is outside its supported bounds.');
        }
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::husk(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::zombie(),
            $motion,
            $yaw,
            $pitch,
            $health,
            $baby,
        );
    }

    public function getSubmergedConversionTicks(): int
    {
        return $this->submergedConversionTicks;
    }

    /** @internal Returns true when the vanilla 30-second submersion threshold is reached. */
    public function advanceSubmergedConversion(int $ticks): bool
    {
        if ($ticks < 1 || $ticks > 20) {
            throw new InvalidArgumentException('Husk submerged conversion advance is outside its supported bound.');
        }
        $this->submergedConversionTicks = min(
            self::MAXIMUM_SUBMERGED_CONVERSION_TICKS,
            $this->submergedConversionTicks + $ticks,
        );
        $this->markChanged();

        return $this->submergedConversionTicks === self::MAXIMUM_SUBMERGED_CONVERSION_TICKS;
    }

    /** @internal */
    public function resetSubmergedConversion(): void
    {
        if ($this->submergedConversionTicks !== 0) {
            $this->submergedConversionTicks = 0;
            $this->markChanged();
        }
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
            ...$this->zombieFamilyPersistenceData(),
            'submergedConversionTicks' => $this->submergedConversionTicks,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 96) {
            throw new InvalidArgumentException('Persisted husk state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted husk state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['baby', 'submergedConversionTicks']
            || !is_int($decoded['submergedConversionTicks'])
            || $decoded['submergedConversionTicks'] < 0
            || $decoded['submergedConversionTicks'] > self::MAXIMUM_SUBMERGED_CONVERSION_TICKS) {
            throw new InvalidArgumentException('Persisted husk state is malformed.');
        }
        $this->restoreZombieFamilyPersistenceData($decoded);
        $this->submergedConversionTicks = $decoded['submergedConversionTicks'];
    }

    protected function visualSizeMultiplier(): float
    {
        return $this->isBaby() ? 0.53125 : 1.0625;
    }
}
