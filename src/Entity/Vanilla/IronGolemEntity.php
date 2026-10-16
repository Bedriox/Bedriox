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

use Bedriox\Api\Entity\Vanilla\IronGolem;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\Concern\AngerStateTrait;
use Bedriox\Server\Entity\Concern\MutableAngerState;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class IronGolemEntity extends AbstractMobEntity implements IntrinsicEntityPersistence, IronGolem, MutableAngerState
{
    use AngerStateTrait;

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
        private bool $playerCreated = false,
        ?string $angerTargetUniqueId = null,
        int $angerTicks = 0,
    ) {
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::ironGolem(),
            $worldName,
            $position,
            $behavior ?? VanillaAiBehaviors::ironGolem(),
            $motion,
            $yaw,
            $pitch,
            $health,
        );
        $this->initializeAngerState($angerTargetUniqueId, $angerTicks);
    }

    public function isPlayerCreated(): bool
    {
        return $this->playerCreated;
    }

    public function persistenceVariant(): int
    {
        return $this->playerCreated ? 1 : 0;
    }

    public function persistenceSchemaVersion(): int
    {
        return 2;
    }

    public function persistenceData(): string
    {
        return json_encode($this->angerPersistenceData(), JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || ($variant !== 0 && $variant !== 1)) {
            throw new InvalidArgumentException('Persisted iron-golem state is malformed.');
        }
        $this->playerCreated = $variant === 1;
        if ($schemaVersion === 1 && $data === '') {
            $this->initializeAngerState();

            return;
        }
        if ($schemaVersion !== 2 || strlen($data) > 256) {
            throw new InvalidArgumentException('Persisted iron-golem state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted iron-golem state is malformed.', previous: $error);
        }
        if (!is_array($decoded)
            || array_keys($decoded) !== ['angerTargetUniqueId', 'angerTicks']) {
            throw new InvalidArgumentException('Persisted iron-golem state is malformed.');
        }
        $this->restoreAngerPersistenceData($decoded);
    }
}
