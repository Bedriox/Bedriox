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

use Bedriox\Api\Entity\Vanilla\Bogged;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\MonsterEntity;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class BoggedEntity extends MonsterEntity implements Bogged, IntrinsicEntityPersistence
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
        private bool $sheared = false,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::bogged(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::skeleton(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
    }

    public function isSheared(): bool
    {
        return $this->sheared;
    }

    /** @internal */
    public function setSheared(bool $sheared): void
    {
        if ($this->sheared !== $sheared) {
            $this->sheared = $sheared;
            $this->markPresentationChanged();
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
        return json_encode(['sheared' => $this->sheared], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > 48) {
            throw new InvalidArgumentException('Persisted bogged state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted bogged state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['sheared'] || !is_bool($decoded['sheared'])) {
            throw new InvalidArgumentException('Persisted bogged state is malformed.');
        }
        $this->sheared = $decoded['sheared'];
    }
}
