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

use Bedriox\Api\Entity\Capability\Aquatic;
use Bedriox\Server\Entity\Concern\AquaticStateTrait;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use InvalidArgumentException;
use JsonException;

abstract class AquaticBreedableAnimalEntity extends BreedableAnimalEntity implements Aquatic, AquaticRuntimeState, IntrinsicEntityPersistence
{
    use AquaticStateTrait;

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
        return json_encode($this->breedablePersistenceData(), JSON_THROW_ON_ERROR);
    }

    final public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant === null && $schemaVersion === 0 && $data === '') {
            return;
        }
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted aquatic breeding state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted aquatic breeding state is malformed.', previous: $error);
        }
        if (!is_array($decoded)) {
            throw new InvalidArgumentException('Persisted aquatic breeding state is malformed.');
        }
        $this->restoreBreedablePersistenceData($decoded);
    }
}
