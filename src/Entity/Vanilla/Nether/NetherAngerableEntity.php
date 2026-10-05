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

use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

/** @internal Shared authoritative anger state for Nether piglin families. */
abstract class NetherAngerableEntity extends MonsterEntity implements IntrinsicEntityPersistence, MutableAngerState
{
    use AngerStateTrait;

    public const int ZOMBIFICATION_TICKS = 300;

    private int $outsideNetherTicks = 0;

    public function __construct(
        string $uniqueId,
        int $runtimeId,
        EntityDefinition $definition,
        string $worldName,
        Position $position,
        AiBehaviorDefinition $behavior,
        EntityMotion $motion = new EntityMotion(),
        float $yaw = 0.0,
        float $pitch = 0.0,
        ?float $health = null,
    ) {
        parent::__construct($uniqueId, $runtimeId, $definition, $worldName, $position, $behavior, $motion, $yaw, $pitch, $health);
        $this->initializeAngerState();
    }

    final public function advanceZombification(bool $outsideNether): bool
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

    final public function persistenceVariant(): null
    {
        return null;
    }

    final public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    final public function persistenceData(): string
    {
        return json_encode([
            ...$this->angerPersistenceData(),
            'outsideNetherTicks' => $this->outsideNetherTicks,
            ...$this->additionalPersistenceData(),
        ], JSON_THROW_ON_ERROR);
    }

    final public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted Nether anger state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted Nether anger state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== array_keys([
                ...$this->angerPersistenceData(),
                'outsideNetherTicks' => $this->outsideNetherTicks,
                ...$this->additionalPersistenceData(),
            ])) {
            throw new InvalidArgumentException('Persisted Nether anger state is malformed.');
        }
        $this->restoreAngerPersistenceData($decoded);
        $outsideNetherTicks = $decoded['outsideNetherTicks'] ?? null;
        if (!is_int($outsideNetherTicks) || $outsideNetherTicks < 0
            || $outsideNetherTicks > self::ZOMBIFICATION_TICKS) {
            throw new InvalidArgumentException('Persisted Nether conversion state is malformed.');
        }
        $this->outsideNetherTicks = $outsideNetherTicks;
        $this->restoreAdditionalPersistenceData($decoded);
    }

    /** @return array<string, bool|int|string|null> */
    protected function additionalPersistenceData(): array
    {
        return [];
    }

    /** @param array<mixed> $data */
    protected function restoreAdditionalPersistenceData(array $data): void {}
}
