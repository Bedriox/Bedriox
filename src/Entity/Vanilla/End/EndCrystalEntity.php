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

namespace Bedriox\Server\Entity\Vanilla\End;

use Bedriox\Api\Entity\Vanilla\EndCrystal;
use Bedriox\Server\Entity\AbstractMobEntity;
use Bedriox\Server\Entity\Ai\AiBehaviorDefinition;
use Bedriox\Server\Entity\EntityMotion;
use Bedriox\Server\Entity\Persistence\EntityPersistenceLimits;
use Bedriox\Server\Entity\Persistence\IntrinsicEntityPersistence;
use Bedriox\Server\Entity\VanillaEntityDefinitions;
use Bedriox\Server\Simulation\Position;
use InvalidArgumentException;
use JsonException;

final class EndCrystalEntity extends AbstractMobEntity implements EndCrystal, IntrinsicEntityPersistence
{
    private bool $showBase = false;

    private ?Position $beamTarget = null;

    private bool $invulnerable = false;

    private bool $encounterOwned = false;

    public function __construct(string $uniqueId, int $runtimeId, string $worldName, Position $position)
    {
        parent::__construct($uniqueId, $runtimeId, VanillaEntityDefinitions::endCrystal(), $worldName, $position, new AiBehaviorDefinition(), new EntityMotion());
        $this->setAiEnabled(false);
        $this->setGravityEnabled(false);
    }

    public function showsBase(): bool
    {
        return $this->showBase;
    }

    public function beamTarget(): ?Position
    {
        return $this->beamTarget;
    }

    public function isInvulnerable(): bool
    {
        return $this->invulnerable;
    }

    public function isEncounterOwned(): bool
    {
        return $this->encounterOwned;
    }

    /** @internal Encounter state is authoritative for naturally generated crystals. */
    public function configureEncounterState(
        bool $owned,
        bool $showBase,
        ?Position $beamTarget = null,
        bool $invulnerable = false,
    ): void {
        foreach ($beamTarget === null ? [] : [$beamTarget->x, $beamTarget->y, $beamTarget->z] as $coordinate) {
            if (!is_finite($coordinate) || abs($coordinate) > 30_000_000.0) {
                throw new InvalidArgumentException('End Crystal beam target must be finite and bounded.');
            }
        }
        if ($this->encounterOwned !== $owned || $this->showBase !== $showBase
            || $this->beamTarget != $beamTarget || $this->invulnerable !== $invulnerable) {
            $this->encounterOwned = $owned;
            $this->showBase = $showBase;
            $this->beamTarget = $beamTarget;
            $this->invulnerable = $invulnerable;
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
        return json_encode([
            'showBase' => $this->showBase,
            'beamTarget' => $this->beamTarget === null ? null : [
                $this->beamTarget->x,
                $this->beamTarget->y,
                $this->beamTarget->z,
            ],
            'invulnerable' => $this->invulnerable,
            'encounterOwned' => $this->encounterOwned,
        ], JSON_THROW_ON_ERROR);
    }

    public function restorePersistenceState(int|string|null $variant, int $schemaVersion, string $data): void
    {
        if ($variant !== null || $schemaVersion !== 1 || strlen($data) > EntityPersistenceLimits::MAX_CUSTOM_DATA_BYTES) {
            throw new InvalidArgumentException('Persisted End Crystal state has an unsupported schema.');
        }
        try {
            $decoded = json_decode($data, true, 4, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new InvalidArgumentException('Persisted End Crystal state is malformed.', previous: $error);
        }
        if (!is_array($decoded) || array_keys($decoded) !== [
            'showBase', 'beamTarget', 'invulnerable', 'encounterOwned',
        ] || !is_bool($decoded['showBase']) || !is_bool($decoded['invulnerable'])
            || !is_bool($decoded['encounterOwned'])) {
            throw new InvalidArgumentException('Persisted End Crystal state is malformed.');
        }
        $beamTarget = null;
        if ($decoded['beamTarget'] !== null) {
            if (!is_array($decoded['beamTarget']) || !array_is_list($decoded['beamTarget'])
                || count($decoded['beamTarget']) !== 3) {
                throw new InvalidArgumentException('Persisted End Crystal beam target is malformed.');
            }
            $coordinates = array_map(static function (mixed $coordinate): float {
                if (!is_int($coordinate) && !is_float($coordinate)) {
                    throw new InvalidArgumentException('Persisted End Crystal beam coordinate is malformed.');
                }
                return (float) $coordinate;
            }, $decoded['beamTarget']);
            $beamTarget = new Position($coordinates[0], $coordinates[1], $coordinates[2]);
        }
        $this->configureEncounterState(
            $decoded['encounterOwned'],
            $decoded['showBase'],
            $beamTarget,
            $decoded['invulnerable'],
        );
    }
}
