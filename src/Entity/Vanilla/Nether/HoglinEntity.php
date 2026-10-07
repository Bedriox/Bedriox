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

namespace Bedriox\Server\Entity\Vanilla\Nether;

use Bedriox\Api\Entity\Vanilla\Hoglin;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class HoglinEntity extends BreedableAnimalEntity implements Hoglin, IntrinsicEntityPersistence
{
    public const int ZOMBIFICATION_TICKS = 300;

    private int $outsideNetherTicks = 0;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null, bool $baby = false, int $loveTicks = 0, int $babyGrowthTicks = 0, int $breedingCooldownTicks = 0)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::hoglin(), $worldName, $position, $behavior ?? VanillaAiBehaviors::hoglin(), $motion, $yaw, $pitch, $health);
        $this->initializeBreedableState($baby, $loveTicks, $babyGrowthTicks, $breedingCooldownTicks);
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
            ...$this->breedablePersistenceData(),
            'outsideNetherTicks' => $this->outsideNetherTicks,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 128) {
            throw new InvalidArgumentException('Persisted hoglin state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted hoglin state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== array_keys([...$this->breedablePersistenceData(), 'outsideNetherTicks' => $this->outsideNetherTicks])
            || !is_int($decoded['outsideNetherTicks']) || $decoded['outsideNetherTicks'] < 0
            || $decoded['outsideNetherTicks'] > self::ZOMBIFICATION_TICKS) {
            throw new InvalidArgumentException('Persisted hoglin state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->outsideNetherTicks = $decoded['outsideNetherTicks'];
    }

    public function advanceZombification(bool $outsideNether): bool
    {
        if (!$outsideNether) {
            if ($this->outsideNetherTicks !== 0) {
                $this->outsideNetherTicks = 0;
                $this->markChanged();
            }
            return false;
        }
        $this->outsideNetherTicks = min(self::ZOMBIFICATION_TICKS, $this->outsideNetherTicks + 1);
        $this->markChanged();

        return $this->outsideNetherTicks === self::ZOMBIFICATION_TICKS;
    }
}
