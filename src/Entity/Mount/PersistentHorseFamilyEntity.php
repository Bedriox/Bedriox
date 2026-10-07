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

namespace Bedriox\Server\Entity\Mount;

use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use InvalidArgumentException;
use JsonException;

abstract class PersistentHorseFamilyEntity extends HorseFamilyEntity implements IntrinsicEntityPersistence
{
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
        return json_encode([...$this->horseFamilyPersistenceData(), ...$this->mountSpeciesPersistenceData()], JSON_THROW_ON_ERROR);
    }

    final public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 4_096) {
            throw new InvalidArgumentException('Persisted horse-family state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted horse-family state is malformed.', previous: $error);
        }
        $expectedKeys = array_keys([...$this->horseFamilyPersistenceData(), ...$this->mountSpeciesPersistenceData()]);
        if (!is_array($decoded) || array_keys($decoded) !== $expectedKeys) {
            throw new InvalidArgumentException('Persisted horse-family state is malformed.');
        }
        $this->restoreHorseFamilyPersistenceData($decoded);
        $this->restoreMountSpeciesPersistenceData($decoded);
    }

    /** @return array<string, bool|int|string|null> */
    protected function mountSpeciesPersistenceData(): array
    {
        return [];
    }

    /** @param array<mixed> $data */
    protected function restoreMountSpeciesPersistenceData(array $data): void {}
}
