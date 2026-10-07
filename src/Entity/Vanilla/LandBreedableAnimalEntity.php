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

use Bedriox\Api\Entity\Controller\BreedableAnimalController;
use Bedriox\Server\Entity\BreedableAnimalEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Plugin\BufferedBreedableAnimalController;
use Bedriox\Server\Plugin\PluginActionBuffer;
use InvalidArgumentException;
use JsonException;
use LogicException;

abstract class LandBreedableAnimalEntity extends BreedableAnimalEntity implements IntrinsicEntityPersistence
{
    final public function getController(): BreedableAnimalController
    {
        $controller = parent::getController();
        if (!$controller instanceof BreedableAnimalController) {
            throw new LogicException('A breedable land animal must expose a breedable-animal controller.');
        }

        return $controller;
    }

    final protected function createController(?PluginActionBuffer $actions): BreedableAnimalController
    {
        return new BufferedBreedableAnimalController($actions, $this, $this->equipmentState());
    }

    final public function persistenceVariant(): int|string|null
    {
        return $this->speciesPersistenceVariant();
    }

    final public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    final public function persistenceData(): string
    {
        return json_encode([...$this->breedablePersistenceData(), ...$this->speciesPersistenceData()], JSON_THROW_ON_ERROR);
    }

    final public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($schemaVersion !== 1 || strlen($data) > 1_024) {
            throw new InvalidArgumentException('Persisted land-animal state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted land-animal state is malformed.', previous: $error);
        }
        $expectedKeys = array_keys([...$this->breedablePersistenceData(), ...$this->speciesPersistenceData()]);
        if (!is_array($decoded) || array_keys($decoded) !== $expectedKeys) {
            throw new InvalidArgumentException('Persisted land-animal state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
        $this->restoreSpeciesPersistenceState($variant, $decoded);
    }

    protected function speciesPersistenceVariant(): int|string|null
    {
        return null;
    }

    /** @return array<string, bool|int|string|null> */
    protected function speciesPersistenceData(): array
    {
        return [];
    }

    /** @param array<mixed> $data */
    protected function restoreSpeciesPersistenceState(int|string|null $variant, array $data): void
    {
        if ($variant !== null) {
            throw new InvalidArgumentException('Persisted land-animal variant is unsupported.');
        }
    }
}
