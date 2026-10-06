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

namespace Bedriox\Server\Gameplay\End;

use InvalidArgumentException;

/** Immutable, restart-safe End encounter checkpoint. */
final readonly class EndEncounterState
{
    public const int SCHEMA_VERSION = 2;

    /** @param list<string> $ritualCrystalUuids */
    public function __construct(
        public bool $initialized = false,
        public bool $dragonKilled = false,
        public bool $previouslyKilledDragon = false,
        public bool $dragonEggPlaced = false,
        public bool $exitPortalActive = false,
        public int $gatewayCount = 0,
        public EnderDragonPhase $phase = EnderDragonPhase::CIRCLING,
        public EnderDragonRespawnStage $respawnStage = EnderDragonRespawnStage::NONE,
        public int $respawnTicks = 0,
        public ?string $dragonUuid = null,
        public EndVictoryStage $victoryStage = EndVictoryStage::NONE,
        public ?string $confirmedDeathUuid = null,
        public array $ritualCrystalUuids = [],
        public int $respawnWorkIndex = 0,
        public int $revision = 0,
    ) {
        if ($gatewayCount < 0 || $gatewayCount > EndGatewayPlanner::GATEWAY_COUNT
            || $respawnTicks < 0 || $respawnTicks > 10_000 || $revision < 0
            || ($dragonUuid !== null && ($dragonUuid === '' || strlen($dragonUuid) > 64))
            || ($confirmedDeathUuid !== null && ($confirmedDeathUuid === '' || strlen($confirmedDeathUuid) > 64))
            || $respawnWorkIndex < 0 || $respawnWorkIndex > 10_000
            || count($ritualCrystalUuids) > 4 || count(array_unique($ritualCrystalUuids)) !== count($ritualCrystalUuids)) {
            throw new InvalidArgumentException('End encounter state is outside its supported bounds.');
        }
        foreach ($ritualCrystalUuids as $crystalUuid) {
            if ($crystalUuid === '' || strlen($crystalUuid) > 64 || preg_match('//u', $crystalUuid) !== 1) {
                throw new InvalidArgumentException('End encounter ritual crystal identity is invalid.');
            }
        }
    }

    public function withDragon(string $uuid): self
    {
        return new self(
            true,
            false,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            false,
            $this->gatewayCount,
            EnderDragonPhase::CIRCLING,
            EnderDragonRespawnStage::NONE,
            0,
            $uuid,
            EndVictoryStage::NONE,
            null,
            [],
            0,
            $this->revision + 1,
        );
    }

    public function withPhase(EnderDragonPhase $phase): self
    {
        return new self(
            $this->initialized,
            $this->dragonKilled,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            $phase,
            $this->respawnStage,
            $this->respawnTicks,
            $this->dragonUuid,
            $this->victoryStage,
            $this->confirmedDeathUuid,
            $this->ritualCrystalUuids,
            $this->respawnWorkIndex,
            $this->revision + 1,
        );
    }

    public function confirmDragonDeath(string $uuid): self
    {
        if ($this->dragonKilled || $this->victoryStage !== EndVictoryStage::NONE || $this->dragonUuid !== $uuid) {
            return $this;
        }
        return new self(
            $this->initialized,
            $this->dragonKilled,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            $this->phase,
            $this->respawnStage,
            $this->respawnTicks,
            $this->dragonUuid,
            EndVictoryStage::DEATH_CONFIRMED,
            $uuid,
            [],
            0,
            $this->revision + 1,
        );
    }

    public function prepareVictory(bool $gatewayCreated): self
    {
        return new self(
            true,
            true,
            true,
            true,
            true,
            min(EndGatewayPlanner::GATEWAY_COUNT, $this->gatewayCount + ($gatewayCreated ? 1 : 0)),
            EnderDragonPhase::DYING,
            EnderDragonRespawnStage::NONE,
            0,
            null,
            EndVictoryStage::PREPARED,
            $this->confirmedDeathUuid,
            [],
            0,
            $this->revision + 1,
        );
    }

    public function withVictoryStage(EndVictoryStage $stage): self
    {
        return new self(
            $this->initialized,
            $this->dragonKilled,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            $this->phase,
            $this->respawnStage,
            $this->respawnTicks,
            $this->dragonUuid,
            $stage,
            $this->confirmedDeathUuid,
            $this->ritualCrystalUuids,
            $this->respawnWorkIndex,
            $this->revision + 1,
        );
    }

    /** @param list<string> $crystalUuids */
    public function beginRespawn(array $crystalUuids): self
    {
        if (!$this->dragonKilled || $this->respawnStage !== EnderDragonRespawnStage::NONE) {
            throw new InvalidArgumentException('The Ender Dragon encounter cannot begin respawning now.');
        }
        return new self(
            true,
            true,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            $this->phase,
            EnderDragonRespawnStage::STARTING,
            0,
            null,
            EndVictoryStage::PUBLISHED,
            null,
            $crystalUuids,
            0,
            $this->revision + 1,
        );
    }

    public function advanceRespawn(EnderDragonRespawnStage $stage, int $ticks, int $workIndex): self
    {
        return new self(
            $this->initialized,
            $this->dragonKilled,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            $this->phase,
            $stage,
            $ticks,
            $this->dragonUuid,
            $this->victoryStage,
            $this->confirmedDeathUuid,
            $this->ritualCrystalUuids,
            $workIndex,
            $this->revision + 1,
        );
    }

    public function abortRespawn(): self
    {
        return new self(
            $this->initialized,
            true,
            $this->previouslyKilledDragon,
            $this->dragonEggPlaced,
            $this->exitPortalActive,
            $this->gatewayCount,
            EnderDragonPhase::DYING,
            EnderDragonRespawnStage::NONE,
            0,
            null,
            EndVictoryStage::PUBLISHED,
            null,
            [],
            0,
            $this->revision + 1,
        );
    }

    /** @return array<string, bool|int|string|null|list<string>> */
    public function toArray(): array
    {
        return [
            'schema' => self::SCHEMA_VERSION, 'initialized' => $this->initialized,
            'dragon_killed' => $this->dragonKilled, 'previously_killed' => $this->previouslyKilledDragon,
            'egg_placed' => $this->dragonEggPlaced, 'exit_portal_active' => $this->exitPortalActive,
            'gateway_count' => $this->gatewayCount, 'phase' => $this->phase->value,
            'respawn_stage' => $this->respawnStage->value, 'respawn_ticks' => $this->respawnTicks,
            'dragon_uuid' => $this->dragonUuid, 'victory_stage' => $this->victoryStage->value,
            'confirmed_death_uuid' => $this->confirmedDeathUuid,
            'ritual_crystal_uuids' => $this->ritualCrystalUuids,
            'respawn_work_index' => $this->respawnWorkIndex, 'revision' => $this->revision,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $schema = $data['schema'] ?? null;
        if ($schema !== 1 && $schema !== self::SCHEMA_VERSION) {
            throw new InvalidArgumentException('Unsupported End encounter state schema.');
        }
        if ($schema === 1) {
            return new self(
                self::bool($data, 'initialized'),
                self::bool($data, 'dragon_killed'),
                self::bool($data, 'previously_killed'),
                self::bool($data, 'egg_placed'),
                self::bool($data, 'exit_portal_active'),
                self::int($data, 'gateway_count'),
                EnderDragonPhase::from(self::string($data, 'phase')),
                EnderDragonRespawnStage::from(self::string($data, 'respawn_stage')),
                self::int($data, 'respawn_ticks'),
                isset($data['dragon_uuid']) ? self::string($data, 'dragon_uuid') : null,
                self::bool($data, 'dragon_killed') ? EndVictoryStage::PUBLISHED : EndVictoryStage::NONE,
                null,
                [],
                0,
                self::int($data, 'revision'),
            );
        }
        $ritualCrystalUuids = $data['ritual_crystal_uuids'] ?? null;
        if (!is_array($ritualCrystalUuids) || !array_is_list($ritualCrystalUuids)) {
            throw new InvalidArgumentException('End encounter ritual crystal identities are malformed.');
        }
        return new self(
            self::bool($data, 'initialized'),
            self::bool($data, 'dragon_killed'),
            self::bool($data, 'previously_killed'),
            self::bool($data, 'egg_placed'),
            self::bool($data, 'exit_portal_active'),
            self::int($data, 'gateway_count'),
            EnderDragonPhase::from(self::string($data, 'phase')),
            EnderDragonRespawnStage::from(self::string($data, 'respawn_stage')),
            self::int($data, 'respawn_ticks'),
            isset($data['dragon_uuid']) ? self::string($data, 'dragon_uuid') : null,
            EndVictoryStage::from(self::string($data, 'victory_stage')),
            isset($data['confirmed_death_uuid']) ? self::string($data, 'confirmed_death_uuid') : null,
            array_map(static fn(mixed $value): string => is_string($value) ? $value : throw new InvalidArgumentException('End encounter ritual crystal identity is malformed.'), $ritualCrystalUuids),
            self::int($data, 'respawn_work_index'),
            self::int($data, 'revision'),
        );
    }

    /** @param array<string, mixed> $data */
    private static function bool(array $data, string $key): bool
    {
        return is_bool($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("End encounter field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function int(array $data, string $key): int
    {
        return is_int($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("End encounter field '$key' is malformed.");
    }

    /** @param array<string, mixed> $data */
    private static function string(array $data, string $key): string
    {
        return is_string($data[$key] ?? null) ? $data[$key] : throw new InvalidArgumentException("End encounter field '$key' is malformed.");
    }
}
