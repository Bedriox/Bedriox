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

use Bedriox\Api\Entity\Vanilla\ZombieVillager;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\Ai\VanillaAiBehaviors;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class ZombieVillagerEntity extends ZombieFamilyEntity implements ZombieVillager, IntrinsicEntityPersistence
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
        bool $baby = false,
        private int $profession = 0,
        private int $biomeVariant = 0,
        private int $cureTicks = 0,
    ) {
        self::validateState($profession, $biomeVariant, $cureTicks);
        parent::__construct(
            $uniqueId,
            $runtimeId,
            VanillaEntityDefinitions::zombieVillager(),
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

    public function getProfession(): int
    {
        return $this->profession;
    }

    public function getBiomeVariant(): int
    {
        return $this->biomeVariant;
    }

    public function getCureTicks(): int
    {
        return $this->cureTicks;
    }

    /** @internal */
    public function setCureTicks(int $ticks): void
    {
        self::validateState($this->profession, $this->biomeVariant, $ticks);
        if ($ticks !== $this->cureTicks) {
            $this->cureTicks = $ticks;
            $this->markPresentationChanged();
        }
    }

    public function persistenceVariant(): int
    {
        return $this->profession;
    }

    public function persistenceSchemaVersion(): int
    {
        return 1;
    }

    public function persistenceData(): string
    {
        return json_encode([
            ...$this->zombieFamilyPersistenceData(),
            'biomeVariant' => $this->biomeVariant,
            'cureTicks' => $this->cureTicks,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if (!is_int($variant) || $schemaVersion !== 1 || strlen($data) > 128) {
            throw new InvalidArgumentException('Persisted zombie-villager state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted zombie-villager state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== ['baby', 'biomeVariant', 'cureTicks']
            || !is_int($decoded['biomeVariant']) || !is_int($decoded['cureTicks'])) {
            throw new InvalidArgumentException('Persisted zombie-villager state is malformed.');
        }
        self::validateState($variant, $decoded['biomeVariant'], $decoded['cureTicks']);
        $this->restoreZombieFamilyPersistenceData($decoded);
        $this->profession = $variant;
        $this->biomeVariant = $decoded['biomeVariant'];
        $this->cureTicks = $decoded['cureTicks'];
    }

    private static function validateState(int $profession, int $biomeVariant, int $cureTicks): void
    {
        if ($profession < 0 || $profession > 14 || $biomeVariant < 0 || $biomeVariant > 6
            || $cureTicks < 0 || $cureTicks > 6_000) {
            throw new InvalidArgumentException('Zombie-villager state is outside its supported bounds.');
        }
    }
}
