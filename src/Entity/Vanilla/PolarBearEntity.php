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
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class PolarBearEntity extends AnimalEntity implements IntrinsicEntityPersistence, PolarBear
{
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
    ) {
        parent::__construct($uniqueId, $runtimeId, LandAnimalEntityDefinitions::polarBear(), $worldName, $position, $behavior ?? LandAnimalAiBehaviors::wandering('polar_bear', 0.09), $motion, $yaw, $pitch, $health);
    }

    public function isBaby(): bool
    {
        return $this->baby;
    }

    /** @internal Authoritative species-state mutation. */
    public function setBaby(bool $baby): void
    {
        if ($this->baby !== $baby) {
            $this->baby = $baby;
            $this->markPresentationChanged();
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
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode(['baby' => $this->baby], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 64) {
            throw new InvalidArgumentException('Persisted polar-bear state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted polar-bear state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['baby'] || !is_bool($decoded['baby'])) {
            throw new InvalidArgumentException('Persisted polar-bear state is malformed.');
        }
        $this->baby = $decoded['baby'];
    }
}
