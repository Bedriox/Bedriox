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

use Bedriox\Api\Entity\Value\SlimeSize;
use Bedriox\Api\Entity\Vanilla\MagmaCube;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;

final class MagmaCubeEntity extends MonsterEntity implements MagmaCube, IntrinsicEntityPersistence
{
    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position, private readonly SlimeSize $size = SlimeSize::LARGE, ?AiBehaviorDefinition $behavior = null, EntityMotion $motion = new EntityMotion(), float $yaw = 0.0, float $pitch = 0.0, ?float $health = null)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::magmaCube($size), $worldName, $position, $behavior ?? VanillaAiBehaviors::magmaCube(), $motion, $yaw, $pitch, $health);
    }

    public function getSize(): SlimeSize
    {
        return $this->size;
    }

    public function persistenceVariant(): int
    {
        return $this->size->value;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return '{}';
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || SlimeSize::tryFrom($variant) !== $this->size
            || $schemaVersion !== 1 || $data !== '{}') {
            throw new InvalidArgumentException('Persisted magma-cube state has an unsupported schema or size.');
        }
    }
}
